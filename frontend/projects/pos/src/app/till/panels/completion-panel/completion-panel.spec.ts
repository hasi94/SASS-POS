import { SALES_MOCK_DEMO } from '@flexipos/shared';
import { SaleStore } from '../../state/sale-store';
import { api, branchOf, openPayment, serverSale } from '../../testing/payment-helpers';
import { byId, clickId, scan, settleFully, storeOf } from '../../testing/sale-screen-helpers';
import { TillHarness, setupTill } from '../../testing/till-harness';

async function completeSale(h: TillHarness): Promise<void> {
  await openPayment(h);
  await clickId(h, 'pay-complete');
  await settleFully(h);
}

describe('the completion panel (AC-47)', () => {
  it('is not on screen until a sale completes', async () => {
    const h = await setupTill({ given: ['shift_open', 'paid_sale'] });
    expect(h.query('[data-testid="completion"]')).toBeNull();
    await openPayment(h);
    expect(h.query('[data-testid="completion"]')).toBeNull();
  });

  it('shows the number, the total, the tenders and the change due, from the server', async () => {
    const h = await setupTill({ given: ['shift_open', 'overpaid_sale'] });
    await completeSale(h);
    const sale = serverSale(h);
    expect(sale.status).toBe('completed');
    expect(h.text('completion-number')).toBe(`Sale ${sale.number} completed`);
    expect(sale.number).toBe('INV-000001');
    expect(h.text('completion-total')).toBe('250.00 LKR');
    expect(h.text('completion-change')).toBe('50.00 LKR');
    expect(h.text('completion-tenders')).toContain('CASH');
    expect(h.text('completion-tenders')).toContain('250.00 LKR');
    expect(storeOf(h, SaleStore).current()).toBeNull();
  });

  it('gives the focus to "New sale", which clears the screen and starts no draft (D-9)', async () => {
    const h = await setupTill({ given: ['shift_open', 'paid_sale'] });
    const create = vi.spyOn(api(), 'createSale');
    await completeSale(h);
    expect(document.activeElement).toBe(byId(h, 'new-sale'));
    byId(h, 'new-sale').click();
    await settleFully(h);
    expect(h.query('[data-testid="completion"]')).toBeNull();
    expect(storeOf(h, SaleStore).current()).toBeNull();
    expect(document.activeElement).toBe(byId(h, 'scan-field'));
    expect(create).not.toHaveBeenCalled();
    expect(branchOf(h).sales).toHaveLength(1);
  });

  it('goes by itself when the next sale starts, and does not come back when that sale ends', async () => {
    const h = await setupTill({ given: ['shift_open', 'paid_sale'] });
    await completeSale(h);
    expect(h.query('[data-testid="completion"]')).not.toBeNull();
    await scan(h, SALES_MOCK_DEMO.barcode);
    await settleFully(h);
    expect(h.query('[data-testid="completion"]')).toBeNull();
    storeOf(h, SaleStore).clear();
    await settleFully(h);
    expect(h.query('[data-testid="completion"]')).toBeNull();
  });

  it('shows a zero-total sale with no tenders', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_zero_priced_line'] });
    await completeSale(h);
    expect(h.text('completion-total')).toBe('0.00 LKR');
    expect(h.host.querySelectorAll('[data-testid="completion-tenders"] li')).toHaveLength(0);
  });
});
