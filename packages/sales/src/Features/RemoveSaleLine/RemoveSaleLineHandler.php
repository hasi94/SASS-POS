<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RemoveSaleLine;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Sales\LineWriter;
use Flexipos\Sales\Sales\SaleLocks;
use Flexipos\Sales\Sales\SaleType;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\TenantConnection;

/**
 * Remove a line (T-030 AC-6, AC-8; Ruling 7). The sale is locked first, its lines
 * must still be editable, the line must belong to it, and the header is re-summed.
 * A line of another sale is `not_found` through this one.
 */
final class RemoveSaleLineHandler
{
    public const ACTION = 'sales_sale_line_removed';

    private const FIELDS = ['sale_id', 'line_id'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly EmployeeDirectory $employees,
        private readonly LineWriter $lines,
    ) {}

    public function handle(RemoveSaleLine $command): RemoveSaleLineResult
    {
        Fields::only($command->fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A line is removed by an employee.');
        }

        $saleId = Identifier::fold($command->fields['sale_id'] ?? null, 'sale_id');
        $lineId = Identifier::fold($command->fields['line_id'] ?? null, 'line_id');
        $actorId = Identifier::fold($command->actorId, 'actor');

        if (! $this->employees->isLive($actorId)) {
            throw SalesRefused::notFound("No employee has id [{$actorId}].");
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $saleId, $lineId, $actorId): RemoveSaleLineResult {
            $sale = SaleLocks::lockSale($connection, $saleId);

            if ((string) $sale->type !== SaleType::Sale->value) {
                throw SalesRefused::because(SalesRefusal::SaleNotEditable, "Document [{$saleId}] is not a sale.");
            }

            SaleLocks::assertLinesEditable($connection, $sale);

            $line = $connection->table('sale_items')->where('id', $lineId)->where('sale_id', $saleId)->first();

            if ($line === null) {
                throw SalesRefused::notFound("Sale [{$saleId}] has no line [{$lineId}].");
            }

            $connection->table('sale_items')->where('id', $lineId)->delete();

            $totals = $this->lines->refreshHeader($connection, $sale, $this->clock->timestamp());

            $this->audit->record(SalesAuditActions::MODULE, $actorId, self::ACTION, 'sale_item', $lineId, (array) $line, null, strtoupper((string) $sale->branch_id));

            return new RemoveSaleLineResult($lineId, $totals->total);
        });
    }
}
