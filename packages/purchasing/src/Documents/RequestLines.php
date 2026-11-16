<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Catalog\UnitResolver;
use Flexipos\Contracts\Catalog\UnitSnapshot;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Identifier;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;

/**
 * What the three request-line slices share (T-034 AC-22 to AC-25, D-14).
 *
 * Nothing here opens a transaction or takes a lock: the slice owns both, in the
 * order of Ruling 7 (the header first), and calls these with the header already
 * held.
 *
 * **The unit quad is the resolver's** ({@see self::snapshot()}): the stored
 * `unit_id`, `quantity`, `unit_factor` and `base_quantity` are exactly what
 * `UnitResolver::snapshot()` returned and are never recomputed on read (AC-24).
 */
final class RequestLines
{
    public function __construct(
        private readonly UnitResolver $units,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * A refusal, not a result, when the unit cannot be resolved: any resolver
     * refusal is `unit_unresolvable`, with the resolver's reason as the cause.
     *
     * @throws PurchasingRefused unit_unresolvable
     */
    public function snapshot(string $productId, string $unitId, string $quantity): UnitSnapshot
    {
        try {
            return $this->units->snapshot($productId, $unitId, $quantity);
        } catch (UnitResolutionRefused $refused) {
            throw PurchasingRefused::fromUnit($refused);
        }
    }

    /**
     * Only a draft accepts a header or a line change (AC-25).
     *
     * @throws PurchasingRefused locked
     */
    public static function assertDraft(object $header): void
    {
        if (! RequestStatus::from((string) $header->status)->isEditable()) {
            throw PurchasingRefused::because(
                PurchasingRefusal::Locked,
                "Purchase request [{$header->number}] is [{$header->status}] and can no longer be edited.",
            );
        }
    }

    /**
     * Room for one more line (AC-25: the 201st is refused).
     *
     * @throws PurchasingRefused too_many_lines
     */
    public function assertRoom(Connection $connection, string $requestId): void
    {
        $max = (int) $this->config->get('purchasing.max_lines', 200);

        if ($connection->table('purchase_request_items')->where('request_id', $requestId)->count() >= $max) {
            throw PurchasingRefused::because(PurchasingRefusal::TooManyLines, "A purchase request holds at most {$max} lines.");
        }
    }

    /**
     * The next line number: one above the highest, so a removed line leaves a
     * gap and a number is never reused within a request.
     */
    public static function nextLineNo(Connection $connection, string $requestId): int
    {
        return ((int) $connection->table('purchase_request_items')->where('request_id', $requestId)->max('line_no')) + 1;
    }

    /**
     * One line of this request, optionally `FOR UPDATE`; a line of another
     * request is not found through this one.
     *
     * @throws PurchasingRefused validation (malformed id), not_found
     */
    public static function line(Connection $connection, string $requestId, mixed $lineId, bool $lock = false): object
    {
        $id = Identifier::fold($lineId, 'line_id');

        $query = $connection->table('purchase_request_items')->where('id', $id)->where('request_id', $requestId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $line = $query->first();

        if ($line === null) {
            throw PurchasingRefused::notFound("Purchase request [{$requestId}] has no line [{$id}].");
        }

        return $line;
    }

    /**
     * Move the header's `updated_at` and `updated_by`: a line change is a change
     * to the document.
     */
    public static function touch(Connection $connection, string $requestId, string $actorId, string $now): void
    {
        $connection->table('purchase_requests')->where('id', $requestId)->update([
            'updated_at' => $now,
            'updated_by' => $actorId,
        ]);
    }
}
