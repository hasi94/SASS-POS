<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\UpdateLoyaltyProgram;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Loyalty\TierRules;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Fields;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Update a loyalty program (T-028 plan Ruling 5, AC-26, D-22). A program
 * carries no `[SOFT]` tag and no `version` column (section 6.9) - this is a
 * plain patch, retiring the program via `is_active = false` (D-23).
 */
final class UpdateLoyaltyProgramHandler
{
    public const ACTION = 'loyalty_program_updated';

    public const FIELDS = ['name', 'earn_rule', 'redeem_rule', 'tier_rules', 'expiry_months', 'is_active'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateLoyaltyProgram $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $programId = Identifier::fold($command->programId, 'programId');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $programId, $fields): void {
            $before = $connection->table('loyalty_programs')->where('id', $programId)->lockForUpdate()->first();

            if ($before === null) {
                throw CustomersRefused::notFound("The loyalty program [{$programId}] does not exist.");
            }

            $changes = [];

            if (Fields::has($fields, 'name')) {
                $changes['name'] = Fields::requiredText($fields, 'name', 150);
            }

            if (Fields::has($fields, 'earn_rule')) {
                $changes['earn_rule'] = Fields::json($fields, 'earn_rule');
            }

            if (Fields::has($fields, 'redeem_rule')) {
                $changes['redeem_rule'] = Fields::json($fields, 'redeem_rule');
            }

            if (Fields::has($fields, 'tier_rules')) {
                TierRules::parse($fields['tier_rules']);
                $changes['tier_rules'] = Fields::json($fields, 'tier_rules');
            }

            if (Fields::has($fields, 'expiry_months')) {
                $changes['expiry_months'] = Fields::unsignedSmallInt($fields, 'expiry_months', null);
            }

            if (Fields::has($fields, 'is_active')) {
                $changes['is_active'] = Fields::flag($fields, 'is_active', true);
            }

            $changes['updated_at'] = $this->clock->timestamp();

            $connection->table('loyalty_programs')->where('id', $programId)->update($changes);

            $after = $connection->table('loyalty_programs')->where('id', $programId)->first();

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'loyalty_program',
                $programId,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        });
    }
}
