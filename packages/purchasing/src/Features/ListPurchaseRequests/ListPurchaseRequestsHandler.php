<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ListPurchaseRequests;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Purchasing\Documents\BranchScope;
use Flexipos\Purchasing\Documents\RequestImage;
use Flexipos\Purchasing\Documents\RequestStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Page;
use Flexipos\Purchasing\Support\Pager;
use Flexipos\Purchasing\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * List requests (T-034 AC-35, AC-84, D-34, D-36).
 *
 * Only requests of branches where the caller holds `purchasing.requests.view`
 * appear ({@see BranchScope}: one decision at the filtered branch, else one per
 * branch row, none allowed an empty page). Newest first by id, keyset-paged with
 * `before_id`; a `page_size` above the maximum is clamped, a non-positive or
 * non-integer one and a malformed cursor are `validation`; the filters (`status`,
 * `branch_id`, `number`) combine, and an unknown status is `validation`, never an
 * empty list. A cursor past the end or no match is an empty page.
 */
final class ListPurchaseRequestsHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly PermissionResolver $permissions,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(ListPurchaseRequests $query): Page
    {
        $connection = $this->tenant->get();

        $status = $this->status($query->status);
        $number = $this->number($query->number);

        $allowed = BranchScope::allowed($connection, $this->permissions, $query->actorId, PurchasingPermissions::REQUESTS_VIEW, $query->branchId);

        // Validate the paging even when nothing may be shown, so a bad cursor is never silently an empty page.
        Pager::size($this->config, $query->pageSize);
        Identifier::foldNullable($query->beforeId, 'before_id');

        if ($allowed === []) {
            return new Page([], null);
        }

        $builder = $connection->table('purchase_requests')->whereIn('branch_id', $allowed);

        if ($status !== null) {
            $builder->where('status', $status);
        }

        if ($number !== null) {
            $builder->where('number', $number);
        }

        $page = Pager::page($builder, $this->config, $query->pageSize, $query->beforeId);

        return new Page(
            array_map(static fn (array $row): array => RequestImage::header($row), $page->rows),
            $page->nextCursor,
        );
    }

    /**
     * @throws PurchasingRefused validation
     */
    private function status(mixed $submitted): ?string
    {
        if ($submitted === null) {
            return null;
        }

        $statuses = array_map(static fn (RequestStatus $status): string => $status->value, RequestStatus::cases());

        if (! is_string($submitted) || ! in_array($submitted, $statuses, true)) {
            throw PurchasingRefused::validation('The status must be one of: '.implode(', ', $statuses).'.');
        }

        return $submitted;
    }

    /**
     * @throws PurchasingRefused validation
     */
    private function number(mixed $submitted): ?string
    {
        if ($submitted === null) {
            return null;
        }

        if (! is_string($submitted) || trim($submitted) === '' || mb_strlen(trim($submitted)) > 30) {
            throw PurchasingRefused::validation('The number must be a document number of at most 30 characters.');
        }

        return trim($submitted);
    }
}
