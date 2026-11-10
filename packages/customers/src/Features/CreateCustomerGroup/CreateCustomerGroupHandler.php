<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\CreateCustomerGroup;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Fields;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a customer group (T-028 plan Ruling 7, AC-1, AC-2, AC-5).
 *
 * **One default group** (AC-2): a newly created `is_default = true` row
 * clears the flag on every other group first, in the same transaction.
 * `default_marker`'s unique index is the backstop for a concurrent writer.
 *
 * The duplicate-code pre-check compares `lower()`, the unique index is the
 * backstop (T-027 Ruling 12).
 */
final class CreateCustomerGroupHandler
{
    public const ACTION = 'customer_group_created';

    public const FIELDS = [
        'code', 'name', 'discount_percent', 'credit_allowed', 'default_credit_limit',
        'default_payment_terms_days', 'is_default', 'loyalty_multiplier',
    ];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateCustomerGroup $command): CreateCustomerGroupResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $code = Fields::requiredText($fields, 'code', 50);
        $name = Fields::requiredText($fields, 'name', 150);
        $discountPercent = Fields::decimal($fields, 'discount_percent', 4, false, null);
        $creditAllowed = Fields::flag($fields, 'credit_allowed', false);
        $defaultCreditLimit = Fields::decimal($fields, 'default_credit_limit', 4, false, null);
        $defaultPaymentTermsDays = Fields::unsignedSmallInt($fields, 'default_payment_terms_days', null);
        $isDefault = Fields::flag($fields, 'is_default', false);
        $loyaltyMultiplier = Fields::decimal($fields, 'loyalty_multiplier', 4, false, null);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use (
            $connection, $command, $code, $name, $discountPercent, $creditAllowed,
            $defaultCreditLimit, $defaultPaymentTermsDays, $isDefault, $loyaltyMultiplier,
        ): CreateCustomerGroupResult {
            if ($connection->table('customer_groups')->whereRaw('lower(code) = ?', [strtolower($code)])->exists()) {
                throw CustomersRefused::duplicate("The code [{$code}] is already in use.");
            }

            if ($isDefault) {
                $connection->table('customer_groups')->where('is_default', true)->update(['is_default' => false]);
            }

            $now = $this->clock->timestamp();
            $groupId = (string) Str::ulid();

            try {
                $connection->table('customer_groups')->insert([
                    'id' => $groupId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                    'code' => $code,
                    'name' => $name,
                    'discount_percent' => $discountPercent,
                    'credit_allowed' => $creditAllowed,
                    'default_credit_limit' => $defaultCreditLimit,
                    'default_payment_terms_days' => $defaultPaymentTermsDays,
                    'is_default' => $isDefault,
                    'loyalty_multiplier' => $loyaltyMultiplier,
                    'version' => 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CustomersRefused::duplicate("The code [{$code}] is already in use.");
            }

            $row = $connection->table('customer_groups')->where('id', $groupId)->first();

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'customer_group',
                $groupId,
                null,
                $row === null ? null : (array) $row,
            );

            return new CreateCustomerGroupResult($groupId);
        });
    }
}
