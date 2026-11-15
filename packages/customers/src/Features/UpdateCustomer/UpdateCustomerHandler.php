<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\UpdateCustomer;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Customers\Customers\CustomerRules;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Ledger\MaintainedColumns;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Fields;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\Lookup;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Update a customer (T-028 plan Ruling 7, AC-4, AC-6a, AC-8, AC-9, AC-12).
 *
 * **The maintained columns are refused whole, before any read** (AC-8,
 * D-8, D-29): `credit_balance`, `loyalty_points`, `loyalty_tier`,
 * `merged_into_id` and `opening_balance` (F-11) may never be set here.
 *
 * **A currency change is refused once any ledger row exists** (AC-6a,
 * D-25).
 *
 * **`status`/`blocked_reason` consistency is checked against the merged
 * state** (AC-9) - the stored row's own value, overridden by whichever of
 * the two the patch names.
 */
final class UpdateCustomerHandler
{
    public const ACTION = 'customer_updated';

    public const FIELDS = [
        'version', 'group_id', 'code', 'name', 'phone', 'email', 'address', 'dob', 'gender', 'tax_no',
        'company_name', 'currency', 'credit_allowed', 'credit_limit', 'payment_terms_days',
        'status', 'blocked_reason', 'possible_duplicate_of', 'notes',
    ];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateCustomer $command): void
    {
        $fields = $command->fields;

        MaintainedColumns::refuse($fields, MaintainedColumns::UPDATE);
        Fields::only($fields, self::FIELDS);

        $version = Fields::requiredVersion($fields);
        $customerId = Identifier::fold($command->customerId, 'customerId');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $customerId, $fields, $version): void {
            $before = $connection->table('customers')->where('id', $customerId)->whereNull('deleted_at')->lockForUpdate()->first();

            if ($before === null) {
                throw CustomersRefused::notFound("The customer [{$customerId}] does not exist.");
            }

            if ((int) $before->version !== $version) {
                throw CustomersRefused::stale("The customer [{$customerId}] is at version [{$before->version}], not [{$version}].");
            }

            $changes = [];

            if (Fields::has($fields, 'group_id')) {
                $groupId = Identifier::foldNullable($fields['group_id'], 'group_id');

                if ($groupId !== null) {
                    Lookup::live($connection, 'customer_groups', $groupId, 'group', lock: true);
                }

                $changes['group_id'] = $groupId;
            }

            if (Fields::has($fields, 'code')) {
                $changes['code'] = Fields::optionalText($fields, 'code', 50);
            }

            if (Fields::has($fields, 'name')) {
                $changes['name'] = Fields::requiredText($fields, 'name', 150);
            }

            if (Fields::has($fields, 'phone')) {
                $changes['phone'] = Fields::optionalText($fields, 'phone', 30);
            }

            if (Fields::has($fields, 'email')) {
                $changes['email'] = Fields::optionalText($fields, 'email', 191);
            }

            if (Fields::has($fields, 'address')) {
                $changes['address'] = Fields::json($fields, 'address');
            }

            if (Fields::has($fields, 'dob')) {
                $changes['dob'] = Fields::date($fields, 'dob', null);
            }

            if (Fields::has($fields, 'gender')) {
                $changes['gender'] = Fields::optionalText($fields, 'gender', 30);
            }

            if (Fields::has($fields, 'tax_no')) {
                $changes['tax_no'] = Fields::optionalText($fields, 'tax_no', 50);
            }

            if (Fields::has($fields, 'company_name')) {
                $changes['company_name'] = Fields::optionalText($fields, 'company_name', 150);
            }

            if (Fields::has($fields, 'currency')) {
                $newCurrency = self::currency($fields);

                if ($newCurrency !== $before->currency) {
                    $hasLedgerRows = $connection->table('customer_ledger')->where('customer_id', $customerId)->exists();

                    if ($hasLedgerRows) {
                        throw CustomersRefused::validation("Customer [{$customerId}]'s currency cannot be changed once a ledger row exists.");
                    }
                }

                $changes['currency'] = $newCurrency;
            }

            if (Fields::has($fields, 'credit_allowed')) {
                $changes['credit_allowed'] = Fields::flag($fields, 'credit_allowed', false);
            }

            if (Fields::has($fields, 'credit_limit')) {
                if ($fields['credit_limit'] === null) {
                    throw CustomersRefused::validation('The credit_limit may not be null.');
                }

                $changes['credit_limit'] = Fields::decimal($fields, 'credit_limit', 4, false, '0', 14);
            }

            if (Fields::has($fields, 'payment_terms_days')) {
                $changes['payment_terms_days'] = Fields::unsignedSmallInt($fields, 'payment_terms_days', null);
            }

            if (Fields::has($fields, 'notes')) {
                $changes['notes'] = Fields::optionalText($fields, 'notes', 65535);
            }

            if (Fields::has($fields, 'status') || Fields::has($fields, 'blocked_reason')) {
                // Only parse `before->status` through the enum when the
                // patch actually touches status/blocked_reason - a future
                // out-of-enum stored value (e.g. from Sync) would otherwise
                // fail every update to that row, not just one touching
                // status.
                $newStatus = Fields::has($fields, 'status') ? CustomerRules::status($fields['status']) : CustomerRules::status($before->status);
                $newBlockedReason = Fields::has($fields, 'blocked_reason') ? Fields::optionalText($fields, 'blocked_reason', 255) : $before->blocked_reason;

                CustomerRules::checkStatusConsistency($newStatus, $newBlockedReason);
                $changes['status'] = $newStatus->value;
                $changes['blocked_reason'] = $newBlockedReason;
            }

            if (Fields::has($fields, 'possible_duplicate_of')) {
                $possibleDuplicateOf = Identifier::foldNullable($fields['possible_duplicate_of'], 'possible_duplicate_of');

                if ($possibleDuplicateOf === $customerId) {
                    throw CustomersRefused::validation("Customer [{$customerId}] may not be marked a possible duplicate of itself.");
                }

                if ($possibleDuplicateOf !== null) {
                    Lookup::any($connection, 'customers', $possibleDuplicateOf, 'possible_duplicate_of');
                }

                $changes['possible_duplicate_of'] = $possibleDuplicateOf;
            }

            if (isset($changes['code']) && $changes['code'] !== $before->code) {
                $duplicate = $connection->table('customers')
                    ->whereRaw('lower(code) = ?', [strtolower($changes['code'])])
                    ->where('id', '!=', $customerId)
                    ->exists();

                if ($duplicate) {
                    throw CustomersRefused::duplicate("The code [{$changes['code']}] is already in use.");
                }
            }

            $changes['version'] = $version + 1;
            $changes['updated_at'] = $this->clock->timestamp();

            try {
                $connection->table('customers')->where('id', $customerId)->update($changes);
            } catch (UniqueConstraintViolationException) {
                throw CustomersRefused::duplicate('The code is already in use.');
            }

            $after = $connection->table('customers')->where('id', $customerId)->first();

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'customer',
                $customerId,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws CustomersRefused validation
     */
    private static function currency(array $fields): ?string
    {
        $value = $fields['currency'];

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/\A[A-Za-z]{3}\z/', $value) !== 1) {
            throw CustomersRefused::validation('The currency must be three letters.');
        }

        return strtoupper($value);
    }
}
