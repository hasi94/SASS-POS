import { TestBed } from '@angular/core/testing';
import {
  SALES_MOCK_DEMO,
  Sale,
  SalesApi,
  asQuantity,
  formatMoney,
  formatQuantity,
} from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { Connectivity } from '../../state/connectivity';
import { SaleStore } from '../../state/sale-store';
import { ScanQueue } from '../../state/scan-queue';
import {
  allById,
  byId,
  clickId,
  scan,
  serverSales,
  settleFully,
  storeOf,
} from '../../testing/sale-screen-helpers';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from '../../testing/till-harness';

const seedId = (tag: string, n: number): string => `01JMCK${tag}${String(n).padStart(19, '0')}`;
const EACH = seedId('N', 1);
const BOX = seedId('N', 3);
const KG = seedId('N', 2);
const PACK100 = seedId('N', 4);
const RICE = seedId('P', 1);
const WATER = seedId('P', 2);
const SCREWS = seedId('P', 12);

const serverSale = (h: TillHarness, index = 0): Sale =>
  serverSales(h, HARNESS_TENANT_ID, 'BR001')[index]!;
const cartText = (h: TillHarness) => byId(h, 'cart-lines').textContent ?? '';

/** Loads the first server sale into the cart, as a resumed sale would be. */
async function loadSale(h: TillHarness): Promise<Sale> {
  const sale = JSON.parse(JSON.stringify(serverSale(h))) as Sale;
  storeOf(h, SaleStore).load(sale);
  await h.settle();
  return sale;
}

describe('Cart (AC-29 to AC-33, AC-36 to AC-38)', () => {
  describe('before anything is added (AC-29, AC-38)', () => {
    it('shows the empty state and disables Pay and Hold, saying why', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      expect(h.text('cart-empty')).toBe('Scan or choose a product');
      expect(byId<HTMLButtonElement>(h, 'cart-pay').disabled).toBe(true);
      expect(byId<HTMLButtonElement>(h, 'cart-hold').disabled).toBe(true);
      expect(h.text('cart-reason')).toBe('Add a product before paying');
      expect(h.text('cart-count')).toBe('0 lines in the cart');
    });

    it('creates no sale on load or on a key press, only on the first line', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const api = TestBed.inject(SalesApi);
      const create = vi.spyOn(api, 'createSale');
      const addLine = vi.spyOn(api, 'addLine');
      for (const key of ['ArrowDown', 'F6', 'Escape', 'F2', 'Delete']) {
        h.press(key);
      }
      await h.settle();
      expect(serverSales(h, HARNESS_TENANT_ID, 'BR001')).toHaveLength(0);
      expect(create).not.toHaveBeenCalled();
      await scan(h, SALES_MOCK_DEMO.barcode);
      await settleFully(h);
      expect(create).toHaveBeenCalledTimes(1);
      expect(addLine).toHaveBeenCalledTimes(1);
      expect(create.mock.invocationCallOrder[0]!).toBeLessThan(
        addLine.mock.invocationCallOrder[0]!,
      );
      expect(create.mock.calls[0]).toEqual([{}]);
      expect(serverSales(h, HARNESS_TENANT_ID, 'BR001')).toHaveLength(1);
    });

    it('creates the sale once for several lines', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const create = vi.spyOn(TestBed.inject(SalesApi), 'createSale');
      await scan(h, SALES_MOCK_DEMO.barcode);
      await scan(h, SALES_MOCK_DEMO.packBarcode);
      await settleFully(h);
      expect(create).toHaveBeenCalledTimes(1);
      expect(storeOf(h, SaleStore).lineCount()).toBe(2);
    });
  });

  describe("lines and totals, all the server's strings (AC-30)", () => {
    it('shows name, quantity with unit, unit price, tax and line total, and the footer totals', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await scan(h, SALES_MOCK_DEMO.packBarcode);
      await settleFully(h);
      const line = storeOf(h, SaleStore).lines()[0]!;
      const sale = storeOf(h, SaleStore).current()!;
      expect(h.text('line-name')).toBe('Mineral Water 500ml');
      expect(h.text('line-quantity')).toBe(`${formatQuantity(line.quantity)} box`);
      expect(h.text('line-price')).toBe(`Price ${formatMoney(line.unit_price)} LKR`);
      expect(h.text('line-tax')).toBe(`Tax ${formatMoney(line.tax_amount)} LKR`);
      expect(h.text('line-total')).toBe(`${formatMoney(line.line_total)} LKR`);
      expect(h.text('total-subtotal')).toBe(`${formatMoney(sale.subtotal)} LKR`);
      expect(h.text('total-discount')).toBe(`${formatMoney(sale.discount_total)} LKR`);
      expect(h.text('total-tax')).toBe(`${formatMoney(sale.tax_total)} LKR`);
      expect(h.text('total-total')).toBe(`${formatMoney(sale.total)} LKR`);
      expect(h.text('total-tax')).toBe('36.00 LKR');
      expect(h.text('total-total')).toBe('486.00 LKR');
      expect(h.query('[data-testid="total-paid"]')).toBeNull();
      expect(h.query('[data-testid="total-balance"]')).toBeNull();
    });

    it('shows a discount only when it is not zero', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_discounted_line'] });
      await loadSale(h);
      expect(h.text('line-discount')).toBe('Discount 24.00 LKR');
      const plain = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await loadSale(plain);
      expect(plain.query('[data-testid="line-discount"]')).toBeNull();
    });

    it('shows paid and balance due once payment has started', async () => {
      const h = await setupTill({ given: ['shift_open', 'pending_payment_sale'] });
      const sale = await loadSale(h);
      expect(h.text('total-paid')).toBe(`${formatMoney(sale.paid_total)} LKR`);
      expect(h.text('total-balance')).toBe(`${formatMoney(sale.balance_due)} LKR`);
      expect(h.text('total-paid')).toBe('100.00 LKR');
      expect(h.text('total-balance')).toBe('150.00 LKR');
    });

    it('is a labelled list region and announces the number of lines when it changes', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      expect(
        h.query('section[aria-labelledby="pos-cart-title"] ul[aria-label="Cart lines"]'),
      ).not.toBeNull();
      const region = byId(h, 'cart-count');
      expect(region.getAttribute('aria-live')).toBe('polite');
      await scan(h, SALES_MOCK_DEMO.barcode);
      await settleFully(h);
      expect(region.textContent?.trim()).toBe('1 line in the cart');
      await scan(h, SALES_MOCK_DEMO.packBarcode);
      await settleFully(h);
      expect(region.textContent?.trim()).toBe('2 lines in the cart');
    });

    it('marks a line the server prices at zero, and does not block it (AC-37)', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await scan(h, SALES_MOCK_DEMO.zeroPricedPlu);
      await settleFully(h);
      expect(storeOf(h, SaleStore).lineCount()).toBe(1);
      expect(h.text('zero-price')).toBe('Zero price');
      expect(h.text('line-total')).toBe('0.00 LKR');
      await scan(h, SALES_MOCK_DEMO.barcode);
      await settleFully(h);
      expect(allById(h, 'zero-price')).toHaveLength(1);
    });

    it('enables Pay and Hold once a line is in, and Pay opens the payment dialog', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await scan(h, SALES_MOCK_DEMO.barcode);
      await settleFully(h);
      expect(byId<HTMLButtonElement>(h, 'cart-pay').disabled).toBe(false);
      expect(byId<HTMLButtonElement>(h, 'cart-hold').disabled).toBe(false);
      expect(h.query('[data-testid="cart-reason"]')).toBeNull();
      await clickId(h, 'cart-pay');
      expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Payment');
    });
  });

  describe('selecting and removing a line (AC-31, AC-32)', () => {
    async function twoLines(): Promise<TillHarness> {
      const h = await setupTill({ given: ['shift_open'] });
      await scan(h, SALES_MOCK_DEMO.barcode);
      await scan(h, SALES_MOCK_DEMO.packBarcode);
      await settleFully(h);
      return h;
    }

    it('selects with Up and Down from the scan field and with a click, marking the selection', async () => {
      const h = await twoLines();
      const store = storeOf(h, SaleStore);
      const [first, second] = store.lines();
      h.press('ArrowDown');
      expect(store.selectedLineId()).toBe(first?.id);
      h.press('ArrowDown');
      expect(store.selectedLineId()).toBe(second?.id);
      h.press('ArrowDown');
      expect(store.selectedLineId()).toBe(second?.id);
      h.press('ArrowUp');
      await h.settle();
      expect(store.selectedLineId()).toBe(first?.id);
      const rows = allById(h, 'cart-line');
      expect(rows[0]?.getAttribute('aria-current')).toBe('true');
      expect(rows[1]?.getAttribute('aria-current')).toBeNull();
      allById(h, 'line-name')[1]?.click();
      await h.settle();
      expect(store.selectedLineId()).toBe(second?.id);
      expect(h.text('line-actions')).toContain('Selected: Mineral Water 500ml');
    });

    it('asks before removing: Delete shows a confirmation and removes nothing yet', async () => {
      const h = await twoLines();
      const remove = vi.spyOn(TestBed.inject(SalesApi), 'removeLine');
      h.press('ArrowDown');
      h.press('Delete');
      await h.settle();
      expect(byId(h, 'confirm-removal').textContent).toContain('Remove Rice 1kg?');
      expect(remove).not.toHaveBeenCalled();
      expect(storeOf(h, SaleStore).lineCount()).toBe(2);
      expect(document.activeElement).toBe(byId(h, 'confirm-remove'));
    });

    it("removes the line on Enter and shows the server's recomputed totals", async () => {
      const h = await twoLines();
      const before = h.text('total-total');
      h.press('ArrowDown');
      h.press('Delete');
      await h.settle();
      byId(h, 'confirm-remove').click();
      await settleFully(h);
      const store = storeOf(h, SaleStore);
      expect(store.lines().map((l) => l.product_name)).toEqual(['Mineral Water 500ml']);
      expect(serverSale(h).lines).toHaveLength(1);
      expect(h.query('[data-testid="confirm-removal"]')).toBeNull();
      expect(h.text('total-total')).toBe('486.00 LKR');
      expect(before).not.toBe(h.text('total-total'));
      expect(document.activeElement).toBe(byId(h, 'scan-field'));
    });

    it('removes on a second Delete press too', async () => {
      const h = await twoLines();
      h.press('ArrowDown');
      h.press('Delete');
      await h.settle();
      h.press('Delete');
      await settleFully(h);
      expect(storeOf(h, SaleStore).lineCount()).toBe(1);
    });

    it('keeps the line on Esc and hands the focus back to the scan field', async () => {
      const h = await twoLines();
      h.press('ArrowDown');
      h.press('Delete');
      await h.settle();
      const event = h.press('Escape');
      await h.settle();
      expect(event.defaultPrevented).toBe(true);
      expect(h.query('[data-testid="confirm-removal"]')).toBeNull();
      expect(storeOf(h, SaleStore).lineCount()).toBe(2);
      expect(document.activeElement).toBe(byId(h, 'scan-field'));
    });

    it('drops the confirmation when another line is selected', async () => {
      const h = await twoLines();
      h.press('ArrowDown');
      h.press('Delete');
      await h.settle();
      storeOf(h, SaleStore).selectNext();
      await h.settle();
      expect(h.query('[data-testid="confirm-removal"]')).toBeNull();
      expect(storeOf(h, SaleStore).lineCount()).toBe(2);
    });

    it('removes through the Remove control as well, with the same confirmation', async () => {
      const h = await twoLines();
      allById(h, 'line-name')[0]?.click();
      await h.settle();
      await clickId(h, 'action-remove');
      expect(h.query('[data-testid="confirm-removal"]')).not.toBeNull();
      expect(storeOf(h, SaleStore).lineCount()).toBe(2);
    });
  });

  describe('once payment has started (AC-33)', () => {
    it('disables the line actions with the reason', async () => {
      const h = await setupTill({ given: ['shift_open', 'pending_payment_sale'] });
      const sale = await loadSale(h);
      storeOf(h, SaleStore).select(sale.lines[0]!.id);
      await h.settle();
      for (const id of ['action-edit', 'action-override', 'action-discount', 'action-remove']) {
        expect(byId<HTMLButtonElement>(h, id).disabled, id).toBe(true);
      }
      expect(h.text('line-reason')).toBe('Payment has started: remove the tenders to edit lines');
    });

    it('does the same for a sale recalled from pending payment', async () => {
      const h = await setupTill({ given: ['shift_open', 'held_from_pending_payment'] });
      const sale = await loadSale(h);
      storeOf(h, SaleStore).select(sale.lines[0]!.id);
      await h.settle();
      expect(byId<HTMLButtonElement>(h, 'action-remove').disabled).toBe(true);
      expect(h.text('line-reason')).toContain('Payment has started');
    });

    it('does not start a removal on a locked sale; Delete says why', async () => {
      const h = await setupTill({ given: ['shift_open', 'pending_payment_sale'] });
      const sale = await loadSale(h);
      storeOf(h, SaleStore).select(sale.lines[0]!.id);
      h.press('Delete');
      await h.settle();
      expect(h.query('[data-testid="confirm-removal"]')).toBeNull();
      expect(h.text('message-bar')).toContain('Payment has started');
    });

    it('shows the same message and reloads when the server refuses anyway', async () => {
      const h = await setupTill({ given: ['shift_open', 'pending_payment_sale'] });
      const stale = JSON.parse(JSON.stringify(serverSale(h))) as Sale;
      // The cart still believes the sale is a draft with no tenders.
      storeOf(h, SaleStore).load({
        ...stale,
        status: 'draft',
        tenders: [],
        paid_total: '0.0000' as never,
      });
      await h.settle();
      expect(h.query('[data-testid="total-paid"]')).toBeNull();
      await scan(h, SALES_MOCK_DEMO.barcode);
      await settleFully(h);
      expect(h.text('assertive-region')).toBe(
        'Payment has started: remove the tenders to edit lines',
      );
      expect(storeOf(h, SaleStore).status()).toBe('pending_payment');
      expect(h.text('total-paid')).toBe('100.00 LKR');
      expect(storeOf(h, SaleStore).lineCount()).toBe(1);
    });
  });

  describe('a refused add or update changes nothing (AC-36)', () => {
    it('shows "No price for this product" and keeps the previous cart', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      const before = await loadSale(h);
      const outcome = await storeOf(h, SaleStore).addLine({
        productId: SCREWS,
        unitId: PACK100,
        quantity: asQuantity('1'),
      });
      await settleFully(h);
      expect(outcome).toEqual({ ok: false, code: 'unresolvable_price' });
      expect(h.text('message-bar')).toContain('No price for this product');
      expect(storeOf(h, SaleStore).current()).toEqual(before);
      expect(serverSale(h).lines).toHaveLength(1);
    });

    it('shows "This unit does not apply to the product"', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await loadSale(h);
      const outcome = await storeOf(h, SaleStore).addLine({
        productId: RICE,
        unitId: KG,
        quantity: asQuantity('1'),
      });
      await settleFully(h);
      expect(outcome.code).toBe('unit_unresolvable');
      expect(h.text('message-bar')).toContain('This unit does not apply to the product');
      expect(storeOf(h, SaleStore).lineCount()).toBe(1);
    });

    it('shows a product that cannot be sold with the failed fact', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const outcome = await storeOf(h, SaleStore).addLine({
        productId: seedId('P', 10),
        unitId: EACH,
        quantity: asQuantity('1'),
      });
      await settleFully(h);
      expect(outcome.code).toBe('product_not_sellable');
      expect(h.text('message-bar')).toContain('This product cannot be sold (is_active)');
    });

    it('does not select or reorder anything when an update is refused', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      const before = await loadSale(h);
      const lineId = before.lines[0]!.id;
      const outcome = await storeOf(h, SaleStore).updateLine(lineId, { unit_id: KG });
      expect(outcome.code).toBe('unit_unresolvable');
      expect(storeOf(h, SaleStore).current()).toEqual(before);
    });
  });

  describe('a lost answer (AC-75)', () => {
    async function lostAddLine(): Promise<TillHarness> {
      const h = await setupTill({ given: ['shift_open'] });
      h.state.faults.dropAnswerOf.push('sales.add-line');
      await scan(h, SALES_MOCK_DEMO.barcode);
      await settleFully(h);
      return h;
    }

    it('shows the network banner and a cart that does not yet know about the line', async () => {
      const h = await lostAddLine();
      expect(h.query('[data-testid="network-banner"]')).not.toBeNull();
      expect(serverSale(h).lines).toHaveLength(1);
      expect(storeOf(h, SaleStore).lineCount()).toBe(0);
      expect(storeOf(h, SaleStore).needsReload()).toBe(true);
    });

    it('reloads the sale when the connection is back, before anything else, and shows the line', async () => {
      const h = await lostAddLine();
      await storeOf(h, Connectivity).retryNow();
      await settleFully(h);
      expect(storeOf(h, SaleStore).needsReload()).toBe(false);
      expect(
        storeOf(h, SaleStore)
          .lines()
          .map((l) => l.product_name),
      ).toEqual(['Rice 1kg']);
      expect(h.query('[data-testid="network-banner"]')).toBeNull();
    });

    it('accepts no line action until a reload has succeeded', async () => {
      const h = await lostAddLine();
      const addLine = vi.spyOn(TestBed.inject(SalesApi), 'addLine');
      h.state.faults.unreachable = true;
      await scan(h, SALES_MOCK_DEMO.packBarcode);
      await settleFully(h);
      expect(addLine).not.toHaveBeenCalled();
      expect(storeOf(h, SaleStore).needsReload()).toBe(true);
      expect(serverSale(h).lines).toHaveLength(1);
      // The server is back: the next action reads the sale first, then goes through.
      h.state.faults.unreachable = false;
      await scan(h, SALES_MOCK_DEMO.packBarcode);
      await settleFully(h);
      expect(addLine).toHaveBeenCalledTimes(1);
      expect(storeOf(h, SaleStore).needsReload()).toBe(false);
      expect(
        storeOf(h, SaleStore)
          .lines()
          .map((l) => l.product_name),
      ).toEqual(['Rice 1kg', 'Mineral Water 500ml']);
    });

    it('never shows a line the server does not have after a lost remove', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await loadSale(h);
      h.state.faults.dropAnswerOf.push('sales.remove-line');
      const lineId = serverSale(h).lines[0]!.id;
      await storeOf(h, SaleStore).removeLine(lineId);
      await settleFully(h);
      // The server removed it; the cart still shows it until the sale is read again.
      expect(serverSale(h).lines).toHaveLength(0);
      expect(storeOf(h, SaleStore).lineCount()).toBe(1);
      await storeOf(h, Connectivity).retryNow();
      await settleFully(h);
      expect(storeOf(h, SaleStore).lineCount()).toBe(0);
    });
  });

  describe('200 lines, no input lost (non-functional)', () => {
    it('takes 200 scans typed back to back: every one queued, every line added, in order', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const queue = storeOf(h, ScanQueue);
      const codes = Array.from({ length: 200 }, (_, i) =>
        i % 2 === 0 ? SALES_MOCK_DEMO.barcode : SALES_MOCK_DEMO.packBarcode,
      );
      for (const code of codes) {
        h.typeAndEnter('[data-testid="scan-field"]', code);
      }
      expect(queue.pending()).toHaveLength(200);
      await vi.waitFor(() => expect(storeOf(h, SaleStore).lineCount()).toBe(200), {
        timeout: 20000,
      });
      await settleFully(h);
      expect(queue.pending()).toHaveLength(0);
      expect(serverSale(h).lines.map((l) => l.product_name)).toEqual(
        codes.map((c) => (c === SALES_MOCK_DEMO.barcode ? 'Rice 1kg' : 'Mineral Water 500ml')),
      );
      expect(allById(h, 'cart-line')).toHaveLength(200);
    }, 30000);

    it('moves the selection one line for each of 200 Down and Up presses, and removes the last on request', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const api = TestBed.inject(SalesApi);
      let sale = await firstValueFrom(api.createSale({}));
      for (let i = 0; i < 200; i++) {
        sale = await firstValueFrom(
          api.addLine(sale.id, { product_id: RICE, unit_id: EACH, quantity: asQuantity('1') }),
        );
      }
      const store = storeOf(h, SaleStore);
      store.load(sale);
      await h.settle();
      expect(allById(h, 'cart-line')).toHaveLength(200);
      for (let i = 0; i < 200; i++) {
        expect(h.press('ArrowDown').defaultPrevented, `down ${i}`).toBe(true);
        expect(store.selectedLineId(), `down ${i}`).toBe(sale.lines[i]?.id);
      }
      for (let i = 198; i >= 0; i--) {
        h.press('ArrowUp');
        expect(store.selectedLineId(), `up ${i}`).toBe(sale.lines[i]?.id);
      }
      store.select(sale.lines[199]!.id);
      h.press('Delete');
      await h.settle();
      byId(h, 'confirm-remove').click();
      await vi.waitFor(() => expect(store.lineCount()).toBe(199));
      await settleFully(h);
      expect(allById(h, 'cart-line')).toHaveLength(199);
      expect(cartText(h)).toContain('Rice 1kg');
    }, 30000);
  });
});
