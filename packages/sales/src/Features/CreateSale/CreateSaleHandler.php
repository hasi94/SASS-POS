<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\CreateSale;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Organization\TerminalAuthority;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Sales\SaleLocks;
use Flexipos\Sales\Sales\SaleStatus;
use Flexipos\Sales\Sales\SaleType;
use Flexipos\Sales\Support\BusinessClock;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Support\TenantConnection;
use Illuminate\Support\Str;

/**
 * Open a draft sale (T-030 AC-1; D-22, D-23; Ruling 7).
 *
 * **Everything that identifies the sale is the server's.** The branch and the
 * location come from the terminal (`TerminalAuthority` for the branch, the
 * terminal row for the location), the currency from the branch, the business
 * date from the branch's own timezone ({@see BusinessClock}), and the shift from
 * the terminal's open one. The caller names a terminal, optionally a customer
 * and notes, and nothing else: a date, a `[SYNC]` field, a bill discount or a
 * status is refused as an unknown field (AC-8, R-39).
 *
 * **Lock order.** The terminal's open shift id is read before the transaction
 * (a plain read); the transaction's first statement locks that shift
 * `sharedLock()` **by id** and checks again that it is open and the terminal's,
 * so a `CloseCashShift` that committed in between leaves `no_open_shift` and no
 * draft on the closed shift (R-5). The customer is read `FOR UPDATE` after it
 * (Ruling 7, row 6), so a customer merged or deleted a moment ago is not
 * attached.
 */
final class CreateSaleHandler
{
    public const ACTION = 'sales_sale_created';

    private const FIELDS = ['terminal_id', 'customer_id', 'notes'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly BusinessClock $business,
        private readonly AuditTrail $audit,
        private readonly TerminalAuthority $terminals,
        private readonly EmployeeDirectory $employees,
    ) {}

    public function handle(CreateSale $command): CreateSaleResult
    {
        Fields::only($command->fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A sale is opened by an employee.');
        }

        $terminalId = Identifier::fold($command->fields['terminal_id'] ?? null, 'terminal_id');
        $customerId = Identifier::foldNullable($command->fields['customer_id'] ?? null, 'customer_id');
        $notes = Fields::notes($command->fields, 'notes');
        $employeeId = Identifier::fold($command->actorId, 'actor');

        $branchId = $this->terminals->activeBranchOf($terminalId);

        if ($branchId === null) {
            throw SalesRefused::notFound("No active terminal has id [{$terminalId}].");
        }

        $branchId = strtoupper($branchId);

        if (! $this->employees->isLive($employeeId)) {
            throw SalesRefused::notFound("No employee has id [{$employeeId}].");
        }

        $connection = $this->tenant->get();

        $terminal = Lookup::live($connection, 'terminals', $terminalId, 'terminal');
        $branch = Lookup::live($connection, 'branches', $branchId, 'branch');

        $shiftId = SaleLocks::openShiftIdOf($connection, $terminalId)
            ?? throw SalesRefused::because(SalesRefusal::NoOpenShift, "Terminal [{$terminalId}] has no open cash shift.");

        $businessDate = $this->business->businessDate((string) $branch->timezone);
        $currency = strtoupper((string) $branch->currency);
        $locationId = strtoupper((string) $terminal->location_id);

        return $connection->transaction(function () use ($connection, $terminalId, $branchId, $locationId, $shiftId, $employeeId, $customerId, $notes, $businessDate, $currency): CreateSaleResult {
            SaleLocks::lockOpenShift($connection, $shiftId, $terminalId);

            $customer = null;

            if ($customerId !== null) {
                $customer = Lookup::live($connection, 'customers', $customerId, 'customer', true);

                if ($customer->merged_into_id !== null) {
                    throw SalesRefused::notFound("No customer has id [{$customerId}].");
                }
            }

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            $connection->table('sales')->insert([
                'id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
                'terminal_id' => $terminalId,
                'branch_id' => $branchId,
                'location_id' => $locationId,
                'shift_id' => $shiftId,
                'employee_id' => $employeeId,
                'customer_id' => $customerId,
                'customer_name' => $customer === null ? null : $customer->name,
                'customer_phone' => $customer === null ? null : $customer->phone,
                'customer_tax_no' => $customer === null ? null : $customer->tax_no,
                'type' => SaleType::Sale->value,
                'status' => SaleStatus::Draft->value,
                'business_date' => $businessDate,
                'business_date_source' => 'server',
                'currency' => $currency,
                'subtotal' => Money::ZERO,
                'discount_total' => Money::ZERO,
                'tax_total' => Money::ZERO,
                'service_charge' => Money::ZERO,
                'rounding' => Money::ZERO,
                'total' => Money::ZERO,
                'paid_total' => Money::ZERO,
                'change_due' => Money::ZERO,
                'balance_due' => Money::ZERO,
                'notes' => $notes,
            ]);

            $row = $connection->table('sales')->where('id', $id)->first();

            $this->audit->record(SalesAuditActions::MODULE, $employeeId, self::ACTION, 'sale', $id, null, $row === null ? null : (array) $row, $branchId);

            return new CreateSaleResult($id, $shiftId, $businessDate);
        });
    }
}
