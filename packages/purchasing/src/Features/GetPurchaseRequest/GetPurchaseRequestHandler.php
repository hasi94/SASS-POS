<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\GetPurchaseRequest;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Purchasing\Documents\RequestImage;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Read one request, header and lines (T-034 AC-34, AC-84, D-36).
 *
 * The caller needs `purchasing.requests.view` **at the request's stored branch**
 * (a company-wide grant counts); an unknown id is `not_found`, and which of the two
 * a caller sees is decided by the id first, so the existence of a request is not
 * hidden from a caller who may not read it (the house shape: every `Get` looks the
 * row up to learn its branch). The lines are returned as stored, by line number:
 * nothing is recomputed on read (AC-24), and a unit factor is the six-decimal
 * string it was frozen as.
 */
final class GetPurchaseRequestHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly PermissionResolver $permissions,
    ) {}

    public function handle(GetPurchaseRequest $query): GetPurchaseRequestResult
    {
        $requestId = Identifier::fold($query->requestId, 'request_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_requests', $requestId, 'purchase request');

        Authorise::require($this->permissions, $query->actorId, PurchasingPermissions::REQUESTS_VIEW, strtoupper((string) $header->branch_id));

        return new GetPurchaseRequestResult(
            RequestImage::header($header),
            array_map(
                static fn (object $line): array => RequestImage::line($line),
                $connection->table('purchase_request_items')->where('request_id', $requestId)->orderBy('line_no')->get()->all(),
            ),
        );
    }
}
