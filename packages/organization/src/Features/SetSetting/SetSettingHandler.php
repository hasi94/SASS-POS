<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\SetSetting;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Flexipos\Organization\Support\BranchGuard;
use Flexipos\Organization\Support\Fields;
use Flexipos\Organization\Support\SettingsAudit;
use Flexipos\Organization\Support\SettingScope;
use Flexipos\Organization\Support\SettingsRules;
use Flexipos\Organization\Support\TenantConnection;
use Flexipos\Organization\Support\TerminalStatus;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Write a setting at company, branch or terminal scope (T-014 AC-45, AC-46,
 * AC-47, AC-51).
 *
 * **One tenant transaction, and every refusal is decided before the first
 * write**: the key must be registered, its registry row well formed (it
 * fails closed otherwise and logs the key), the value non-null and of the
 * registered type and rules, the scope a company or an existing, usable
 * branch or terminal. Then the existing row for (scope type, scope id, key)
 * is taken `for update` and updated, or inserted: the sentinel-backed unique
 * key is what stops a second company-wide row, and the lock is what makes
 * two writers take turns rather than race to the key.
 *
 * Setting the same triple again **replaces** the row, and writes an audit row
 * whether or not the value changed: `updated_by` and `updated_at` did.
 *
 * **A secret's value is read by nothing after this**: the response names the
 * setting and its scope and never the value, the audit before and after hold
 * `is_set` instead, and nothing here logs a value. A JSON `null` is refused
 * (D-23) - clearing is its own operation.
 */
final class SetSettingHandler
{
    public const ACTION = 'setting_set';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{setting_id: string, key: string, scope_type: string, scope_id: string|null}
     */
    public function handle(SetSetting $command): array
    {
        Fields::only($command->fields, ['key', 'scope_type', 'scope_id', 'value']);

        $key = $command->fields['key'] ?? null;

        if (! is_string($key) || $key === '' || mb_strlen($key) > 120) {
            throw OrganizationRefused::validation('The key is required and must be a string of 1 to 120 characters.');
        }

        $scope = SettingScope::parse($command->fields['scope_type'] ?? null, $command->fields['scope_id'] ?? null);
        $value = $command->fields['value'] ?? null;
        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $key, $scope, $value): array {
            $registry = $connection->table('settings_registry')->where('key', $key)->first();

            if ($registry === null) {
                throw OrganizationRefused::validation("No setting is registered under [{$key}].");
            }

            $definition = SettingsRules::definition($registry, $this->logger);

            if ($value === null) {
                throw OrganizationRefused::validation("A value is required for [{$key}]; clear the override to remove it.");
            }

            SettingsRules::check($value, $definition, $key);

            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                throw OrganizationRefused::validation("The value for [{$key}] cannot be stored as JSON.");
            }

            $branchId = $this->requireUsableScope($connection, $scope);
            $secret = (bool) $registry->is_secret;
            $now = $this->clock->timestamp();

            $query = $connection->table('settings')->where('scope_type', $scope->type)->where('key', $key);

            if ($scope->id === null) {
                $query->whereNull('scope_id');
            } else {
                $query->where('scope_id', $scope->id);
            }

            $existing = $query->lockForUpdate()->first();

            if ($existing === null) {
                $id = (string) Str::ulid();

                $connection->table('settings')->insert([
                    'id' => $id,
                    'scope_type' => $scope->type,
                    'scope_id' => $scope->id,
                    'key' => $key,
                    'value' => $encoded,
                    'updated_by' => $command->actorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $before = null;
            } else {
                $id = (string) $existing->id;

                $connection->table('settings')->where('id', $id)->update([
                    'value' => $encoded,
                    'updated_by' => $command->actorId,
                    'updated_at' => $now,
                ]);

                $before = SettingsAudit::snapshot($key, $scope, (string) $existing->value, $secret);
            }

            $this->audit->record(
                OrganizationPermissions::MODULE,
                $command->actorId,
                self::ACTION,
                'setting',
                $id,
                $before,
                SettingsAudit::snapshot($key, $scope, $encoded, $secret),
                $branchId,
            );

            return ['setting_id' => $id, 'key' => $key, 'scope_type' => $scope->type, 'scope_id' => $scope->id];
        });
    }

    /**
     * The scope must be usable to be written: an active branch, a terminal
     * that exists and is not revoked. Returns the branch the audit row
     * carries, which a company-wide write does not have.
     *
     * @throws OrganizationRefused unknown_branch, branch_inactive, unknown_terminal, terminal_revoked
     */
    private function requireUsableScope(Connection $connection, SettingScope $scope): ?string
    {
        if ($scope->type === SettingScope::BRANCH) {
            return (string) BranchGuard::requireActive($connection, $scope->id)->id;
        }

        if ($scope->type !== SettingScope::TERMINAL) {
            return null;
        }

        $terminal = $connection->table('terminals')->where('id', $scope->id)->whereNull('deleted_at')->first();

        if ($terminal === null) {
            throw OrganizationRefused::unknownTerminal("No terminal has id [{$scope->id}].");
        }

        if ((string) $terminal->status === TerminalStatus::REVOKED) {
            throw OrganizationRefused::terminalRevoked("Terminal [{$scope->id}] is revoked.");
        }

        return (string) $terminal->branch_id;
    }
}
