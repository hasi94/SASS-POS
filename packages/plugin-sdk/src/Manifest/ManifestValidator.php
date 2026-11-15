<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Manifest;

use Flexipos\Contracts\Plugins\AddHook;
use Flexipos\Contracts\Plugins\AfterHook;
use Flexipos\Contracts\Plugins\BeforeHook;
use Flexipos\Contracts\Plugins\FilterHook;
use Flexipos\Contracts\Plugins\HookMode;
use Flexipos\Contracts\Plugins\OverrideHook;
use Flexipos\PluginSdk\Exceptions\ManifestRefused;
use Flexipos\PluginSdk\Gateway\HookPointCatalogue;
use ReflectionClass;

/**
 * `plugin.json`'s validator, with one named constructor per refusal and
 * checks run in a fixed order, stopping at the first failure (T-019 plan
 * Ruling 2).
 *
 * Re-run at registration, at enable time and at runtime load (AC-35):
 * nothing trusts a stored manifest without validating it again.
 */
final class ManifestValidator
{
    private const PERMISSION_PATTERN = '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)*$/';

    private const REQUIRED_FIELDS = ['name', 'version', 'core_constraint', 'hooks', 'permissions', 'requires_licence'];

    private const KNOWN_FIELDS = [
        'name', 'version', 'core_constraint', 'hooks', 'permissions', 'requires_licence',
        'display_name', 'type', 'visibility', 'author', 'description', 'requires', 'migrations',
    ];

    private const TYPES = ['module', 'integration', 'theme', 'report', 'vertical'];

    private const VISIBILITIES = ['official', 'private', 'third_party'];

    /**
     * @var array<string, class-string>
     */
    private const HANDLER_CONTRACTS = [
        'add' => AddHook::class,
        'before' => BeforeHook::class,
        'after' => AfterHook::class,
        'filter' => FilterHook::class,
        'override' => OverrideHook::class,
    ];

    public function __construct(
        private readonly HookPointCatalogue $catalogue,
    ) {}

    public function validate(string $json): Manifest
    {
        $decoded = $this->decode($json);

        $this->requireFields($decoded);
        $this->checkShapes($decoded);

        $name = (string) $decoded['name'];
        $coreConstraint = (string) $decoded['core_constraint'];
        $this->checkCoreVersion($coreConstraint);

        $hooks = $this->checkHooks($decoded['hooks']);

        return new Manifest(
            name: $name,
            version: (string) $decoded['version'],
            coreConstraint: $coreConstraint,
            hooks: $hooks,
            permissions: array_values($decoded['permissions']),
            requiresLicence: $decoded['requires_licence'],
            displayName: isset($decoded['display_name']) ? (string) $decoded['display_name'] : null,
            type: isset($decoded['type']) ? (string) $decoded['type'] : 'integration',
            visibility: isset($decoded['visibility']) ? (string) $decoded['visibility'] : 'private',
            author: isset($decoded['author']) ? (string) $decoded['author'] : null,
            description: isset($decoded['description']) ? (string) $decoded['description'] : null,
            requires: isset($decoded['requires']) ? (array) $decoded['requires'] : [],
            migrationsDir: isset($decoded['migrations']) ? (string) $decoded['migrations'] : 'migrations',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        try {
            // Decoded as objects first, so an empty JSON array `[]` can be
            // told apart from an empty JSON object `{}` - both decode to
            // `[]` once $assoc=true collapses the distinction.
            $probe = json_decode($json, false, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ManifestRefused::malformedJson();
        }

        if (! $probe instanceof \stdClass) {
            throw ManifestRefused::malformedJson();
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function requireFields(array $decoded): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw ManifestRefused::missingField($field);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function checkShapes(array $decoded): void
    {
        foreach (array_keys($decoded) as $key) {
            if (! in_array($key, self::KNOWN_FIELDS, true)) {
                throw ManifestRefused::unknownField($key);
            }
        }

        if (! is_string($decoded['name']) || preg_match(PluginName::NAME_PATTERN, $decoded['name']) !== 1 || strlen($decoded['name']) > PluginName::MAX_LENGTH) {
            throw ManifestRefused::invalidField('name', 'must match '.PluginName::NAME_PATTERN.' and be at most '.PluginName::MAX_LENGTH.' characters.');
        }

        if (! is_string($decoded['version']) || preg_match('/^\d+\.\d+\.\d+$/', $decoded['version']) !== 1 || strlen($decoded['version']) > 20) {
            throw ManifestRefused::invalidField('version', 'must match ^\d+\.\d+\.\d+$ and be at most 20 characters.');
        }

        if (! is_string($decoded['core_constraint']) || strlen($decoded['core_constraint']) > 40) {
            throw ManifestRefused::invalidField('core_constraint', 'must be a string of at most 40 characters.');
        }

        try {
            VersionConstraint::parse($decoded['core_constraint']);
        } catch (InvalidVersionConstraint) {
            throw ManifestRefused::invalidField('core_constraint', 'is not a valid version constraint.');
        }

        if (! is_array($decoded['hooks']) || ! array_is_list($decoded['hooks']) || ! self::allObjects($decoded['hooks'])) {
            throw ManifestRefused::invalidField('hooks', 'must be a list of objects.');
        }

        if (! is_array($decoded['permissions']) || ! array_is_list($decoded['permissions']) || ! self::allValidPermissions($decoded['permissions'])) {
            throw ManifestRefused::invalidField('permissions', 'must be a list of dotted lower-case permission keys.');
        }

        if (! is_bool($decoded['requires_licence'])) {
            throw ManifestRefused::invalidField('requires_licence', 'must be a real boolean, never a coerced string.');
        }

        if (isset($decoded['type']) && ! in_array($decoded['type'], self::TYPES, true)) {
            throw ManifestRefused::invalidField('type', 'must be one of '.implode(', ', self::TYPES).'.');
        }

        if (isset($decoded['visibility']) && ! in_array($decoded['visibility'], self::VISIBILITIES, true)) {
            throw ManifestRefused::invalidField('visibility', 'must be one of '.implode(', ', self::VISIBILITIES).'.');
        }
    }

    /**
     * @param  list<mixed>  $hooks
     */
    private static function allObjects(array $hooks): bool
    {
        foreach ($hooks as $hook) {
            if (! is_array($hook) || array_is_list($hook)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $permissions
     */
    private static function allValidPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! is_string($permission) || preg_match(self::PERMISSION_PATTERN, $permission) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function checkCoreVersion(string $constraint): void
    {
        $core = (string) config('plugins.core_version', '0.1.0');

        if (! VersionConstraint::parse($constraint)->satisfiedBy($core)) {
            throw ManifestRefused::coreVersionMismatch($constraint, $core);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $hooks
     * @return list<ManifestHook>
     */
    private function checkHooks(array $hooks): array
    {
        $registered = [];
        $result = [];

        foreach ($hooks as $index => $hook) {
            foreach (['point', 'mode', 'handler'] as $required) {
                if (! array_key_exists($required, $hook)) {
                    throw ManifestRefused::missingField("hooks[{$index}].{$required}");
                }
            }

            $pointName = (string) $hook['point'];
            $modeValue = (string) $hook['mode'];
            $handler = (string) $hook['handler'];

            $mode = HookMode::tryFrom($modeValue);

            if ($mode === null) {
                throw ManifestRefused::unknownMode($modeValue);
            }

            $point = $this->catalogue->find($pointName);

            if ($point === null) {
                throw ManifestRefused::unknownPoint($pointName);
            }

            if ($mode === HookMode::Override && ! $point->isOverridable()) {
                throw ManifestRefused::overrideNotPermitted($pointName);
            }

            if (! $point->accepts($mode)) {
                throw ManifestRefused::modeNotAccepted($pointName, $modeValue);
            }

            if (in_array($mode, [HookMode::Override, HookMode::After], true)) {
                $key = $pointName.'|'.$modeValue;

                if (isset($registered[$key])) {
                    throw ManifestRefused::duplicateRegistration($pointName, $modeValue);
                }

                $registered[$key] = true;
            }

            $this->checkHandler($handler, $mode);

            $result[] = new ManifestHook($pointName, $mode, $handler);
        }

        return $result;
    }

    private function checkHandler(string $class, HookMode $mode): void
    {
        if (! class_exists($class)) {
            throw ManifestRefused::handlerNotFound($class);
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            throw ManifestRefused::handlerNotFound($class);
        }

        $contract = self::HANDLER_CONTRACTS[$mode->value];

        if (! $reflection->implementsInterface($contract)) {
            throw ManifestRefused::handlerWrongContract($class, $mode->value);
        }
    }
}
