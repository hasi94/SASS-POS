<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\UpdateTerminal;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Flexipos\Organization\Support\Fields;
use Flexipos\Organization\Support\TenantConnection;
use Flexipos\Organization\Support\TerminalLookup;
use Flexipos\Organization\Support\TerminalPlacement;
use Flexipos\Organization\Support\TerminalStatus;

/**
 * Update a terminal's `name` or `location_id` (T-014 AC-42).
 *
 * **`code`, `branch_id`, `status` and `id` are refused `validation`, not
 * ignored**: the code is immutable, the branch is what the terminal belongs
 * to, and status changes only through pausing, resuming and revoking, each
 * with its own audit action. A new location obeys the rule creating a terminal
 * follows (active, sellable, the terminal's own branch). **A revoked terminal
 * cannot be updated** (`terminal_revoked`): it is final (D-15).
 */
final class UpdateTerminalHandler
{
    public const ACTION = 'terminal_updated';

    public const FIELDS = ['name', 'location_id'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateTerminal $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($fields === []) {
            throw OrganizationRefused::validation('An update must name at least one field.');
        }

        $name = Fields::has($fields, 'name') ? Fields::requiredText($fields, 'name', 100) : null;

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $fields, $name): void {
            $before = TerminalLookup::find($connection, $command->terminalId, lock: true);

            if ((string) $before->status === TerminalStatus::REVOKED) {
                throw OrganizationRefused::terminalRevoked("Terminal [{$before->id}] is revoked.");
            }

            $changes = [];

            if ($name !== null) {
                $changes['name'] = $name;
            }

            if (Fields::has($fields, 'location_id')) {
                $changes['location_id'] = (string) TerminalPlacement::requireSellingLocation(
                    $connection,
                    $fields['location_id'],
                    (string) $before->branch_id
                )->id;
            }

            $connection->table('terminals')->where('id', (string) $before->id)->update($changes + [
                'updated_at' => $this->clock->timestamp(),
            ]);

            $after = $connection->table('terminals')->where('id', (string) $before->id)->first();

            $this->audit->record(
                OrganizationPermissions::MODULE,
                $command->actorId,
                self::ACTION,
                'terminal',
                (string) $before->id,
                TerminalLookup::audited($before),
                $after === null ? null : TerminalLookup::audited($after),
                (string) $before->branch_id,
            );
        });
    }
}
