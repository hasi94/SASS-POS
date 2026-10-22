<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

/**
 * What a settings audit row records (T-014 AC-20, AC-47).
 *
 * The same shape before and after, so `changed_fields` is derived honestly:
 * the setting's key and scope, and then **either the value or, for a secret,
 * only `is_set`**. A secret's value has one home, `settings.value`; it is not
 * in an audit row, a log line or a response (Ruling 9).
 */
final class SettingsAudit
{
    /**
     * @param  string|null  $encoded  the stored JSON text of the value
     * @return array<string, mixed>
     */
    public static function snapshot(string $key, SettingScope $scope, ?string $encoded, bool $secret): array
    {
        $snapshot = ['key' => $key, 'scope_type' => $scope->type, 'scope_id' => $scope->id];

        if ($secret) {
            $snapshot['is_set'] = $encoded !== null;

            return $snapshot;
        }

        $snapshot['value'] = $encoded === null ? null : json_decode($encoded, true);

        return $snapshot;
    }
}
