<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\RemovePurchaseRequestLine;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\RequestImage;
use Flexipos\Purchasing\Documents\RequestLines;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Fields;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Remove a line from a draft request (T-034 AC-25, AC-39).
 *
 * The line is deleted, not flagged: a draft is the one place a row is removed, and
 * its before image is in the audit row. The header is locked `FOR UPDATE` first
 * and the line is read under it, so a line another session just removed is
 * `not_found` and not a silent success. Only a draft (`locked` otherwise).
 */
final class RemovePurchaseRequestLineHandler
{
    private const FIELDS = ['request_id', 'line_id'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
    ) {}

    public function handle(RemovePurchaseRequestLine $command): RemovePurchaseRequestLineResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $requestId = Identifier::fold($fields['request_id'] ?? null, 'request_id');
        $lineId = Identifier::fold($fields['line_id'] ?? null, 'line_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_requests', $requestId, 'purchase request');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::REQUESTS_MANAGE, $branchId);

        return $connection->transaction(function () use ($connection, $requestId, $lineId, $branchId, $actorId): RemovePurchaseRequestLineResult {
            $locked = DocumentLocks::request($connection, $requestId);

            RequestLines::assertDraft($locked);

            $before = RequestImage::line(RequestLines::line($connection, $requestId, $lineId, true));

            $connection->table('purchase_request_items')->where('id', $lineId)->delete();

            RequestLines::touch($connection, $requestId, $actorId, $this->clock->timestamp());

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::REQUEST_LINE_REMOVED,
                'purchase_request',
                $requestId,
                $before,
                null,
                $branchId,
            );

            return new RemovePurchaseRequestLineResult($lineId, $requestId, $before);
        });
    }
}
