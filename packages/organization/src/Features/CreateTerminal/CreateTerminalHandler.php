<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\CreateTerminal;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Platform\LimitKey;
use Flexipos\Contracts\Platform\PlanLimits;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Flexipos\Organization\Support\ActivationIssuer;
use Flexipos\Organization\Support\BranchGuard;
use Flexipos\Organization\Support\Fields;
use Flexipos\Organization\Support\OrgCode;
use Flexipos\Organization\Support\PlanCounts;
use Flexipos\Organization\Support\TenantConnection;
use Flexipos\Organization\Support\TerminalLookup;
use Flexipos\Organization\Support\TerminalPlacement;
use Flexipos\Organization\Support\TerminalStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a `pending` terminal and issue its activation code (T-014 AC-26,
 * AC-15, AC-20, D-15, D-17, D-30).
 *
 * **The code is made first and written nowhere but the hash.** The issuer is
 * asked before the transaction opens, so a tenant whose stored code does not
 * fit the format stops here with nothing written (Ruling 7). The row gets the
 * hash and the expiry, the audit row gets neither, and the plaintext is
 * returned once in {@see CreateTerminalResult} and never again.
 *
 * Refused with no row: an unknown or inactive branch (`unknown_branch`,
 * `branch_inactive`), a location that is not an active, sellable location of
 * that same branch, a duplicate code within the branch (soft-deleted rows
 * included) and a malformed code or name. The same code under another branch
 * is accepted.
 */
final class CreateTerminalHandler
{
    public const ACTION = 'terminal_created';

    public const FIELDS = ['branch_id', 'location_id', 'code', 'name'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly ActivationIssuer $issuer,
        private readonly PlanLimits $planLimits,
    ) {}

    public function handle(CreateTerminal $command): CreateTerminalResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $code = OrgCode::normalise($fields['code'] ?? null);
        $name = Fields::requiredText($fields, 'name', 100);

        $issued = $this->issuer->issue();

        // T-016 AC-2: resolved before the transaction, asserted first inside it.
        $terminalLimit = $this->planLimits->forCurrentTenant(LimitKey::Terminals);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $fields, $code, $name, $issued, $terminalLimit): CreateTerminalResult {
            $terminalLimit->assertRoomFor(PlanCounts::seatedTerminals($connection));

            $branchId = (string) BranchGuard::requireActive($connection, $fields['branch_id'] ?? null)->id;
            $locationId = (string) TerminalPlacement::requireSellingLocation($connection, $fields['location_id'] ?? null, $branchId)->id;

            if ($connection->table('terminals')->where('branch_id', $branchId)->where('code', $code)->exists()) {
                throw OrganizationRefused::validation("The code [{$code}] is already used in this branch.");
            }

            $now = $this->clock->timestamp();
            $id = (string) Str::ulid();

            try {
                $connection->table('terminals')->insert([
                    'id' => $id,
                    'branch_id' => $branchId,
                    'location_id' => $locationId,
                    'code' => $code,
                    'name' => $name,
                    'status' => TerminalStatus::PENDING,
                    'activation_code_hash' => $issued['hash'],
                    'activation_expires_at' => $issued['expires_at'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw OrganizationRefused::validation("The code [{$code}] is already used in this branch.");
            }

            $row = $connection->table('terminals')->where('id', $id)->first();

            $this->audit->record(
                OrganizationPermissions::MODULE,
                $command->actorId,
                self::ACTION,
                'terminal',
                $id,
                null,
                $row === null ? null : TerminalLookup::audited($row),
                $branchId,
            );

            return new CreateTerminalResult($id, $issued['code'], $issued['expires_at']);
        });
    }
}
