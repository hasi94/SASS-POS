import { TestBed } from '@angular/core/testing';
import { ApiError, SalesApi, asQuantity, branchState } from '@flexipos/shared';
import { of, throwError } from 'rxjs';
import { SaleStore } from '../../state/sale-store';
import { ShiftStore } from '../../state/shift-store';
import { allById, byId, clickId, storeOf, typeInto } from '../../testing/sale-screen-helpers';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from '../../testing/till-harness';

async function openMenu(h: TillHarness): Promise<void> {
  h.press('F9');
  await h.settle();
}

function branchOf(h: TillHarness) {
  return branchState(h.state, HARNESS_TENANT_ID, 'BR001');
}

async function fillMovement(h: TillHarness, amount: string, reason: string): Promise<void> {
  await clickId(h, 'menu-movement');
  typeInto(byId<HTMLInputElement>(h, 'movement-amount'), amount);
  typeInto(byId<HTMLInputElement>(h, 'movement-reason'), reason);
}

function submitForm(h: TillHarness, testId: string): void {
  byId(h, testId)
    .closest('form')
    ?.dispatchEvent(new Event('submit', { cancelable: true }));
}

async function tryToClose(h: TillHarness, counted = '5000'): Promise<void> {
  await openMenu(h);
  await clickId(h, 'menu-close');
  typeInto(byId<HTMLInputElement>(h, 'counted-cash'), counted);
  submitForm(h, 'counted-cash');
  await h.settle();
}

describe('ShiftMenuDialog: cash movements (AC-12)', () => {
  it('shows the sign of each type as an "adds to / takes from drawer" hint', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    await openMenu(h);
    await clickId(h, 'menu-movement');
    const hints: Record<string, string> = {
      cash_in: 'Adds to the drawer',
      cash_out: 'Takes from the drawer',
      float_in: 'Adds to the drawer',
      float_out: 'Takes from the drawer',
      drop: 'Takes from the drawer',
      petty_cash: 'Takes from the drawer',
      bank_deposit: 'Takes from the drawer',
    };
    const select = byId<HTMLSelectElement>(h, 'movement-type');
    expect(Array.from(select.options).map((o) => o.value)).toEqual(Object.keys(hints));
    for (const [type, hint] of Object.entries(hints)) {
      select.value = type;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      await h.settle();
      expect(h.text('movement-hint'), type).toBe(hint);
    }
  });

  it('records a movement with a client id and the positive amount as typed', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    await openMenu(h);
    await fillMovement(h, '250.5', 'Float top-up');
    submitForm(h, 'movement-amount');
    await h.settle();
    const movements = branchOf(h).movements;
    expect(movements).toHaveLength(1);
    expect(movements[0]?.data.id).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
    expect(movements[0]?.data.type).toBe('cash_in');
    expect(movements[0]?.data.amount).toBe('250.5000');
    expect(h.text('menu-movement')).toContain('Record a cash movement');
  });

  it.each([
    ['', 'x', 'Amount is required'],
    ['-5', 'x', 'Amount cannot be negative'],
    ['0', 'x', 'Amount must be more than zero'],
    ['1.23456', 'x', 'Amount can have at most 4 decimal places'],
    ['10', '  ', 'Reason is required'],
  ])('refuses amount "%s" with reason "%s" before any call', async (amount, reason, message) => {
    const h = await setupTill({ given: ['shift_open'] });
    const record = vi.spyOn(TestBed.inject(SalesApi), 'recordMovement');
    await openMenu(h);
    await fillMovement(h, amount, reason);
    submitForm(h, 'movement-amount');
    await h.settle();
    expect(h.text('shift-form-error')).toBe(message);
    expect(record).not.toHaveBeenCalled();
  });

  it('retries a lost submit with the same id, so the movement is recorded once', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const record = vi.spyOn(TestBed.inject(SalesApi), 'recordMovement');
    await openMenu(h);
    await fillMovement(h, '40', 'Petty');
    h.state.faults.dropAnswerOf.push('shifts.movement');
    submitForm(h, 'movement-amount');
    await h.settle();
    expect(branchOf(h).movements).toHaveLength(1);
    expect(h.text('message-bar')).toContain('Cannot reach the server');
    submitForm(h, 'movement-amount');
    await h.settle();
    expect(branchOf(h).movements).toHaveLength(1);
    const ids = record.mock.calls.map((call) => call[1].id);
    expect(ids).toHaveLength(2);
    expect(ids[1]).toBe(ids[0]);
  });

  it('gives a different movement a different id', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const record = vi.spyOn(TestBed.inject(SalesApi), 'recordMovement');
    await openMenu(h);
    await fillMovement(h, '40', 'First');
    submitForm(h, 'movement-amount');
    await h.settle();
    await fillMovement(h, '40', 'Second');
    submitForm(h, 'movement-amount');
    await h.settle();
    expect(branchOf(h).movements).toHaveLength(2);
    const ids = record.mock.calls.map((call) => call[1].id);
    expect(ids[1]).not.toBe(ids[0]);
  });

  it('gives an amount changed after a failed submit a new id, not one the server would call a conflict', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const record = vi.spyOn(TestBed.inject(SalesApi), 'recordMovement');
    await openMenu(h);
    await fillMovement(h, '40', 'Petty');
    h.state.faults.dropAnswerOf.push('shifts.movement');
    submitForm(h, 'movement-amount');
    await h.settle();
    typeInto(byId<HTMLInputElement>(h, 'movement-amount'), '45');
    submitForm(h, 'movement-amount');
    await h.settle();
    const ids = record.mock.calls.map((call) => call[1].id);
    expect(ids[1]).not.toBe(ids[0]);
    expect(h.query('[data-testid="message-bar"]')?.textContent ?? '').not.toContain('conflicts');
  });
});

describe('ShiftMenuDialog: closing (AC-13, AC-14)', () => {
  it('asks for the count without showing the expected cash, then shows the server figures', async () => {
    const h = await setupTill({ given: ['shift_open', 'cash_movement_recorded'] });
    await openMenu(h);
    await clickId(h, 'menu-close');
    const dialog = h.query('[role="dialog"]')?.textContent ?? '';
    expect(dialog).toContain('Counted cash');
    expect(dialog).not.toContain('5,500');
    typeInto(byId<HTMLInputElement>(h, 'counted-cash'), '5400');
    submitForm(h, 'counted-cash');
    await h.settle();
    expect(h.text('expected-cash')).toBe('5,500.00');
    expect(h.text('counted-figure')).toBe('5,400.00');
    expect(h.text('variance')).toBe('-100.00');
    expect(h.text('sales-count')).toBe('0');
    expect(h.text('refunds-count')).toBe('0');
    expect(h.text('net')).toBe('0.00');
    expect(storeOf(h, ShiftStore).shiftOpen()).toBe(false);
    expect(h.query('[data-testid="shift-open-prompt"]')).not.toBeNull();
  });

  it('refuses an empty or malformed count in the form', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const close = vi.spyOn(TestBed.inject(SalesApi), 'closeShift');
    await openMenu(h);
    await clickId(h, 'menu-close');
    submitForm(h, 'counted-cash');
    await h.settle();
    expect(h.text('shift-form-error')).toBe('Counted cash is required');
    typeInto(byId<HTMLInputElement>(h, 'counted-cash'), '12x');
    submitForm(h, 'counted-cash');
    await h.settle();
    expect(h.text('shift-form-error')).toBe('Counted cash must be a number such as 12.50');
    expect(close).not.toHaveBeenCalled();
  });

  it('lists the draft and part-paid sales when the shift cannot close, and not a held one', async () => {
    const h = await setupTill({
      given: ['shift_open', 'draft_sale_with_line', 'pending_payment_sale', 'held_from_draft'],
    });
    await tryToClose(h);
    expect(h.text('message-bar')).toContain('Finish or void the open sales');
    const rows = Array.from(allById(h, 'open-sales')[0]?.querySelectorAll('li') ?? []);
    expect(rows).toHaveLength(2);
    const text = rows.map((r) => r.textContent ?? '');
    expect(text.some((t) => t.includes('Draft'))).toBe(true);
    expect(text.some((t) => t.includes('Part paid'))).toBe(true);
    expect(storeOf(h, ShiftStore).shiftOpen()).toBe(true);
  });

  it('opens a listed sale into the cart', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    await tryToClose(h);
    await clickId(h, 'sale-open');
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(storeOf(h, SaleStore).lineCount()).toBe(1);
  });

  it('sends Complete to the payment dialog, with the sale in the cart', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    await tryToClose(h);
    await clickId(h, 'sale-complete');
    expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Payment');
    expect(storeOf(h, SaleStore).lineCount()).toBe(1);
  });

  it('sends Void to the void dialog, with the sale in the cart', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    await tryToClose(h);
    await clickId(h, 'sale-void');
    expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Void a sale');
    expect(storeOf(h, SaleStore).lineCount()).toBe(1);
  });

  it('holds a listed sale and refreshes the list', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    const stored = branchOf(h).sales[0]!;
    const hold = vi.spyOn(TestBed.inject(SalesApi), 'holdSale').mockImplementation(() => {
      stored.data.status = 'held';
      stored.data.held_from_status = 'draft';
      return of(stored.data);
    });
    await tryToClose(h);
    expect(allById(h, 'open-sales')[0]?.querySelectorAll('li')).toHaveLength(1);
    await clickId(h, 'sale-hold');
    expect(hold).toHaveBeenCalledWith(stored.data.id);
    expect(h.query('[data-testid="no-open-sales"]')).not.toBeNull();
  });

  it('clears the cart when the sale held from the list is the one in the cart', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    const stored = branchOf(h).sales[0]!;
    storeOf(h, SaleStore).load(stored.data);
    vi.spyOn(TestBed.inject(SalesApi), 'holdSale').mockImplementation(() => {
      stored.data.status = 'held';
      stored.data.held_from_status = 'draft';
      return of(stored.data);
    });
    await tryToClose(h);
    await clickId(h, 'sale-hold');
    expect(storeOf(h, SaleStore).current()).toBeNull();
  });

  it('closes once the open sales are dealt with', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    await tryToClose(h);
    branchOf(h).sales[0]!.data.status = 'voided';
    const outcome = await storeOf(h, ShiftStore).closeShift('5000');
    expect(outcome.kind).toBe('closed');
  });
});

describe('the shift is gone (AC-15)', () => {
  const refused = (code: string) => new ApiError(403, 'refused', [{ code, field: null }]);

  it.each(['sales.no_open_shift', 'sales.shift_not_open'])(
    'routes %s to the prompt and keeps the cart on show',
    async (code) => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      const sale = storeOf(h, SaleStore);
      const stored = branchOf(h).sales[0]!.data;
      sale.load(stored);
      await h.settle();
      expect(h.host.querySelectorAll('[data-testid="cart-line"]')).toHaveLength(1);
      vi.spyOn(TestBed.inject(SalesApi), 'addLine').mockReturnValue(
        throwError(() => refused(code)),
      );
      await sale.addLine({
        productId: stored.lines[0]!.product_id,
        unitId: stored.lines[0]!.unit_id,
        quantity: asQuantity('1'),
      });
      await h.settle();
      expect(h.query('[data-testid="shift-open-prompt"]')).not.toBeNull();
      expect(h.text('shift')).toBe('No open shift');
      expect(h.host.querySelectorAll('[data-testid="cart-line"]')).toHaveLength(1);
      expect(sale.lineCount()).toBe(1);
    },
  );

  it('does not start a sale when there is no shift', async () => {
    const h = await setupTill();
    const outcome = await storeOf(h, SaleStore).addLine({
      productId: 'x',
      unitId: 'y',
      quantity: asQuantity('1'),
    });
    expect(outcome.ok).toBe(false);
    await h.settle();
    expect(branchOf(h).sales).toHaveLength(0);
    expect(h.text('message-bar')).toContain('Open a shift first');
  });
});
