<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateUnit;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Catalog\Units\Dimension;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a unit of measure (T-027 AC-11, plan Ruling 6).
 *
 * `precision` defaults to **4**, the storage scale, not `0` (F-11): a
 * default of `0` would silently round every fractional entry on a unit
 * nobody meant to restrict to whole numbers. `base_unit_id`/`factor` are
 * either both present or both absent, and the base unit must share this
 * unit's dimension - a cycle cannot be created here, because the new row's
 * id is referenced by nobody yet (Ruling 6; `UpdateUnit` is where a cycle
 * becomes reachable).
 *
 * The unique-code pre-check compares with `lower()` (Ruling 12), because
 * the engine's `utf8mb4_*_ci` collation makes `units.code` case-insensitive
 * while SQLite's is not; the unique index stays the backstop for a
 * concurrent request.
 */
final class CreateUnitHandler
{
    public const ACTION = 'catalog_unit_created';

    /** The fields a creation takes. */
    public const FIELDS = ['code', 'name', 'dimension', 'precision', 'base_unit_id', 'factor', 'is_active'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateUnit $command): CreateUnitResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $code = Fields::requiredText($fields, 'code', 50);
        $name = Fields::requiredText($fields, 'name', 150);
        $dimension = self::dimension($fields);
        $precision = Fields::integer($fields, 'precision', 4);

        if ($precision < 0 || $precision > 4) {
            throw CatalogRefused::validation('The precision must be an integer from 0 to 4.');
        }

        $baseUnitId = Identifier::foldNullable(Fields::has($fields, 'base_unit_id') ? $fields['base_unit_id'] : null, 'base_unit_id');
        $factor = Fields::decimal($fields, 'factor', 6);

        if (($baseUnitId === null) !== ($factor === null)) {
            throw CatalogRefused::validation('base_unit_id and factor must both be present, or both absent.');
        }

        if ($factor !== null && preg_match('/^\d{1,12}(\.\d{1,6})?$/', $factor) !== 1) {
            throw CatalogRefused::validation('The factor does not fit DECIMAL(18,6).');
        }

        $isActive = Fields::flag($fields, 'is_active', true);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $code, $name, $dimension, $precision, $baseUnitId, $factor, $isActive): CreateUnitResult {
            if ($connection->table('units')->whereRaw('lower(code) = ?', [strtolower($code)])->exists()) {
                throw CatalogRefused::duplicate("The code [{$code}] is already in use.");
            }

            if ($baseUnitId !== null) {
                $base = $connection->table('units')->where('id', $baseUnitId)->first();

                if ($base === null) {
                    throw CatalogRefused::notFound("The base unit [{$baseUnitId}] does not exist.");
                }

                if ($base->dimension !== $dimension->value) {
                    throw CatalogRefused::dimensionMismatch("The base unit [{$baseUnitId}] does not share this unit's dimension [{$dimension->value}].");
                }

                if ((float) $factor <= 0.0) {
                    throw CatalogRefused::validation('The factor must be greater than 0.');
                }
            }

            $now = $this->clock->timestamp();
            $unitId = (string) Str::ulid();

            try {
                $connection->table('units')->insert([
                    'id' => $unitId,
                    'code' => $code,
                    'name' => $name,
                    'dimension' => $dimension->value,
                    'precision' => $precision,
                    'base_unit_id' => $baseUnitId,
                    'factor' => $factor,
                    'is_active' => $isActive,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CatalogRefused::duplicate("The code [{$code}] is already in use.");
            }

            $row = $connection->table('units')->where('id', $unitId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'unit',
                $unitId,
                null,
                $row === null ? null : (array) $row,
            );

            return new CreateUnitResult($unitId);
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function dimension(array $fields): Dimension
    {
        $value = $fields['dimension'] ?? null;

        if (! is_string($value) || Dimension::tryFrom($value) === null) {
            throw CatalogRefused::validation('The dimension must be one of: '.implode(', ', array_map(fn (Dimension $case): string => $case->value, Dimension::cases())).'.');
        }

        return Dimension::from($value);
    }
}
