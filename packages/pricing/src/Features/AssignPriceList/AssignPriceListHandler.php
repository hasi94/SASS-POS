<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\AssignPriceList;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Pricing\Engine\ScopeType;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\Identifier;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\TenantConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Assign a price list to a branch, a customer group or a customer (T-029 AC-14).
 *
 * `scope_id` carries no foreign key, because it names a row in one of three
 * tables depending on `scope_type` (the documented polymorphic exception,
 * `docs/database.md` section 3.3). So the existence of the target is checked
 * here, in all three cases: an assignment to a missing or soft-deleted target
 * would be silent dead data.
 */
final class AssignPriceListHandler
{
    public const ACTION = 'pricing_price_list_assigned';

    public const FIELDS = ['price_list_id', 'scope_type', 'scope_id', 'priority'];

    /** The table each scope type names. */
    private const TARGET_TABLES = [
        'branch' => 'branches',
        'customer_group' => 'customer_groups',
        'customer' => 'customers',
    ];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(AssignPriceList $command): AssignPriceListResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $scopeType = Fields::enum($fields, 'scope_type', array_column(ScopeType::cases(), 'value'), null);

        if ($scopeType === null) {
            throw PricingAdminRefused::validation('The scope_type is required.');
        }

        $scopeId = Identifier::fold($fields['scope_id'] ?? null, 'scope_id');
        $priority = Fields::integer($fields, 'priority', 0, -32768, 32767);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $fields, $scopeType, $scopeId, $priority): AssignPriceListResult {
            $list = Lookup::live($connection, 'price_lists', $fields['price_list_id'] ?? null, 'price list', lock: true);
            $listId = (string) $list->id;

            $exists = $connection->table(self::TARGET_TABLES[$scopeType])->where('id', $scopeId)->whereNull('deleted_at')->exists();

            if (! $exists) {
                throw PricingAdminRefused::notFound("No live {$scopeType} has scope_id [{$scopeId}].");
            }

            if ($connection->table('price_list_assignments')->where('scope_type', $scopeType)->where('scope_id', $scopeId)->where('price_list_id', $listId)->exists()) {
                throw PricingAdminRefused::duplicate("Price list [{$listId}] is already assigned to {$scopeType} [{$scopeId}].");
            }

            $id = (string) Str::ulid();

            try {
                $connection->table('price_list_assignments')->insert([
                    'id' => $id,
                    'price_list_id' => $listId,
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                    'priority' => $priority,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw PricingAdminRefused::duplicate("Price list [{$listId}] is already assigned to {$scopeType} [{$scopeId}].");
            }

            $row = $connection->table('price_list_assignments')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'price_list_assignment', $id, null, $row === null ? null : (array) $row);

            return new AssignPriceListResult($id);
        });
    }
}
