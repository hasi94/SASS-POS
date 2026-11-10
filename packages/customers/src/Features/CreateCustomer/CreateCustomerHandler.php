<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\CreateCustomer;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Customers\CreditEntryType;
use Flexipos\Customers\Customers\CustomerRules;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Ledger\CreditLedgerWriter;
use Flexipos\Customers\Ledger\MaintainedColumns;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Fields;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\Lookup;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a customer (T-028 plan Ruling 7, AC-6, AC-7, AC-8, AC-9, AC-11,
 * AC-12, AC-6a; the opening entry is Ruling 6/AC-7a, step 11).
 *
 * **`credit_allowed`, `credit_limit` and `payment_terms_days` are copied
 * from the group when not explicitly supplied** (D-27, F-10): a later
 * change to the group leaves this customer unchanged, because the values
 * are copied once, not referenced.
 *
 * **A non-zero `opening_balance` posts one `opening` entry through
 * {@see CreditLedgerWriter}**, in this same transaction, after the row
 * exists (F-11/D-28). A currency is then required - no currency means
 * nothing is written at all, including this customer row, because the
 * writer's refusal rolls back the whole transaction.
 */
final class CreateCustomerHandler
{
    public const ACTION = 'customer_created';

    public const FIELDS = [
        'group_id', 'code', 'name', 'phone', 'email', 'address', 'dob', 'gender', 'tax_no',
        'company_name', 'currency', 'credit_allowed', 'credit_limit', 'payment_terms_days',
        'status', 'blocked_reason', 'possible_duplicate_of', 'opening_balance', 'notes',
    ];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly CreditLedgerWriter $writer,
    ) {}

    public function handle(CreateCustomer $command): CreateCustomerResult
    {
        $fields = $command->fields;

        MaintainedColumns::refuse($fields, MaintainedColumns::CREATE);
        Fields::only($fields, self::FIELDS);

        $name = Fields::requiredText($fields, 'name', 150);
        $code = Fields::optionalText($fields, 'code', 50);
        $phone = Fields::optionalText($fields, 'phone', 30);
        $email = Fields::optionalText($fields, 'email', 191);
        $address = Fields::json($fields, 'address');
        $dob = Fields::date($fields, 'dob', null);
        $gender = Fields::optionalText($fields, 'gender', 30);
        $taxNo = Fields::optionalText($fields, 'tax_no', 50);
        $companyName = Fields::optionalText($fields, 'company_name', 150);
        $currency = self::currency($fields);
        $groupId = Identifier::foldNullable(Fields::has($fields, 'group_id') ? $fields['group_id'] : null, 'group_id');
        $blockedReason = Fields::optionalText($fields, 'blocked_reason', 255);
        $status = CustomerRules::status($fields['status'] ?? 'active');
        $possibleDuplicateOf = Identifier::foldNullable(Fields::has($fields, 'possible_duplicate_of') ? $fields['possible_duplicate_of'] : null, 'possible_duplicate_of');
        $openingBalance = Fields::decimal($fields, 'opening_balance', 4, true, '0');
        $notes = Fields::optionalText($fields, 'notes', 65535);

        if (Fields::has($fields, 'credit_limit') && $fields['credit_limit'] === null) {
            throw CustomersRefused::validation('The credit_limit may not be null.');
        }

        CustomerRules::checkStatusConsistency($status, $blockedReason);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use (
            $connection, $command, $name, $code, $phone, $email, $address, $dob, $gender, $taxNo,
            $companyName, $currency, $groupId, $blockedReason, $status, $possibleDuplicateOf,
            $openingBalance, $notes, $fields,
        ): CreateCustomerResult {
            $group = $groupId === null ? null : Lookup::live($connection, 'customer_groups', $groupId, 'group');

            $creditAllowed = Fields::has($fields, 'credit_allowed')
                ? Fields::flag($fields, 'credit_allowed', false)
                : ($group !== null && (bool) $group->credit_allowed);

            $creditLimit = Fields::has($fields, 'credit_limit')
                ? Fields::decimal($fields, 'credit_limit', 4, false, '0')
                : ($group !== null && $group->default_credit_limit !== null ? (string) $group->default_credit_limit : '0');

            $paymentTermsDays = Fields::has($fields, 'payment_terms_days')
                ? Fields::unsignedSmallInt($fields, 'payment_terms_days', null)
                : ($group !== null ? ($group->default_payment_terms_days === null ? null : (int) $group->default_payment_terms_days) : null);

            if ($possibleDuplicateOf !== null) {
                Lookup::any($connection, 'customers', $possibleDuplicateOf, 'possible_duplicate_of');
            }

            if ($code !== null && $connection->table('customers')->whereRaw('lower(code) = ?', [strtolower($code)])->exists()) {
                throw CustomersRefused::duplicate("The code [{$code}] is already in use.");
            }

            $now = $this->clock->timestamp();
            $customerId = (string) Str::ulid();

            try {
                $connection->table('customers')->insert([
                    'id' => $customerId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                    'created_by' => null,
                    'updated_by' => null,
                    'group_id' => $groupId,
                    'code' => $code,
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email,
                    'address' => $address,
                    'dob' => $dob,
                    'gender' => $gender,
                    'tax_no' => $taxNo,
                    'company_name' => $companyName,
                    'currency' => $currency,
                    'credit_allowed' => $creditAllowed,
                    'credit_limit' => $creditLimit,
                    'credit_balance' => '0.0000',
                    'payment_terms_days' => $paymentTermsDays,
                    'loyalty_points' => '0.0000',
                    'loyalty_tier' => null,
                    'opening_balance' => $openingBalance,
                    'status' => $status->value,
                    'blocked_reason' => $blockedReason,
                    'possible_duplicate_of' => $possibleDuplicateOf,
                    'merged_into_id' => null,
                    'first_sale_at' => null,
                    'last_sale_at' => null,
                    'lifetime_value' => '0.0000',
                    'notes' => $notes,
                    'version' => 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CustomersRefused::duplicate("The code [{$code}] is already in use.");
            }

            if ((float) $openingBalance !== 0.0) {
                $isCredit = (float) $openingBalance < 0;

                $this->writer->post(
                    $connection,
                    $customerId,
                    CreditEntryType::Opening,
                    'customer',
                    $customerId,
                    $isCredit ? 0 : self::abs($openingBalance),
                    $isCredit ? self::abs($openingBalance) : 0,
                    $currency,
                    null,
                    null,
                    null,
                    $command->actorId,
                );
            }

            $row = $connection->table('customers')->where('id', $customerId)->first();

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'customer',
                $customerId,
                null,
                $row === null ? null : (array) $row,
            );

            return new CreateCustomerResult($customerId);
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws CustomersRefused validation
     */
    private static function currency(array $fields): ?string
    {
        if (! Fields::has($fields, 'currency') || $fields['currency'] === null) {
            return null;
        }

        $value = $fields['currency'];

        if (! is_string($value) || preg_match('/\A[A-Za-z]{3}\z/', $value) !== 1) {
            throw CustomersRefused::validation('The currency must be three letters.');
        }

        return strtoupper($value);
    }

    private static function abs(string $decimal): string
    {
        return ltrim($decimal, '-');
    }
}
