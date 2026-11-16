<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CreatePurchaseRequest;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Contracts\Organization\DocumentNumbersRefused;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\DateInput;
use Flexipos\Purchasing\Documents\Places;
use Flexipos\Purchasing\Documents\RequestImage;
use Flexipos\Purchasing\Documents\RequestStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\BusinessClock;
use Flexipos\Purchasing\Support\Fields;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\TenantConnection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Raise a draft purchase request (T-034 AC-19 to AC-21, AC-36, AC-39, AC-90;
 * Rulings 3, 5, 7).
 *
 * **What is the server's.** The requester is the actor, never a field; the number
 * comes from `DocumentNumbers::next('purchase_request', branch)`; the status is
 * `draft`. A supplied `requested_by`, `number`, `status` or date raised is an
 * unknown field, refused `validation` (D-19).
 *
 * **Order of work (Ruling 7).** Everything that can refuse runs before the
 * transaction on plain reads: the branch is active (`unknown_branch`), the
 * location is eligible (`unknown_location`, D-40), the `required_date` is a real
 * date not earlier than the branch-local business date, the actor holds
 * `purchasing.requests.manage` **at that branch** (AC-21). The transaction then
 * takes the number **last** and inserts the row, so a refusal consumes no number
 * (AC-20, AC-90), and it runs as an outermost `transaction(..., 2)`: a deadlock
 * on the first use of a sequence is retried once from the top, never inside a
 * savepoint (plan 0.1 fact 3).
 *
 * **Replay (Ruling 5).** The id is the caller's ULID. An id that already exists
 * is answered, not written: identical input is returned with `replayed = true`
 * (no number, audit row or event), any difference is `replay_conflict` naming the
 * id. A concurrent duplicate loses on the primary key, rolls back, and is then
 * answered the same way; a unique violation on the *number* is `numbering_refused`.
 */
final class CreatePurchaseRequestHandler
{
    private const FIELDS = ['id', 'branch_id', 'location_id', 'required_date', 'notes'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly BusinessClock $business,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly DocumentNumbers $numbers,
        private readonly Places $places,
    ) {}

    public function handle(CreatePurchaseRequest $command): CreatePurchaseRequestResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $id = Identifier::fold($fields['id'] ?? null, 'id');
        $branchId = Identifier::fold($fields['branch_id'] ?? null, 'branch_id');
        $locationId = Identifier::fold($fields['location_id'] ?? null, 'location_id');
        $requiredDate = DateInput::optional($fields['required_date'] ?? null, 'required_date');
        $notes = Fields::notes($fields, 'notes');

        $connection = $this->tenant->get();

        $branch = $this->places->branch($connection, $branchId);
        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::REQUESTS_MANAGE, $branchId);

        $input = [
            'branch_id' => $branchId,
            'location_id' => $locationId,
            'required_date' => $requiredDate,
            'notes' => $notes,
            'requested_by' => $actorId,
        ];

        // A replay is answered before the date and the location are judged again: a
        // request raised yesterday for today is still the same request tomorrow.
        $existing = $connection->table('purchase_requests')->where('id', $id)->first();

        if ($existing !== null) {
            return $this->replay($id, $existing, $input);
        }

        $this->places->location($connection, $locationId, $branchId);

        if ($requiredDate !== null && $requiredDate < $this->business->businessDate($branch->timezone)) {
            throw PurchasingRefused::validation('The required_date is earlier than today.');
        }

        try {
            return $connection->transaction(function () use ($connection, $id, $input, $actorId): CreatePurchaseRequestResult {
                // The number is taken last (Ruling 3, Ruling 7 row 4): nothing above this
                // line has anything to lock on a create.
                try {
                    $number = $this->numbers->next('purchase_request', $input['branch_id']);
                } catch (DocumentNumbersRefused $refused) {
                    throw PurchasingRefused::fromNumbers($refused);
                }

                $now = $this->clock->timestamp();

                $connection->table('purchase_requests')->insert([
                    'id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                    'number' => $number,
                    'branch_id' => $input['branch_id'],
                    'location_id' => $input['location_id'],
                    'requested_by' => $actorId,
                    'required_date' => $input['required_date'],
                    'status' => RequestStatus::Draft->value,
                    'workflow_instance_id' => null,
                    'notes' => $input['notes'],
                ]);

                $image = RequestImage::header($connection->table('purchase_requests')->where('id', $id)->first()
                    ?? throw PurchasingRefused::notFound("No purchase request has id [{$id}]."));

                $this->audit->record(
                    PurchasingAuditActions::MODULE,
                    $actorId,
                    PurchasingAuditActions::REQUEST_CREATED,
                    'purchase_request',
                    $id,
                    null,
                    $image,
                    $input['branch_id'],
                );

                return new CreatePurchaseRequestResult($id, $number, false, $image);
            }, 2);
        } catch (UniqueConstraintViolationException $violation) {
            // Rolled back, number included. Either a concurrent create took this id (answer
            // it as a replay), or the number collided (numbering_refused, Ruling 3).
            $winner = $connection->table('purchase_requests')->where('id', $id)->first();

            if ($winner !== null) {
                return $this->replay($id, $winner, $input);
            }

            throw PurchasingRefused::because(
                PurchasingRefusal::NumberingRefused,
                'The document number collided with an existing request.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws PurchasingRefused replay_conflict
     */
    private function replay(string $id, object $existing, array $input): CreatePurchaseRequestResult
    {
        $stored = RequestImage::header($existing);

        foreach ($input as $column => $value) {
            if ($stored[$column] !== $value) {
                throw PurchasingRefused::because(
                    PurchasingRefusal::ReplayConflict,
                    "Purchase request [{$id}] already exists with a different [{$column}].",
                );
            }
        }

        return new CreatePurchaseRequestResult($id, $stored['number'], true, $stored);
    }
}
