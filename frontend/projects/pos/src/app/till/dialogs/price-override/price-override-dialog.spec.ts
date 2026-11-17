import { TestBed } from '@angular/core/testing';
import { SalesApi, branchState } from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import type { MockInstance } from 'vitest';
import { ApprovalFlow, ApprovalOutcome, MAX_APPROVAL_ATTEMPTS } from '../../state/approval-flow';
import { SaleStore } from '../../state/sale-store';
import {
  byId,
  clickId,
  serverSales,
  settleFully,
  storeOf,
  typeInto,
} from '../../testing/sale-screen-helpers';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from '../../testing/till-harness';

const seedId = (tag: string, n: number): string => `01JMCK${tag}${String(n).padStart(19, '0')}`;
const POSTER = seedId('P', 5);
const EACH = seedId('N', 1);

/** The cart holds the poster (override allowed), selected, with the override dialog open. */
async function openOverride(
  outcome: ApprovalOutcome | 'approved-from-server',
  extraGiven: string[] = ['approved_price_override'],
): Promise<{ h: TillHarness; ask: MockInstance<ApprovalFlow['requestAndDecide']> }> {
  const h = await setupTill({
    given: ['shift_open', 'draft_sale_with_poster_line', ...extraGiven],
  });
  const store = storeOf(h, SaleStore);
  store.load(serverSales(h, HARNESS_TENANT_ID, 'BR001')[0]!);
  store.select(store.lines()[0]!.id);
  await h.settle();
  // A stub of the approval flow (step A2 builds the real one): it answers what the spec says.
  const approvals = branchState(h.state, HARNESS_TENANT_ID, 'BR001').approvals;
  const answer: ApprovalOutcome =
    outcome === 'approved-from-server'
      ? { kind: 'approved', approvalId: approvals[0]!.data.id }
      : outcome;
  const ask = vi.spyOn(storeOf(h, ApprovalFlow), 'requestAndDecide').mockResolvedValue(answer);
  await clickId(h, 'action-override');
  await settleFully(h);
  return { h, ask };
}

async function submitPrice(h: TillHarness, text: string): Promise<void> {
  typeInto(byId<HTMLInputElement>(h, 'override-price'), text);
  byId(h, 'override-price')
    .closest('form')
    ?.dispatchEvent(new Event('submit', { cancelable: true }));
  await settleFully(h);
}

describe('PriceOverrideDialog (AC-34)', () => {
  it('asks for an approval bound to {product_id, unit_id, unit_price} on this sale, then updates with its id', async () => {
    const { h, ask } = await openOverride('approved-from-server');
    const update = vi.spyOn(TestBed.inject(SalesApi), 'updateLine');
    expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Price override');
    expect(document.activeElement).toBe(byId(h, 'override-price'));
    await submitPrice(h, '1200.0000');
    const sale = serverSales(h, HARNESS_TENANT_ID, 'BR001')[0]!;
    expect(ask).toHaveBeenCalledTimes(1);
    expect(ask.mock.calls[0]?.[0]).toEqual({
      type: 'price_override',
      reference_type: 'sale',
      reference_id: sale.id,
      payload: { product_id: POSTER, unit_id: EACH, unit_price: '1200.0000' },
    });
    const approvalId = branchState(h.state, HARNESS_TENANT_ID, 'BR001').approvals[0]!.data.id;
    expect(update.mock.calls[0]?.[2]).toEqual({ unit_price: '1200.0000', approval_id: approvalId });
    expect(h.query('[role="dialog"]')).toBeNull();
    const line = storeOf(h, SaleStore).lines()[0]!;
    expect(line.unit_price).toBe('1200.0000');
    expect(line.price_overridden).toBe(true);
    expect(h.text('price-overridden')).toBe('Price changed');
    expect(
      branchState(h.state, HARNESS_TENANT_ID, 'BR001').approvals[0]?.data.consumed_at,
    ).not.toBeNull();
  });

  it('sends the price as typed, not normalised', async () => {
    const { h } = await openOverride('approved-from-server');
    const update = vi.spyOn(TestBed.inject(SalesApi), 'updateLine');
    await submitPrice(h, ' 1200 ');
    expect(update.mock.calls[0]?.[2].unit_price).toBe('1200');
  });

  it.each([
    ['', 'Unit price is required'],
    ['abc', 'Unit price must be a number such as 12.50'],
    ['-5', 'Unit price cannot be negative'],
    ['1.23456', 'Unit price can have at most 4 decimal places'],
  ])('refuses "%s" in the form before any approval is asked for', async (text, message) => {
    const { h, ask } = await openOverride('approved-from-server');
    await submitPrice(h, text);
    expect(h.text('override-error')).toBe(message);
    expect(ask).not.toHaveBeenCalled();
  });

  it('says "Approval rejected", closes, and changes nothing when the supervisor rejects', async () => {
    const { h } = await openOverride({ kind: 'rejected' });
    const update = vi.spyOn(TestBed.inject(SalesApi), 'updateLine');
    await submitPrice(h, '1200.0000');
    expect(update).not.toHaveBeenCalled();
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(h.text('message-bar')).toContain('Approval rejected');
    expect(storeOf(h, SaleStore).lines()[0]?.unit_price).toBe('1500.0000');
  });

  it('stays open and changes nothing when the supervisor dialog is cancelled', async () => {
    const { h } = await openOverride({ kind: 'cancelled' });
    const update = vi.spyOn(TestBed.inject(SalesApi), 'updateLine');
    await submitPrice(h, '1200.0000');
    expect(update).not.toHaveBeenCalled();
    expect(h.query('[role="dialog"]')).not.toBeNull();
    expect(storeOf(h, SaleStore).lines()[0]?.unit_price).toBe('1500.0000');
  });

  it('shows price_override_not_allowed and changes nothing', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    const store = storeOf(h, SaleStore);
    store.load(serverSales(h, HARNESS_TENANT_ID, 'BR001')[0]!);
    store.select(store.lines()[0]!.id);
    vi.spyOn(storeOf(h, ApprovalFlow), 'requestAndDecide').mockResolvedValue({
      kind: 'approved',
      approvalId: 'any',
    });
    await h.settle();
    await clickId(h, 'action-override');
    await submitPrice(h, '10');
    expect(h.text('message-bar')).toContain('A price override is not allowed for this product');
    expect(store.lines()[0]?.unit_price).toBe('250.0000');
    expect(h.query('[role="dialog"]')).not.toBeNull();
  });

  it('shows discount_with_override and changes nothing for a line that has a discount', async () => {
    const { h } = await openOverride('approved-from-server');
    h.press('Escape');
    await h.settle();
    const sale = serverSales(h, HARNESS_TENANT_ID, 'BR001')[0]!;
    // Put a discount on the poster behind the till's back, then reload the cart.
    await firstValueFrom(
      TestBed.inject(SalesApi).updateLine(sale.id, sale.lines[0]!.id, {
        discount_id: seedId('D', 1),
      }),
    );
    await storeOf(h, SaleStore).reload();
    await h.settle();
    await clickId(h, 'action-override');
    await submitPrice(h, '1200.0000');
    expect(h.text('message-bar')).toContain('A line with a price override cannot take a discount');
    expect(storeOf(h, SaleStore).lines()[0]?.price_overridden).toBe(false);
  });

  it('asks again at once when the approval it got is stale, and applies the price with the new one (AC-70)', async () => {
    const { h, ask } = await openOverride({ kind: 'approved', approvalId: 'not-an-approval' });
    const good = branchState(h.state, HARNESS_TENANT_ID, 'BR001').approvals[0]!.data.id;
    ask
      .mockResolvedValueOnce({ kind: 'approved', approvalId: 'not-an-approval' })
      .mockResolvedValueOnce({ kind: 'approved', approvalId: good });
    await submitPrice(h, '1200.0000');
    expect(ask).toHaveBeenCalledTimes(2);
    expect(storeOf(h, SaleStore).lines()[0]?.unit_price).toBe('1200.0000');
    expect(h.query('[role="dialog"]')).toBeNull();
  });

  it('gives up after three stale approvals, says a new one is needed, and a later submit asks again', async () => {
    const { h, ask } = await openOverride({ kind: 'approved', approvalId: 'not-an-approval' });
    await submitPrice(h, '1200.0000');
    expect(ask).toHaveBeenCalledTimes(MAX_APPROVAL_ATTEMPTS);
    expect(h.text('message-bar')).toContain('a new approval is needed');
    expect(h.query('[role="dialog"]')).not.toBeNull();
    expect(storeOf(h, SaleStore).lines()[0]?.unit_price).toBe('1500.0000');
    ask.mockResolvedValue({
      kind: 'approved',
      approvalId: branchState(h.state, HARNESS_TENANT_ID, 'BR001').approvals[0]!.data.id,
    });
    await submitPrice(h, '1200.0000');
    expect(ask).toHaveBeenCalledTimes(MAX_APPROVAL_ATTEMPTS + 1);
    expect(storeOf(h, SaleStore).lines()[0]?.unit_price).toBe('1200.0000');
  });
});
