<?php

/*
 * Seed the chart of accounts and the first fiscal year (T-032 AC-26 to
 * AC-30, plan Ruling 8, D-6, D-8, D-23, D-27).
 *
 * **What it writes.**
 *
 * - The twenty accounts in {@see self::ROWS}, parents before children. A row
 *   is **skipped** when any account, live or soft-deleted, already holds its
 *   code (a tenant's own chart wins, and the code is reserved either way), or
 *   when it is a single-holder control type (ar, ap, grni, payroll_payable,
 *   retained_earnings) that a live account already holds. A skipped row is
 *   remembered as a parent **only** when it is a live header of the same type;
 *   otherwise its subtree is skipped too. Nothing is ever updated, so a seeded
 *   account the tenant renamed keeps its new name.
 * - One fiscal year for the **current UTC calendar year**, named `FY<year>`,
 *   January to December, twelve open periods `FY<year> P01` to `P12`, but only
 *   when `fiscal_years` is empty.
 *
 * **Why a migration seeds data** (the T-029 `backfill_product_tax_groups`
 * precedent). `seed_core` runs after the migrations, so a seed there would
 * reach new tenants only; a backfill migration serves a fresh tenant and an
 * upgraded one with the same file. It writes **no audit rows** (D-27): a
 * migration is not an edit by anyone, so `created_by` is null.
 *
 * **Retry-safe.** It runs in one transaction and every row is guarded by the
 * rules above, so a re-run changes nothing and a partial run resumes.
 *
 * **Two homes for the rules, and a test between them.** The row list and the
 * calendar below are inline, because a migration must not depend on
 * application code that may change after it ran. `SeedChartTest` pushes every
 * row through the API's own validators and compares the periods with
 * `FiscalCalendar`, so neither copy can drift.
 *
 * The control-type list is a third copy of `ControlType::isSingle()`;
 * `SeedChartTest` asserts it equals the contract's.
 *
 * @phase backfill
 */

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The control types that may have only one live holder.
     *
     * @var list<string>
     */
    public const SINGLE_CONTROL = ['ar', 'ap', 'grni', 'payroll_payable', 'retained_earnings'];

    /**
     * `[code, name, type, subtype, control_type, is_header, parent_code]`,
     * parents before children (plan section 4).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: ?string, 4: string, 5: bool, 6: ?string}>
     */
    public const ROWS = [
        ['1000', 'Assets', 'asset', null, 'none', true, null],
        ['1100', 'Cash', 'asset', 'cash', 'cash', false, '1000'],
        ['1200', 'Bank', 'asset', 'bank', 'bank', false, '1000'],
        ['1300', 'Inventory', 'asset', 'inventory', 'inventory', false, '1000'],
        ['1400', 'Accounts Receivable', 'asset', 'receivable', 'ar', false, '1000'],
        ['2000', 'Liabilities', 'liability', null, 'none', true, null],
        ['2100', 'Supplier Payables', 'liability', 'payable', 'ap', false, '2000'],
        ['2200', 'Loans', 'liability', 'long_term_liability', 'none', false, '2000'],
        ['2300', 'Tax Payable', 'liability', 'tax', 'tax_payable', false, '2000'],
        ['2400', 'Goods Received Not Invoiced', 'liability', 'current_liability', 'grni', false, '2000'],
        ['2500', 'Payroll Payable', 'liability', 'current_liability', 'payroll_payable', false, '2000'],
        ['3000', 'Equity', 'equity', null, 'none', true, null],
        ['3100', 'Retained Earnings', 'equity', 'retained_earnings', 'retained_earnings', false, '3000'],
        ['4000', 'Revenue', 'revenue', null, 'none', true, null],
        ['4100', 'Sales', 'revenue', 'sales', 'none', false, '4000'],
        ['5000', 'Cost of Goods Sold', 'expense', 'cogs', 'none', false, null],
        ['6000', 'Expenses', 'expense', null, 'none', true, null],
        ['6100', 'Rent', 'expense', 'operating_expense', 'none', false, '6000'],
        ['6200', 'Salary', 'expense', 'payroll_expense', 'none', false, '6000'],
        ['6300', 'Electricity', 'expense', 'operating_expense', 'none', false, '6000'],
    ];

    /**
     * The row list, as a method so a reader (and the type checker) sees it as
     * data and not as the literal it happens to be today.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: ?string, 4: string, 5: bool, 6: ?string}>
     */
    private function rows(): array
    {
        return self::ROWS;
    }

    public function up(): void
    {
        $connection = Schema::getConnection();

        $connection->transaction(function () use ($connection): void {
            $now = app(RunnerClock::class)->now();
            $stamp = $now->format(RunnerClock::PRECISION);

            // code => [id, path, depth] of every row that may take children.
            /** @var array<string, array{0: string, 1: string, 2: int}> $parents */
            $parents = [];

            foreach ($this->rows() as [$code, $name, $type, $subtype, $control, $header, $parentCode]) {
                $parent = null;

                if ($parentCode !== null) {
                    if (! isset($parents[$parentCode])) {
                        continue;
                    }

                    $parent = $parents[$parentCode];
                }

                $existing = $connection->table('accounts')->where('code', $code)->first();

                if ($existing !== null) {
                    if ($existing->deleted_at === null && ! (bool) $existing->is_postable && (string) $existing->type === $type) {
                        $parents[$code] = [(string) $existing->id, (string) $existing->path, (int) $existing->depth];
                    }

                    continue;
                }

                if (in_array($control, self::SINGLE_CONTROL, true)
                    && $connection->table('accounts')->where('control_type', $control)->whereNull('deleted_at')->exists()) {
                    continue;
                }

                $id = strtoupper((string) Str::ulid());
                $path = $parent === null ? $id : $parent[1].'/'.$id;
                $depth = $parent === null ? 0 : $parent[2] + 1;

                $connection->table('accounts')->insert([
                    'id' => $id,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                    'deleted_at' => null,
                    'created_by' => null,
                    'updated_by' => null,
                    'code' => $code,
                    'name' => $name,
                    'description' => null,
                    'type' => $type,
                    'subtype' => $subtype,
                    'parent_id' => $parent === null ? null : $parent[0],
                    'path' => $path,
                    'depth' => $depth,
                    'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit',
                    'is_postable' => ! $header,
                    'is_system' => $control !== 'none',
                    'control_type' => $control,
                    'currency' => null,
                    'is_active' => true,
                    'sort_order' => 0,
                    'opening_balance' => null,
                    'opening_balance_date' => null,
                ]);

                if ($header) {
                    $parents[$code] = [$id, $path, $depth];
                }
            }

            if ($connection->table('fiscal_years')->exists()) {
                return;
            }

            $year = (int) $now->setTimezone('UTC')->format('Y');
            $yearId = strtoupper((string) Str::ulid());

            $connection->table('fiscal_years')->insert([
                'id' => $yearId,
                'created_at' => $stamp,
                'updated_at' => $stamp,
                'name' => 'FY'.$year,
                'start_date' => sprintf('%04d-01-01', $year),
                'end_date' => sprintf('%04d-12-31', $year),
                'status' => 'open',
                'closed_at' => null,
                'closed_by' => null,
                'retained_earnings_entry_id' => null,
            ]);

            for ($month = 1; $month <= 12; $month++) {
                $first = sprintf('%04d-%02d-01', $year, $month);

                $connection->table('fiscal_periods')->insert([
                    'id' => strtoupper((string) Str::ulid()),
                    'fiscal_year_id' => $yearId,
                    'sequence' => $month,
                    'name' => sprintf('FY%d P%02d', $year, $month),
                    'start_date' => $first,
                    'end_date' => gmdate('Y-m-t', (int) strtotime($first.' 00:00:00 UTC')),
                    'status' => 'open',
                    'closed_at' => null,
                    'closed_by' => null,
                ]);
            }
        });
    }
};
