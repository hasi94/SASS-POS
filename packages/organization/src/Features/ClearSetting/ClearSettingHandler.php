<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ClearSetting;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Flexipos\Organization\Support\Fields;
use Flexipos\Organization\Support\SettingsAudit;
use Flexipos\Organization\Support\SettingScope;
use Flexipos\Organization\Support\TenantConnection;

/**
 * Clear an override (T-014 AC-46, clearing half).
 *
 * The row is taken `for update` and deleted, one audit row is written, and
 * the next layer down applies from the next read (AC-44). **Clearing a row
 * that does not exist is `validation` and writes nothing.** The scope is
 * deliberately not checked for being active or unrevoked: an override on an
 * inactive branch or a revoked terminal must stay removable, as a detach
 * does (D-6).
 */
final class ClearSettingHandler
{
    public const ACTION = 'setting_cleared';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @return array{key: string, scope_type: string, scope_id: string|null}
     */
    public function handle(ClearSetting $command): array
    {
        Fields::only($command->fields, ['key', 'scope_type', 'scope_id']);

        $key = $command->fields['key'] ?? null;

        if (! is_string($key) || $key === '' || mb_strlen($key) > 120) {
            throw OrganizationRefused::validation('The key is required and must be a string of 1 to 120 characters.');
        }

        $scope = SettingScope::parse($command->fields['scope_type'] ?? null, $command->fields['scope_id'] ?? null);
        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $key, $scope): array {
            $query = $connection->table('settings')->where('scope_type', $scope->type)->where('key', $key);

            if ($scope->id === null) {
                $query->whereNull('scope_id');
            } else {
                $query->where('scope_id', $scope->id);
            }

            $existing = $query->lockForUpdate()->first();

            if ($existing === null) {
                throw OrganizationRefused::validation("There is no override of [{$key}] at that scope to clear.");
            }

            $secret = (bool) $connection->table('settings_registry')->where('key', $key)->value('is_secret');
            $id = (string) $existing->id;

            $connection->table('settings')->where('id', $id)->delete();

            $branchId = match ($scope->type) {
                SettingScope::BRANCH => $scope->id,
                SettingScope::TERMINAL => $connection->table('terminals')->where('id', $scope->id)->value('branch_id'),
                default => null,
            };

            $this->audit->record(
                OrganizationPermissions::MODULE,
                $command->actorId,
                self::ACTION,
                'setting',
                $id,
                SettingsAudit::snapshot($key, $scope, (string) $existing->value, $secret),
                null,
                $branchId === null ? null : (string) $branchId,
            );

            return ['key' => $key, 'scope_type' => $scope->type, 'scope_id' => $scope->id];
        });
    }
}
