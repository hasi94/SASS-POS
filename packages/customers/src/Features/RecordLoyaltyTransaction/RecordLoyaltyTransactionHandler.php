<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\RecordLoyaltyTransaction;

use Flexipos\Contracts\Customers\LoyaltyLedgerRefused;
use Flexipos\Contracts\Customers\LoyaltyPosting;
use Flexipos\Contracts\Customers\LoyaltyTransactionType;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Loyalty\LoyaltyCalculator;
use Flexipos\Customers\Loyalty\LoyaltyWriter;
use Flexipos\Customers\Support\Fields;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\TenantConnection;

/**
 * Record one `loyalty_transactions` row (T-028 plan Ruling 6, AC-27 to
 * AC-33).
 *
 * The shape of the submitted fields is checked here; amounts and signs are
 * {@see LoyaltyCalculator}'s own job. The optional `id` is this task's own
 * replay key ahead of FP-233's `sale_id` (F-15).
 */
final class RecordLoyaltyTransactionHandler
{
    public const FIELDS = ['id', 'customer_id', 'program_id', 'type', 'points', 'value', 'expires_at', 'employee_id', 'notes'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly LoyaltyWriter $writer,
    ) {}

    /**
     * @throws LoyaltyLedgerRefused
     */
    public function handle(RecordLoyaltyTransaction $command): LoyaltyPosting
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $id = Identifier::foldNullable(Fields::has($fields, 'id') ? $fields['id'] : null, 'id');
        $customerId = Identifier::fold(Fields::requiredText($fields, 'customer_id', 26), 'customer_id');
        $programId = Identifier::fold(Fields::requiredText($fields, 'program_id', 26), 'program_id');
        $type = self::type($fields);
        $points = $fields['points'] ?? null;

        if (! is_int($points) && ! is_string($points)) {
            throw CustomersRefused::validation('The points must be an int or a string.');
        }

        $value = Fields::has($fields, 'value') ? $fields['value'] : null;

        if ($value !== null && ! is_int($value) && ! is_string($value)) {
            throw CustomersRefused::validation('The value must be an int, a string or null.');
        }

        $expiresAt = Fields::datetime($fields, 'expires_at', 29);
        $employeeId = Identifier::foldNullable(Fields::has($fields, 'employee_id') ? $fields['employee_id'] : null, 'employee_id');
        $notes = Fields::optionalText($fields, 'notes', 65535);

        return $this->writer->post(
            $this->tenant->get(),
            $id,
            $customerId,
            $programId,
            $type,
            $points,
            $value,
            $expiresAt,
            $employeeId,
            $notes,
            $command->actorId,
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function type(array $fields): LoyaltyTransactionType
    {
        $value = $fields['type'] ?? null;

        if (! is_string($value) || LoyaltyTransactionType::tryFrom($value) === null) {
            throw CustomersRefused::validation('The type must be one of: '.implode(', ', array_map(static fn (LoyaltyTransactionType $case): string => $case->value, LoyaltyTransactionType::cases())).'.');
        }

        return LoyaltyTransactionType::from($value);
    }
}
