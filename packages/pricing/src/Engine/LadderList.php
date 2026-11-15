<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Flexipos\Contracts\Pricing\Shape;

/**
 * One candidate price list as the pure ladder sees it: the `price_lists` row,
 * its assignments and **the product's** items (AC-15).
 *
 * `startsAt`/`endsAt` are UTC instants (`YYYY-MM-DDTHH:MM:SS.mmmZ`), a null
 * bound being open. `isDeleted` mirrors `deleted_at`: the adapter may pass such
 * a row and the ladder skips it, so a fixture can prove the skip.
 */
final readonly class LadderList
{
    public string $currency;

    public ?string $startsAt;

    public ?string $endsAt;

    /**
     * @param  list<LadderAssignment>  $assignments
     * @param  list<LadderItem>  $items
     */
    public function __construct(
        public string $listId,
        public string $code,
        public ListType $type,
        string $currency,
        public int $priority,
        ?string $startsAt,
        ?string $endsAt,
        public bool $isActive,
        public bool $isDeleted,
        public array $assignments,
        public array $items,
    ) {
        $this->currency = Shape::currency($currency, 'currency');
        $this->startsAt = $startsAt === null ? null : Shape::instant($startsAt, 'startsAt');
        $this->endsAt = $endsAt === null ? null : Shape::instant($endsAt, 'endsAt');
    }
}
