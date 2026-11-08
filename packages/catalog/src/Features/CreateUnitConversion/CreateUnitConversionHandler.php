<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateUnitConversion;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a named unit-to-unit conversion row: `UnitResolver`'s ladder steps
 * 1 and 2 (T-027 AC-13, AC-13a, AC-14, plan Ruling 3, Ruling 9, F-7).
 *
 * **AC-13a.** A product-scoped row whose `to_unit_id` is not that product's
 * own `stock_unit_id` is refused, naming the product's actual stock unit -
 * the ladder reads a row only as `from = line unit, to = stock unit`
 * (F-7), so any other target could never be read.
 */
final class CreateUnitConversionHandler
{
    public const ACTION = 'catalog_unit_conversion_created';

    public const FIELDS = ['product_id', 'from_unit_id', 'to_unit_id', 'factor'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateUnitConversion $command): CreateUnitConversionResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $fromUnitId = Identifier::fold($fields['from_unit_id'] ?? null, 'from_unit_id');
        $toUnitId = Identifier::fold($fields['to_unit_id'] ?? null, 'to_unit_id');
        $productId = Identifier::foldNullable(Fields::has($fields, 'product_id') ? $fields['product_id'] : null, 'product_id');
        $factor = Fields::decimal($fields, 'factor', 6);

        if ($factor === null || (float) $factor <= 0.0) {
            throw CatalogRefused::validation('The factor must be greater than 0.');
        }

        if (preg_match('/^\d{1,12}(\.\d{1,6})?$/', $factor) !== 1) {
            throw CatalogRefused::validation('The factor does not fit DECIMAL(18,6).');
        }

        if ($fromUnitId === $toUnitId) {
            throw CatalogRefused::validation("A unit cannot be converted to itself [{$fromUnitId}].");
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $fromUnitId, $toUnitId, $productId, $factor): CreateUnitConversionResult {
            $fromUnit = self::unit($connection, $fromUnitId, 'from_unit_id');
            $toUnit = self::unit($connection, $toUnitId, 'to_unit_id');

            if ((string) $fromUnit->dimension !== (string) $toUnit->dimension) {
                throw CatalogRefused::dimensionMismatch("The units [{$fromUnitId}] ({$fromUnit->dimension}) and [{$toUnitId}] ({$toUnit->dimension}) do not share a dimension.");
            }

            if ($productId !== null) {
                $product = Lookup::live($connection, 'products', $productId, 'product');

                if ((string) $product->stock_unit_id !== $toUnitId) {
                    throw CatalogRefused::validation("A product-scoped conversion's to_unit_id must be product [{$productId}]'s own stock unit [{$product->stock_unit_id}].");
                }
            }

            $existing = self::findExisting($connection, $productId, $fromUnitId, $toUnitId);

            if ($existing !== null) {
                throw CatalogRefused::duplicate("A conversion from [{$fromUnitId}] to [{$toUnitId}] already exists as [{$existing->id}].");
            }

            $conversionId = (string) Str::ulid();

            try {
                $connection->table('unit_conversions')->insert([
                    'id' => $conversionId,
                    'product_id' => $productId,
                    'from_unit_id' => $fromUnitId,
                    'to_unit_id' => $toUnitId,
                    'factor' => $factor,
                ]);
            } catch (UniqueConstraintViolationException) {
                $existing = self::findExisting($connection, $productId, $fromUnitId, $toUnitId);
                $existingId = $existing === null ? 'unknown' : $existing->id;

                throw CatalogRefused::duplicate("A conversion from [{$fromUnitId}] to [{$toUnitId}] already exists as [{$existingId}].");
            }

            $row = $connection->table('unit_conversions')->where('id', $conversionId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'unit_conversion',
                $conversionId,
                null,
                $row === null ? null : (array) $row,
            );

            return new CreateUnitConversionResult($conversionId);
        });
    }

    private static function unit(Connection $connection, string $unitId, string $what): object
    {
        $row = $connection->table('units')->where('id', $unitId)->first();

        if ($row === null) {
            throw CatalogRefused::notFound("No unit has id [{$unitId}] ({$what}).");
        }

        return $row;
    }

    private static function findExisting(Connection $connection, ?string $productId, string $fromUnitId, string $toUnitId): ?object
    {
        return $connection->table('unit_conversions')
            ->where('from_unit_id', $fromUnitId)
            ->where('to_unit_id', $toUnitId)
            ->where(function ($query) use ($productId) {
                if ($productId === null) {
                    $query->whereNull('product_id');
                } else {
                    $query->where('product_id', $productId);
                }
            })
            ->first();
    }
}
