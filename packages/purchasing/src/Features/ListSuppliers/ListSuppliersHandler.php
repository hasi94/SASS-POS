<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ListSuppliers;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Suppliers\SupplierImage;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Page;
use Flexipos\Purchasing\Support\Pager;
use Flexipos\Purchasing\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * List the live suppliers, ordered by code and keyset-paged (T-034 AC-16, AC-18,
 * D-34, D-36).
 *
 * Either supplier key may list; the rows never carry `bank_details`, whatever the
 * reader holds, because a list is not where a bank account is read. Soft-deleted
 * suppliers never appear. `is_active` and `q` combine; a page size above the
 * maximum is clamped (never refused), a non-positive or non-integer one and a
 * malformed `after_code` are `validation`; a cursor past the end is an empty page.
 *
 * `q` is a contains match on the code or the name, case-insensitive, and the
 * wildcard characters in it are literal: it is escaped with `!`, an escape
 * character the SQLite stand-in and MySQL both read the same way.
 */
final class ListSuppliersHandler
{
    private const MAX_QUERY = 150;

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly PermissionResolver $permissions,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(ListSuppliers $query): Page
    {
        Authorise::requireAny(
            $this->permissions,
            $query->actorId,
            [PurchasingPermissions::SUPPLIERS_MANAGE, PurchasingPermissions::SUPPLIERS_VIEW],
            null,
        );

        if ($query->isActive !== null && ! is_bool($query->isActive)) {
            throw PurchasingRefused::validation('The is_active filter must be true or false.');
        }

        $fragment = $this->fragment($query->query);

        $builder = $this->tenant->get()->table('suppliers')->whereNull('deleted_at');

        if ($query->isActive !== null) {
            $builder->where('is_active', $query->isActive);
        }

        if ($fragment !== null) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $fragment).'%';

            $builder->where(static function ($inner) use ($like): void {
                $inner->whereRaw("lower(code) like ? escape '!'", [$like])
                    ->orWhereRaw("lower(name) like ? escape '!'", [$like]);
            });
        }

        $page = Pager::pageByCode($builder, $this->config, $query->pageSize, $query->afterCode);

        return new Page(
            array_map(
                static fn (array $row): array => SupplierImage::withoutBankDetails(SupplierImage::of($row)),
                $page->rows,
            ),
            $page->nextCursor,
        );
    }

    /**
     * @throws PurchasingRefused validation
     */
    private function fragment(mixed $submitted): ?string
    {
        if ($submitted === null) {
            return null;
        }

        if (! is_string($submitted) || mb_strlen($submitted) > self::MAX_QUERY) {
            throw PurchasingRefused::validation('The q filter must be a string of at most '.self::MAX_QUERY.' characters.');
        }

        $trimmed = mb_strtolower(trim($submitted));

        return $trimmed === '' ? null : $trimmed;
    }
}
