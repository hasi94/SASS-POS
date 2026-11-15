<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

use Flexipos\Contracts\Catalog\UnitSnapshot;

/**
 * What a caller asks `PricingEngine::quoteLine()` to price (plan Ruling 2).
 *
 * `unit` comes from `UnitResolver::snapshot()` and is never hand-built: the
 * engine multiplies by its `unitFactor` and never re-resolves it, so a refund
 * line may legitimately carry an older, snapshotted factor (Ruling 4).
 * `at` is the document instant (UTC, millisecond precision) that every window
 * check runs against, never the wall clock; `documentDate` is the branch-local
 * date a tax rate is resolved on. `currency` filters price lists, which are
 * skipped, never converted, when they differ.
 */
final readonly class LineQuote
{
    public string $productId;

    public string $at;

    public string $documentDate;

    public string $currency;

    public ?string $customerId;

    public ?string $customerGroupId;

    public ?string $branchId;

    public ?string $discountId;

    public function __construct(
        string $productId,
        public UnitSnapshot $unit,
        string $at,
        string $documentDate,
        string $currency,
        ?string $customerId = null,
        ?string $customerGroupId = null,
        ?string $branchId = null,
        ?string $discountId = null,
    ) {
        $this->productId = Shape::ulid($productId, 'productId');
        $this->at = Shape::instant($at, 'at');
        $this->documentDate = Shape::date($documentDate, 'documentDate');
        $this->currency = Shape::currency($currency, 'currency');
        $this->customerId = $customerId === null ? null : Shape::ulid($customerId, 'customerId');
        $this->customerGroupId = $customerGroupId === null ? null : Shape::ulid($customerGroupId, 'customerGroupId');
        $this->branchId = $branchId === null ? null : Shape::ulid($branchId, 'branchId');
        $this->discountId = $discountId === null ? null : Shape::ulid($discountId, 'discountId');
    }
}
