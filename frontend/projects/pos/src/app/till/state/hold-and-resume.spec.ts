import { SalesApi } from '@flexipos/shared';
import { throwError } from 'rxjs';
import { api, branchOf, refusal, serverSale } from '../testing/payment-helpers';
import { byId, clearNotes, clickId, settleFully, storeOf } from '../testing/sale-screen-helpers';
import { setupTill } from '../testing/till-harness';
import { Connectivity } from './connectivity';
import { SaleStore } from './sale-store';
import { ShiftStore } from './shift-store';

describe('Hold (AC-51)', () => {
  it('holds the sale on F3, clears the cart, focuses the scan field and names the reference', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    expect(serverSale(h).reference).toBe('S-0001');
    h.press('F3');
    await settleFully(h);
    expect(serverSale(h).status).toBe('held');
    expect(serverSale(h).held_from_status).toBe('draft');
    expect(storeOf(h, SaleStore).current()).toBeNull();
    expect(h.text('cart-empty')).toBe('Scan or choose a product');
    expect(document.activeElement).toBe(byId(h, 'scan-field'));
    expect(h.text('message-bar')).toContain('Sale S-0001 held');
  });

  it('holds from the Hold button, and a sale with tenders is held from pending_payment', async () => {
    const h = await setupTill({ given: ['shift_open', 'pending_payment_sale'] });
    await clickId(h, 'cart-hold');
    await settleFully(h);
    expect(serverSale(h).status).toBe('held');
    expect(serverSale(h).held_from_status).toBe('pending_payment');
    expect(storeOf(h, SaleStore).current()).toBeNull();
  });

  it('says there is nothing to hold, and makes no call, with an empty cart', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const hold = vi.spyOn(api(), 'holdSale');
    h.press('F3');
    await settleFully(h);
    expect(h.text('message-bar')).toContain('There is nothing to hold');
    expect(hold).not.toHaveBeenCalled();
  });

  it('keeps the sale in the cart and shows the refusal when the server refuses', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    serverSale(h).status = 'voided';
    h.press('F3');
    await settleFully(h);
    expect(storeOf(h, SaleStore).current()?.id).toBe(serverSale(h).id);
    expect(h.text('message-bar')).toContain('That sale cannot be changed that way now');
  });

  it('goes to the shift prompt on no_open_shift and keeps the cart (AC-15)', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    vi.spyOn(api(), 'holdSale').mockImplementation(() =>
      throwError(() => refusal('no_open_shift')),
    );
    h.press('F3');
    await settleFully(h);
    expect(storeOf(h, ShiftStore).shiftOpen()).toBe(false);
    expect(h.query('pos-shift-open-prompt')).not.toBeNull();
    expect(storeOf(h, SaleStore).lineCount()).toBe(1);
  });

  it('reads the sale back when the answer to Hold was lost, and shows what the server has (AC-75)', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    h.state.faults.dropAnswerOf.push('sales.hold');
    h.press('F3');
    await settleFully(h);
    expect(serverSale(h).status).toBe('held');
    expect(storeOf(h, SaleStore).needsReload()).toBe(true);
    await storeOf(h, Connectivity).retryNow();
    await settleFully(h);
    expect(storeOf(h, SaleStore).needsReload()).toBe(false);
    expect(storeOf(h, SaleStore).status()).toBe('held');
  });

  it('has nothing more to hold once the one sale is held', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    h.press('F3');
    await settleFully(h);
    clearNotes(h);
    h.press('F3');
    await settleFully(h);
    expect(h.text('message-bar')).toContain('There is nothing to hold');
    expect(branchOf(h).sales).toHaveLength(1);
  });
});

describe('Resuming an unfinished sale on load (AC-88, D-9)', () => {
  it('puts a draft of this terminal in the cart', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_five_rice'] });
    const cart = storeOf(h, SaleStore);
    expect(cart.current()?.id).toBe(serverSale(h).id);
    expect(cart.lineCount()).toBe(1);
    expect(cart.status()).toBe('draft');
    expect(h.text('cart-reference')).toContain('S-0001');
  });

  it('puts a part-paid sale in the cart with its lines locked', async () => {
    const h = await setupTill({ given: ['shift_open', 'pending_payment_sale'] });
    const cart = storeOf(h, SaleStore);
    expect(cart.status()).toBe('pending_payment');
    expect(cart.editable()).toBe(false);
    expect(h.text('total-balance')).toBe('150.00 LKR');
  });

  it('asks for one draft or pending_payment sale of this terminal, and reads it in full', async () => {
    const list = vi.spyOn(SalesApi.prototype, 'listSales');
    const read = vi.spyOn(SalesApi.prototype, 'getSale');
    try {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      expect(list).toHaveBeenCalledTimes(1);
      expect(list.mock.calls[0]?.[0]).toEqual({
        scope: 'current_terminal',
        status: ['draft', 'pending_payment'],
        limit: 1,
      });
      expect(read).toHaveBeenCalledWith(serverSale(h).id);
    } finally {
      list.mockRestore();
      read.mockRestore();
    }
  });

  it('starts empty, and creates nothing, when the server has no unfinished sale', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    expect(storeOf(h, SaleStore).current()).toBeNull();
    expect(h.text('cart-empty')).toBe('Scan or choose a product');
    expect(branchOf(h).sales).toHaveLength(0);
  });

  it('does not resume a held sale', async () => {
    const h = await setupTill({ given: ['shift_open', 'held_from_draft'] });
    expect(storeOf(h, SaleStore).current()).toBeNull();
  });

  it('does not resume anything without an open shift', async () => {
    const h = await setupTill({
      given: ['shift_open', 'draft_sale_with_line', 'close_open_shift'],
    });
    expect(storeOf(h, ShiftStore).shiftOpen()).toBe(false);
    expect(storeOf(h, SaleStore).current()).toBeNull();
  });
});
