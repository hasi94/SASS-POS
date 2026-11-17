import { TestBed } from '@angular/core/testing';
import { ApiError, ApplicableDiscount, SalesApi, branchState } from '@flexipos/shared';
import { Observable, firstValueFrom, of, throwError } from 'rxjs';
import type { MockInstance } from 'vitest';
import { ApprovalFlow, ApprovalOutcome } from '../../state/approval-flow';
import { DialogHost } from '../../state/dialog-host';
import { SaleStore } from '../../state/sale-store';
import {
  allById,
  byId,
  clickId,
  serverSales,
  settleFully,
  storeOf,
} from '../../testing/sale-screen-helpers';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from '../../testing/till-harness';

const seedId = (tag: string, n: number): string => `01JMCK${tag}${String(n).padStart(19, '0')}`;
const MANAGER_DISCOUNT = seedId('D', 2);

async function openPicker(
  given: string[],
  answer?: ApprovalOutcome | 'approved-from-server',
  list?: Observable<ApplicableDiscount[]>,
): Promise<{ h: TillHarness; ask: MockInstance<ApprovalFlow['requestAndDecide']> }> {
  const h = await setupTill({ given: ['shift_open', ...given] });
  const store = storeOf(h, SaleStore);
  store.load(serverSales(h, HARNESS_TENANT_ID, 'BR001')[0]!);
  store.select(store.lines()[0]!.id);
  const approvals = branchState(h.state, HARNESS_TENANT_ID, 'BR001').approvals;
  const outcome: ApprovalOutcome =
    answer === undefined
      ? { kind: 'cancelled' }
      : answer === 'approved-from-server'
        ? { kind: 'approved', approvalId: approvals[0]!.data.id }
        : answer;
  // A stub of the approval flow (step A2 builds the real one).
  const ask = vi.spyOn(storeOf(h, ApprovalFlow), 'requestAndDecide').mockResolvedValue(outcome);
  if (list) {
    vi.spyOn(TestBed.inject(SalesApi), 'applicableDiscounts').mockReturnValue(list);
  }
  await h.settle();
  await clickId(h, 'action-discount');
  await settleFully(h);
  return { h, ask };
}

describe('DiscountPickerDialog (AC-35)', () => {
  it('lists the discounts the server offers for the line, marking the one that needs a supervisor', async () => {
    const { h } = await openPicker(['draft_sale_with_line']);
    expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Discount');
    expect(h.text('discount-product')).toBe('Rice 1kg');
    const rows = allById(h, 'discount-list')[0]?.querySelectorAll('button') ?? [];
    expect(Array.from(rows).map((b) => b.querySelector('.name')?.textContent?.trim())).toEqual([
      '10% off',
      'Manager 20% off',
      'Used-up 15% off',
    ]);
    expect(byId(h, 'discount-MGR20').textContent).toContain('Needs a supervisor');
    expect(byId(h, 'discount-SAVE10').textContent).not.toContain('Needs a supervisor');
    expect(document.activeElement).toBe(byId(h, 'discount-SAVE10'));
  });

  it('applies a discount that needs no approval with its id, without asking a supervisor', async () => {
    const { h, ask } = await openPicker(['draft_sale_with_line']);
    const update = vi.spyOn(TestBed.inject(SalesApi), 'updateLine');
    await clickId(h, 'discount-SAVE10');
    await settleFully(h);
    expect(ask).not.toHaveBeenCalled();
    expect(update.mock.calls[0]?.[2]).toEqual({ discount_id: seedId('D', 1) });
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(h.text('line-discount')).toBe('Discount 25.00 LKR');
    expect(h.text('line-total')).toBe('225.00 LKR');
    expect(document.activeElement).toBe(byId(h, 'scan-field'));
  });

  it('asks for a discount approval bound to the discount first when the server says it must', async () => {
    const { h, ask } = await openPicker(
      ['draft_sale_with_line', 'approved_discount'],
      'approved-from-server',
    );
    const update = vi.spyOn(TestBed.inject(SalesApi), 'updateLine');
    await clickId(h, 'discount-MGR20');
    await settleFully(h);
    const sale = serverSales(h, HARNESS_TENANT_ID, 'BR001')[0]!;
    expect(ask.mock.calls[0]?.[0]).toEqual({
      type: 'discount',
      reference_type: 'sale',
      reference_id: sale.id,
      payload: { discount_id: MANAGER_DISCOUNT },
    });
    const approvalId = branchState(h.state, HARNESS_TENANT_ID, 'BR001').approvals[0]!.data.id;
    expect(update.mock.calls[0]?.[2]).toEqual({
      discount_id: MANAGER_DISCOUNT,
      approval_id: approvalId,
    });
    expect(h.text('line-discount')).toBe('Discount 50.00 LKR');
    expect(h.query('[role="dialog"]')).toBeNull();
  });

  it('says "Approval rejected" and changes nothing when the supervisor rejects', async () => {
    const { h } = await openPicker(['draft_sale_with_line'], { kind: 'rejected' });
    const update = vi.spyOn(TestBed.inject(SalesApi), 'updateLine');
    await clickId(h, 'discount-MGR20');
    await settleFully(h);
    expect(update).not.toHaveBeenCalled();
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(h.text('message-bar')).toContain('Approval rejected');
    expect(h.query('[data-testid="line-discount"]')).toBeNull();
  });

  it('stays open and sends nothing when the supervisor dialog is cancelled', async () => {
    const { h } = await openPicker(['draft_sale_with_line'], { kind: 'cancelled' });
    const update = vi.spyOn(TestBed.inject(SalesApi), 'updateLine');
    await clickId(h, 'discount-MGR20');
    await settleFully(h);
    expect(update).not.toHaveBeenCalled();
    expect(h.query('[role="dialog"]')).not.toBeNull();
  });

  it('asks for approval when the server answers approval_required to a discount it listed as free', async () => {
    const unlisted: ApplicableDiscount = {
      id: MANAGER_DISCOUNT,
      code: 'MGR20',
      name: 'Manager 20% off',
      requires_approval: false,
      description: 'Listed without the flag',
    };
    const { h, ask } = await openPicker(
      ['draft_sale_with_line', 'approved_discount'],
      'approved-from-server',
      of([unlisted]),
    );
    const update = vi.spyOn(TestBed.inject(SalesApi), 'updateLine');
    await clickId(h, 'discount-MGR20');
    await settleFully(h);
    expect(update).toHaveBeenCalledTimes(2);
    expect(update.mock.calls[0]?.[2]).toEqual({ discount_id: MANAGER_DISCOUNT });
    expect(update.mock.calls[1]?.[2].approval_id).toBeTruthy();
    expect(ask).toHaveBeenCalledTimes(1);
    expect(h.text('line-discount')).toBe('Discount 50.00 LKR');
    // The first refusal was handled by asking, not shown.
    expect(h.query('[data-testid="message-bar"]')?.textContent ?? '').not.toContain(
      'supervisor must approve',
    );
  });

  it('offers to remove a discount the line already has', async () => {
    const { h } = await openPicker(['draft_sale_with_discounted_line']);
    expect(h.query('[data-testid="discount-remove"]')).not.toBeNull();
    await clickId(h, 'discount-remove');
    await settleFully(h);
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(h.query('[data-testid="line-discount"]')).toBeNull();
    expect(storeOf(h, SaleStore).lines()[0]?.discount_id).toBeNull();
    expect(serverSales(h, HARNESS_TENANT_ID, 'BR001')[0]?.lines[0]?.discount_id).toBeNull();
  });

  it('is disabled with the reason on a line with a price override, and the dialog says so too', async () => {
    const h = await setupTill({
      given: ['shift_open', 'draft_sale_with_poster_line', 'approved_price_override'],
    });
    const sale = serverSales(h, HARNESS_TENANT_ID, 'BR001')[0]!;
    const approvalId = branchState(h.state, HARNESS_TENANT_ID, 'BR001').approvals[0]!.data.id;
    const overridden = await firstValueFrom(
      TestBed.inject(SalesApi).updateLine(sale.id, sale.lines[0]!.id, {
        unit_price: '1200.0000' as never,
        approval_id: approvalId,
      }),
    );
    const store = storeOf(h, SaleStore);
    store.load(overridden);
    store.select(store.lines()[0]!.id);
    await h.settle();
    expect(byId<HTMLButtonElement>(h, 'action-discount').disabled).toBe(true);
    expect(h.text('line-reason')).toBe('A line with a price override cannot take a discount');
    // Opened anyway, the dialog lists nothing and says the same.
    const applicable = vi.spyOn(TestBed.inject(SalesApi), 'applicableDiscounts');
    void storeOf(h, DialogHost).open('discount-picker', { lineId: store.lines()[0]!.id }, null);
    await settleFully(h);
    expect(h.text('discount-blocked')).toBe('A line with a price override cannot take a discount');
    expect(applicable).not.toHaveBeenCalled();
  });

  it('shows what went wrong when the list cannot be read, and keeps the till usable', async () => {
    const { h } = await openPicker(
      ['draft_sale_with_line'],
      undefined,
      throwError(() => new ApiError(500, 'Server error', [], 'req-9')),
    );
    expect(h.text('discount-error')).toBe('Something went wrong');
    expect(h.query('[role="dialog"]')).not.toBeNull();
    h.press('Escape');
    await h.settle();
    expect(h.query('[role="dialog"]')).toBeNull();
  });
});
