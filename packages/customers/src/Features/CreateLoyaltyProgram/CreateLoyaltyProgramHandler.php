<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\CreateLoyaltyProgram;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Customers\Loyalty\TierRules;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Fields;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;

/**
 * Create a loyalty program (T-028 plan Ruling 5, AC-26, D-22).
 *
 * `earn_rule`/`redeem_rule` are stored and returned as supplied, with no
 * schema validation beyond "valid JSON" - nothing in this task evaluates
 * either. `tier_rules` is validated against the one minimal shape
 * {@see TierRules::parse()} before the row is written (F-5).
 */
final class CreateLoyaltyProgramHandler
{
    public const ACTION = 'loyalty_program_created';

    public const FIELDS = ['name', 'earn_rule', 'redeem_rule', 'tier_rules', 'expiry_months', 'is_active'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateLoyaltyProgram $command): CreateLoyaltyProgramResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $name = Fields::requiredText($fields, 'name', 150);
        $earnRule = Fields::json($fields, 'earn_rule');
        $redeemRule = Fields::json($fields, 'redeem_rule');

        TierRules::parse(Fields::has($fields, 'tier_rules') ? $fields['tier_rules'] : null);
        $tierRules = Fields::json($fields, 'tier_rules');

        $expiryMonths = Fields::unsignedSmallInt($fields, 'expiry_months', null);
        $isActive = Fields::flag($fields, 'is_active', true);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use (
            $connection, $command, $name, $earnRule, $redeemRule, $tierRules, $expiryMonths, $isActive,
        ): CreateLoyaltyProgramResult {
            $now = $this->clock->timestamp();
            $programId = (string) Str::ulid();

            $connection->table('loyalty_programs')->insert([
                'id' => $programId,
                'created_at' => $now,
                'updated_at' => $now,
                'name' => $name,
                'earn_rule' => $earnRule,
                'redeem_rule' => $redeemRule,
                'tier_rules' => $tierRules,
                'expiry_months' => $expiryMonths,
                'is_active' => $isActive,
            ]);

            $row = $connection->table('loyalty_programs')->where('id', $programId)->first();

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'loyalty_program',
                $programId,
                null,
                $row === null ? null : (array) $row,
            );

            return new CreateLoyaltyProgramResult($programId);
        });
    }
}
