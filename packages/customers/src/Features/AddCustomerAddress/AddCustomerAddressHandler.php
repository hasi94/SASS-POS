<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\AddCustomerAddress;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Fields;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\Lookup;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;

/**
 * Add a customer address (T-028 plan Ruling 8, AC-16, AC-17).
 *
 * **One default per `(customer_id, type)`** (AC-17): setting `is_default`
 * clears the previous default of the same type for this customer first, in
 * the same transaction. `default_customer_id`'s unique index is the backstop.
 */
final class AddCustomerAddressHandler
{
    public const ACTION = 'customer_address_added';

    public const TYPES = ['billing', 'delivery', 'other'];

    public const FIELDS = ['customer_id', 'type', 'label', 'address', 'is_default', 'geo'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(AddCustomerAddress $command): AddCustomerAddressResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $customerId = Identifier::fold(Fields::requiredText($fields, 'customer_id', 26), 'customer_id');
        $type = Fields::enum($fields, 'type', self::TYPES, null);

        if ($type === null) {
            throw CustomersRefused::validation('The type is required and must be one of: '.implode(', ', self::TYPES).'.');
        }

        $label = Fields::optionalText($fields, 'label', 100);
        $address = Fields::json($fields, 'address');
        $isDefault = Fields::flag($fields, 'is_default', false);
        $geo = Fields::json($fields, 'geo');

        $connection = $this->tenant->get();

        return $connection->transaction(function () use (
            $connection, $command, $customerId, $type, $label, $address, $isDefault, $geo,
        ): AddCustomerAddressResult {
            Lookup::live($connection, 'customers', $customerId, 'customer');

            if ($isDefault) {
                $connection->table('customer_addresses')
                    ->where('customer_id', $customerId)
                    ->where('type', $type)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $now = $this->clock->timestamp();
            $addressId = (string) Str::ulid();

            $connection->table('customer_addresses')->insert([
                'id' => $addressId,
                'created_at' => $now,
                'updated_at' => $now,
                'customer_id' => $customerId,
                'type' => $type,
                'label' => $label,
                'address' => $address,
                'is_default' => $isDefault,
                'geo' => $geo,
            ]);

            $row = $connection->table('customer_addresses')->where('id', $addressId)->first();

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'customer_address',
                $addressId,
                null,
                $row === null ? null : (array) $row,
            );

            return new AddCustomerAddressResult($addressId);
        });
    }
}
