import { TestBed } from '@angular/core/testing';
import { ApiError, Sale, SalesApi } from '@flexipos/shared';
import { serverSale } from '../testing/payment-helpers';
import { settleFully, storeOf } from '../testing/sale-screen-helpers';
import { TillHarness, setupTill } from '../testing/till-harness';
import { Connectivity } from './connectivity';
import { PaymentStore } from './payment-store';
import { SaleStore } from './sale-store';

/**
 * QA (T-037, AC-74, D-2): the payment store itself refuses to write while the server cannot be
 * reached. The dialog's disabled buttons are one layer; a key binding or a stale handler reaching
 * the store directly must send nothing either. (Mutation M6 removed the store's guard and no
 * existing spec noticed.)
 */
async function offlineWithSale(): Promise<{ h: TillHarness; sale: Sale }> {
  const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
  const sale = JSON.parse(JSON.stringify(serverSale(h))) as Sale;
  storeOf(h, SaleStore).load(sale);
  await storeOf(h, PaymentStore).loadMethods();
  storeOf(h, Connectivity).reportFailure(new ApiError(0, 'Cannot reach the server.'));
  await settleFully(h);
  expect(storeOf(h, Connectivity).online()).toBe(false);
  return { h, sale };
}

describe('PaymentStore without a network (QA, AC-74)', () => {
  it('sends no tender while offline and says it ignored the request', async () => {
    const { h } = await offlineWithSale();
    const store = storeOf(h, PaymentStore);
    const spy = vi.spyOn(TestBed.inject(SalesApi), 'addTender');
    const cash = store.methods().find((m) => m.type === 'cash');
    expect(cash).toBeDefined();
    const outcome = await store.addTender({ method: cash!, tendered: '250.0000', reference: null });
    expect(outcome.kind).toBe('ignored');
    expect(spy).not.toHaveBeenCalled();
  });

  it('removes no tender and completes nothing while offline', async () => {
    const { h } = await offlineWithSale();
    const store = storeOf(h, PaymentStore);
    const api = TestBed.inject(SalesApi);
    const remove = vi.spyOn(api, 'removeTender');
    const complete = vi.spyOn(api, 'completeSale');
    expect((await store.removeTender('01J00000000000000000000000')).kind).toBe('ignored');
    expect((await store.complete()).kind).toBe('ignored');
    expect(remove).not.toHaveBeenCalled();
    expect(complete).not.toHaveBeenCalled();
  });
});
