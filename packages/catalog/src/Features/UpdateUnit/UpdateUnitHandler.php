<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\UpdateUnit;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Update a unit's `code`, `name`, `precision`, `is_active`, or `base_unit_id`
 * with `factor` (T-027 AC-11, plan Ruling 6).
 *
 * **`dimension` is immutable** once created - purchase units, sales units,
 * conversions and barcodes were all validated against it, so changing it
 * later would silently invalidate every one of them.
 *
 * **A cycle cannot be created by `CreateUnit`**, because a brand-new row's
 * id is referenced by nobody yet. This handler is where a cycle becomes
 * reachable: re-pointing `base_unit_id` so the chain from the new base
 * walks back to this unit is refused, walking the whole chain rather than
 * checking one step.
 */
final class UpdateUnitHandler
{
    public const ACTION = 'catalog_unit_updated';

    public const FIELDS = ['code', 'name', 'precision', 'is_active', 'base_unit_id', 'factor'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateUnit $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($fields === []) {
            throw CatalogRefused::validation('An update must name at least one field.');
        }

        if (Fields::has($fields, 'base_unit_id') !== Fields::has($fields, 'factor')) {
            throw CatalogRefused::validation('base_unit_id and factor must both be submitted, or neither.');
        }

        $unitId = Identifier::fold($command->unitId, 'unitId');

        $changes = [];

        if (Fields::has($fields, 'code')) {
            $changes['code'] = Fields::requiredText($fields, 'code', 50);
        }

        if (Fields::has($fields, 'name')) {
            $changes['name'] = Fields::requiredText($fields, 'name', 150);
        }

        if (Fields::has($fields, 'precision')) {
            $precision = Fields::integer($fields, 'precision', 0);

            if ($precision < 0 || $precision > 4) {
                throw CatalogRefused::validation('The precision must be an integer from 0 to 4.');
            }

            $changes['precision'] = $precision;
        }

        if (Fields::has($fields, 'is_active')) {
            $changes['is_active'] = Fields::flag($fields, 'is_active', true);
        }

        $newBaseUnitId = null;
        $newFactor = null;
        $changingBase = false;

        if (Fields::has($fields, 'base_unit_id')) {
            $changingBase = true;
            $newBaseUnitId = Identifier::foldNullable($fields['base_unit_id'], 'base_unit_id');
            $newFactor = Fields::decimal($fields, 'factor', 6);

            if (($newBaseUnitId === null) !== ($newFactor === null)) {
                throw CatalogRefused::validation('base_unit_id and factor must both be present, or both absent.');
            }

            if ($newFactor !== null && preg_match('/^\d{1,12}(\.\d{1,6})?$/', $newFactor) !== 1) {
                throw CatalogRefused::validation('The factor does not fit DECIMAL(18,6).');
            }

            if ($newFactor !== null && (float) $newFactor <= 0.0) {
                throw CatalogRefused::validation('The factor must be greater than 0.');
            }

            $changes['base_unit_id'] = $newBaseUnitId;
            $changes['factor'] = $newFactor;
        }

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $unitId, $changes, $changingBase, $newBaseUnitId): void {
            $before = $connection->table('units')->where('id', $unitId)->lockForUpdate()->first();

            if ($before === null) {
                throw CatalogRefused::notFound("The unit [{$unitId}] does not exist.");
            }

            if (isset($changes['code']) && $changes['code'] !== $before->code) {
                $duplicate = $connection->table('units')
                    ->whereRaw('lower(code) = ?', [strtolower($changes['code'])])
                    ->where('id', '!=', $unitId)
                    ->exists();

                if ($duplicate) {
                    throw CatalogRefused::duplicate("The code [{$changes['code']}] is already in use.");
                }
            }

            if ($changingBase && $newBaseUnitId !== null) {
                $base = $connection->table('units')->where('id', $newBaseUnitId)->first();

                if ($base === null) {
                    throw CatalogRefused::notFound("The base unit [{$newBaseUnitId}] does not exist.");
                }

                if ($base->dimension !== $before->dimension) {
                    throw CatalogRefused::dimensionMismatch("The base unit [{$newBaseUnitId}] does not share this unit's dimension [{$before->dimension}].");
                }

                self::assertNoCycle($connection, $unitId, $newBaseUnitId);
            }

            try {
                $connection->table('units')->where('id', $unitId)->update($changes + [
                    'updated_at' => $this->clock->timestamp(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CatalogRefused::duplicate("The code [{$changes['code']}] is already in use.");
            }

            $after = $connection->table('units')->where('id', $unitId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'unit',
                $unitId,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        });
    }

    /**
     * Walk the chain from `$candidateBaseUnitId` to its root. If `$unitId`
     * is reached, pointing the unit's base there would close a cycle.
     *
     * @throws CatalogRefused cycle
     */
    private static function assertNoCycle(Connection $connection, string $unitId, string $candidateBaseUnitId): void
    {
        $visited = [];
        $current = $candidateBaseUnitId;

        while (true) {
            if ($current === $unitId) {
                throw CatalogRefused::cycle("Pointing [{$unitId}] at base [{$candidateBaseUnitId}] would close a cycle.");
            }

            if (isset($visited[$current])) {
                // An existing cycle elsewhere in the chain: not this
                // handler's business to fix, and not a reason to loop.
                return;
            }

            $visited[$current] = true;

            $row = $connection->table('units')->where('id', $current)->first(['base_unit_id']);

            if ($row === null || $row->base_unit_id === null) {
                return;
            }

            $current = $row->base_unit_id;
        }
    }
}
