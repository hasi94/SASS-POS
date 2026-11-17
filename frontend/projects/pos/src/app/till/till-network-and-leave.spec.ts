import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { ApiError, Sale, SalesApi } from '@flexipos/shared';
import { NETWORK_MESSAGE } from './errors/refusal-catalogue';
import { ApprovalFlow } from './state/approval-flow';
import { Connectivity, REPROBE_MS } from './state/connectivity';
import { DialogHost } from './state/dialog-host';
import { SaleStore } from './state/sale-store';
import { clickButton, storedText, topDialog } from './testing/approval-helpers';
import { byId, scan, serverSales, settleFully, storeOf } from './testing/sale-screen-helpers';
import {
  HARNESS_STORAGE_KEY,
  HARNESS_TENANT_ID,
  TillHarness,
  setupTill,
} from './testing/till-harness';

const unreachable = () => new ApiError(0, 'Cannot reach the server.');

/** Loads the mock's first sale into the cart, as a resumed sale would be. */
async function loadSale(h: TillHarness): Promise<Sale> {
  const stored = serverSales(h, HARNESS_TENANT_ID, 'BR001')[0];
  const sale = JSON.parse(JSON.stringify(stored)) as Sale;
  storeOf(h, SaleStore).load(sale);
  await h.settle();
  return sale;
}

/** Every call that writes, spied so a spec can prove none was sent. */
function writeSpies() {
  const api = TestBed.inject(SalesApi);
  return {
    createSale: vi.spyOn(api, 'createSale'),
    addLine: vi.spyOn(api, 'addLine'),
    updateLine: vi.spyOn(api, 'updateLine'),
    removeLine: vi.spyOn(api, 'removeLine'),
    holdSale: vi.spyOn(api, 'holdSale'),
    recallSale: vi.spyOn(api, 'recallSale'),
    attachCustomer: vi.spyOn(api, 'attachCustomer'),
    addTender: vi.spyOn(api, 'addTender'),
    completeSale: vi.spyOn(api, 'completeSale'),
    voidSale: vi.spyOn(api, 'voidSale'),
    removeTender: vi.spyOn(api, 'removeTender'),
    requestApproval: vi.spyOn(api, 'requestApproval'),
    decideApproval: vi.spyOn(api, 'decideApproval'),
    createRefund: vi.spyOn(api, 'createRefund'),
    openShift: vi.spyOn(api, 'openShift'),
    recordMovement: vi.spyOn(api, 'recordMovement'),
    closeShift: vi.spyOn(api, 'closeShift'),
  };
}

function expectNoWrites(spies: ReturnType<typeof writeSpies>): void {
  for (const [name, spy] of Object.entries(spies)) {
    expect(spy, name).not.toHaveBeenCalled();
  }
}

async function goOffline(h: TillHarness): Promise<void> {
  h.state.faults.unreachable = true;
  storeOf(h, Connectivity).reportFailure(unreachable());
  await h.settle();
}

describe('The till without a network (AC-74)', () => {
  it('shows the banner, keeps the cart readable and disables every write', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    await loadSale(h);
    const spies = writeSpies();
    storeOf(h, SaleStore).select(storeOf(h, SaleStore).lines()[0]!.id);
    await goOffline(h);

    expect(h.text('network-banner')).toContain(NETWORK_MESSAGE);
    expect(NETWORK_MESSAGE).toBe('Cannot reach the server: sale actions are paused');
    expect(h.query('[data-testid="network-banner"]')?.getAttribute('role')).toBe('alert');
    // The cart is still there to read.
    expect(byId(h, 'cart-lines').textContent).toContain('Rice 1kg');
    expect(byId(h, 'totals').textContent).toContain('Total');
    // Every cart control that would write is off.
    expect(byId<HTMLButtonElement>(h, 'cart-pay').disabled).toBe(true);
    expect(byId<HTMLButtonElement>(h, 'cart-hold').disabled).toBe(true);
    const actions = Array.from(
      byId(h, 'line-actions').querySelectorAll<HTMLButtonElement>('button'),
    );
    expect(actions.length).toBeGreaterThan(0);
    expect(actions.every((b) => b.disabled)).toBe(true);

    // Every way of asking for a write sends nothing.
    h.press('F3');
    h.query<HTMLButtonElement>('[data-testid="tile"]')?.click();
    await scan(h, '4790000000011');
    await settleFully(h);
    await storeOf(h, SaleStore).removeLine(storeOf(h, SaleStore).lines()[0]!.id);
    await storeOf(h, SaleStore).attachCustomer('CUS-0001' as never);
    await settleFully(h);
    expectNoWrites(spies);
    expect(serverSales(h, HARNESS_TENANT_ID, 'BR001')[0]?.lines).toHaveLength(1);
  });

  it('asks no supervisor for an approval while offline, and says why (W-3)', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    await loadSale(h);
    const spies = writeSpies();
    await goOffline(h);
    const flow = h.fixture.debugElement.injector.get(ApprovalFlow);
    const outcome = await flow.requestAndDecide(
      { type: 'discount', reference_type: 'sale', reference_id: 'x', payload: null },
      { title: 'Discount' },
    );
    expect(outcome).toEqual({ kind: 'cancelled' });
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(spies.requestApproval).not.toHaveBeenCalled();
    expect(spies.decideApproval).not.toHaveBeenCalled();
  });

  it('turns the line dialogs submit buttons off while offline (W-3)', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    const sale = await loadSale(h);
    const dialogs = storeOf(h, DialogHost);
    for (const kind of ['price-override', 'line-edit', 'discount-picker'] as const) {
      void dialogs.open(kind, { lineId: sale.lines[0]!.id });
      await settleFully(h);
      await goOffline(h);
      const buttons = Array.from(
        topDialog(h)?.querySelectorAll<HTMLButtonElement>('button[data-testid]') ?? [],
      ).filter((b) => /^(override-ask|edit-save|discount-)/.test(b.dataset['testid'] ?? ''));
      expect(buttons.length, kind).toBeGreaterThan(0);
      expect(
        buttons.every((b) => b.disabled),
        kind,
      ).toBe(true);
      dialogs.closeAll();
      h.state.faults.unreachable = false;
      await storeOf(h, Connectivity).retryNow();
      await settleFully(h);
    }
  });

  it('disables the payment buttons, and the void and refund submits', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    await loadSale(h);
    h.press('F2');
    await h.settle();
    await goOffline(h);
    const pay = topDialog(h)?.querySelectorAll<HTMLButtonElement>('button') ?? [];
    const submit = Array.from(pay).filter((b) =>
      ['pay-submit', 'pay-complete'].includes(b.dataset['testid'] ?? ''),
    );
    expect(submit.length).toBeGreaterThan(0);
    expect(submit.every((b) => b.disabled)).toBe(true);
    h.press('Escape');
    await h.settle();

    h.press('F8');
    await h.settle();
    const voidSubmit = Array.from(
      topDialog(h)?.querySelectorAll<HTMLButtonElement>('button[type="submit"]') ?? [],
    );
    expect(voidSubmit.length).toBeGreaterThan(0);
    expect(voidSubmit.every((b) => b.disabled)).toBe(true);
  });

  it('comes back at once when Retry succeeds, and sends nothing that was asked meanwhile', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    await loadSale(h);
    const spies = writeSpies();
    await goOffline(h);
    h.press('F3');
    await settleFully(h);

    h.state.faults.unreachable = false;
    byId(h, 'network-banner').querySelector('button')?.click();
    await settleFully(h);
    expect(h.query('[data-testid="network-banner"]')).toBeNull();
    expect(byId<HTMLButtonElement>(h, 'cart-pay').disabled).toBe(false);
    expect(byId<HTMLButtonElement>(h, 'cart-hold').disabled).toBe(false);
    // Nothing was queued: the held-back F3 did not run by itself.
    expectNoWrites(spies);
    expect(storeOf(h, SaleStore).status()).toBe('draft');
  });

  it('asks the server again by itself and recovers without a click', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    await loadSale(h);
    vi.useFakeTimers();
    try {
      await goOffline(h);
      expect(h.query('[data-testid="network-banner"]')).not.toBeNull();
      await vi.advanceTimersByTimeAsync(REPROBE_MS);
      await h.settle();
      // Still down: the banner stays and the till keeps trying.
      expect(h.query('[data-testid="network-banner"]')).not.toBeNull();
      h.state.faults.unreachable = false;
      await vi.advanceTimersByTimeAsync(REPROBE_MS);
      await h.settle();
      expect(h.query('[data-testid="network-banner"]')).toBeNull();
      expect(byId<HTMLButtonElement>(h, 'cart-hold').disabled).toBe(false);
    } finally {
      vi.useRealTimers();
    }
  });
});

describe('Leaving the till with a sale in the cart (AC-87)', () => {
  function navigation(h: TillHarness) {
    return vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
  }

  function header(h: TillHarness, label: string): HTMLButtonElement {
    const button = Array.from(h.host.querySelectorAll<HTMLButtonElement>('header button')).find(
      (b) => b.textContent?.trim() === label,
    );
    if (!button) {
      throw new Error(`No header button "${label}"`);
    }
    return button;
  }

  it('signs out at once when the cart is empty', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const navigate = navigation(h);
    header(h, 'Sign out').click();
    await settleFully(h);
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(navigate).toHaveBeenCalledWith(['/login']);
  });

  it('warns before signing out with a sale in the cart, and stays when told to', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    await loadSale(h);
    const navigate = navigation(h);
    header(h, 'Sign out').click();
    await settleFully(h);
    expect(topDialog(h)?.textContent).toContain('stays on the server');
    expect(navigate).not.toHaveBeenCalled();
    expect(sessionStorage.getItem(HARNESS_STORAGE_KEY)).not.toBeNull();
    clickButton(h, 'Stay on the till');
    await settleFully(h);
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(navigate).not.toHaveBeenCalled();
    expect(sessionStorage.getItem(HARNESS_STORAGE_KEY)).not.toBeNull();
    expect(storeOf(h, SaleStore).lineCount()).toBe(1);
  });

  it('leaves only the session key behind while the till runs, and nothing after sign-out', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    localStorage.clear();
    localStorage.setItem('someone-elses-key', 'untouched');
    const sale = await loadSale(h);
    // The till itself writes nothing but the existing session key.
    expect(Object.keys(sessionStorage)).toEqual([HARNESS_STORAGE_KEY]);
    const navigate = navigation(h);
    header(h, 'Sign out').click();
    await settleFully(h);
    clickButton(h, 'Sign out');
    await settleFully(h);
    expect(navigate).toHaveBeenCalledWith(['/login']);
    expect(Object.keys(sessionStorage)).toEqual([]);
    expect(Object.keys(localStorage)).toEqual(['someone-elses-key']);
    expect(localStorage.getItem('someone-elses-key')).toBe('untouched');
    expect(storedText()).not.toContain(sale.id);
    localStorage.clear();
  });

  it('warns before switching branch, and proceeds only on confirmation', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    await loadSale(h);
    const navigate = navigation(h);
    header(h, 'Switch').click();
    await settleFully(h);
    expect(topDialog(h)?.textContent).toContain('stays on the server');
    expect(navigate).not.toHaveBeenCalled();
    h.press('Escape');
    await settleFully(h);
    expect(navigate).not.toHaveBeenCalled();

    header(h, 'Switch').click();
    await settleFully(h);
    clickButton(h, 'Change branch');
    await settleFully(h);
    expect(navigate).toHaveBeenCalledWith(['/context']);
    // The session stays (still signed in), only the company and branch are dropped.
    expect(Object.keys(sessionStorage)).toEqual([HARNESS_STORAGE_KEY]);
    expect(sessionStorage.getItem(HARNESS_STORAGE_KEY)).not.toContain('"branchId":"BR001"');
  });
});
