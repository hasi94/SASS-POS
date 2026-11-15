<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Exceptions;

use RuntimeException;

/**
 * A registry or enablement refusal, with one named constructor per reason
 * (T-019 plan Rulings 7, 8 and 12). Every refusal happens before any
 * write.
 */
final class PluginRefused extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unknownPlugin(string $name): self
    {
        return new self('unknownPlugin', "No plugin named [{$name}] is registered.");
    }

    public static function unknownVersion(string $name, string $version): self
    {
        return new self('unknownVersion', "Plugin [{$name}] has no registered version [{$version}].");
    }

    public static function versionNotAvailable(string $name, string $version): self
    {
        return new self('versionNotAvailable', "Plugin [{$name}] version [{$version}] is not available.");
    }

    public static function unknownTenant(string $tenantId): self
    {
        return new self('unknownTenant', "No tenant [{$tenantId}] is registered in the master.");
    }

    public static function versionAlreadyRegistered(string $name, string $version): self
    {
        return new self('versionAlreadyRegistered', "Plugin [{$name}] version [{$version}] is already registered.");
    }

    public static function notPrivate(string $name): self
    {
        return new self('notPrivate', "Plugin [{$name}] is not private, so a grant would mean nothing.");
    }

    public static function tripped(string $name): self
    {
        return new self('tripped', "Plugin [{$name}] is tripped for this tenant. Run plugin:reset first.");
    }

    public static function updating(string $name): self
    {
        return new self('updating', "Plugin [{$name}] is mid-update for this tenant.");
    }

    public static function versionChangeNotSupported(string $name): self
    {
        return new self('versionChangeNotSupported', "Plugin [{$name}] is already enabled at a different version. Upgrades are not supported.");
    }

    public static function accessNotGranted(string $name, string $tenantId): self
    {
        return new self('accessNotGranted', "Plugin [{$name}] is private, and tenant [{$tenantId}] has no grant.");
    }

    public static function dependencyNotSatisfied(string $dependency, string $constraint): self
    {
        return new self(
            'dependencyNotSatisfied',
            "Dependency on [{$dependency}] [{$constraint}] is not satisfied for this tenant."
        );
    }

    public static function overrideAlreadyClaimed(string $point, string $holder): self
    {
        return new self(
            'overrideAlreadyClaimed',
            "Hook point [{$point}] is already overridden by [{$holder}] for this tenant."
        );
    }

    public static function licenceRequired(string $name): self
    {
        return new self('licenceRequired', "Plugin [{$name}] requires a licence, and none is active for this tenant.");
    }

    public static function disableFirst(string $name): self
    {
        return new self('disableFirst', "Plugin [{$name}] is enabled. Disable it before uninstalling.");
    }

    public static function cannotDisable(string $name, string $status): self
    {
        return new self('cannotDisable', "Plugin [{$name}] cannot be disabled from status [{$status}].");
    }

    public static function notTripped(string $name): self
    {
        return new self('notTripped', "Plugin [{$name}] is not tripped for this tenant.");
    }
}
