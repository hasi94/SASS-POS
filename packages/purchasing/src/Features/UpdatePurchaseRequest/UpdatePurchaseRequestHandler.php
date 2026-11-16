<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdatePurchaseRequest;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\DateInput;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\Places;
use Flexipos\Purchasing\Documents\RequestImage;
use Flexipos\Purchasing\Documents\RequestLines;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\BusinessClock;
use Flexipos\Purchasing\Support\Fields;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Edit the header of a draft request (T-034 AC-20, AC-21, AC-25, AC-39).
 *
 * **Only a draft** (`locked` otherwise, decided under the header lock). The
 * branch is the request's own and never changes, so there is no `branch_id`
 * field; the permission is asked at the **stored** branch (AC-85 d). A field left
 * out keeps its value; a value equal to the stored one is no change, and a call
 * that changes nothing writes nothing, not even `updated_at`.
 *
 * A new `location_id` must be an eligible delivery location of the request's
 * branch (D-40). A new `required_date` must be a real date not earlier than the
 * branch-local business date; **a date that is left as it is is not judged again**,
 * so a request whose required date has since passed can still have its notes
 * edited.
 */
final class UpdatePurchaseRequestHandler
{
    private const FIELDS = ['request_id', 'location_id', 'required_date', 'notes'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly BusinessClock $business,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly Places $places,
    ) {}

    public function handle(UpdatePurchaseRequest $command): UpdatePurchaseRequestResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $requestId = Identifier::fold($fields['request_id'] ?? null, 'request_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_requests', $requestId, 'purchase request');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::REQUESTS_MANAGE, $branchId);

        $submitted = [];

        if (array_key_exists('location_id', $fields)) {
            $submitted['location_id'] = Identifier::fold($fields['location_id'], 'location_id');
        }

        if (array_key_exists('required_date', $fields)) {
            $submitted['required_date'] = DateInput::optional($fields['required_date'], 'required_date');
        }

        if (array_key_exists('notes', $fields)) {
            $submitted['notes'] = Fields::notes($fields, 'notes');
        }

        $stored = RequestImage::header($header);

        if (isset($submitted['location_id']) && $submitted['location_id'] !== $stored['location_id']) {
            $this->places->location($connection, $submitted['location_id'], $branchId);
        }

        if (isset($submitted['required_date']) && $submitted['required_date'] !== $stored['required_date']) {
            $today = $this->business->businessDate($this->places->branch($connection, $branchId)->timezone);

            if ($submitted['required_date'] < $today) {
                throw PurchasingRefused::validation('The required_date is earlier than today.');
            }
        }

        return $connection->transaction(function () use ($connection, $requestId, $submitted, $actorId, $branchId): UpdatePurchaseRequestResult {
            $locked = DocumentLocks::request($connection, $requestId);

            RequestLines::assertDraft($locked);

            $before = RequestImage::header($locked);
            $changes = [];

            foreach ($submitted as $column => $value) {
                if ($before[$column] !== $value) {
                    $changes[$column] = $value;
                }
            }

            if ($changes === []) {
                return new UpdatePurchaseRequestResult($requestId, $before, []);
            }

            $connection->table('purchase_requests')->where('id', $requestId)->update(array_merge($changes, [
                'updated_at' => $this->clock->timestamp(),
                'updated_by' => $actorId,
            ]));

            $after = RequestImage::header($connection->table('purchase_requests')->where('id', $requestId)->first()
                ?? throw PurchasingRefused::notFound("No purchase request has id [{$requestId}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::REQUEST_UPDATED,
                'purchase_request',
                $requestId,
                $before,
                $after,
                $branchId,
            );

            return new UpdatePurchaseRequestResult($requestId, $after, array_keys($changes));
        });
    }
}
