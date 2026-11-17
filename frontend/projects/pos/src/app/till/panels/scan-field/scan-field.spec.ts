import { TestBed } from '@angular/core/testing';
import { SalesApi, SALES_MOCK_DEMO, branchState } from '@flexipos/shared';
import { SaleStore } from '../../state/sale-store';
import {
  allById,
  byId,
  scan,
  serverSales,
  settleFully,
  storeOf,
  typeInto,
} from '../../testing/sale-screen-helpers';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from '../../testing/till-harness';

function field(h: TillHarness): HTMLInputElement {
  return byId<HTMLInputElement>(h, 'scan-field');
}

async function scanAll(h: TillHarness, code: string): Promise<void> {
  h.typeAndEnter('[data-testid="scan-field"]', code);
  await settleFully(h);
}

function linesOf(h: TillHarness): { name: string; quantity: string; unit: string }[] {
  return storeOf(h, SaleStore)
    .lines()
    .map((l) => ({ name: l.product_name, quantity: l.quantity, unit: l.unit_code }));
}

describe('ScanField (AC-21, AC-22, AC-23, AC-24, AC-25, AC-27, AC-81)', () => {
  describe('focus (AC-21)', () => {
    it('is focused when the till opens', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      expect(document.activeElement).toBe(field(h));
    });

    it('takes the focus back when a dialog closes, and on Esc from anywhere', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      h.press('F1');
      await h.settle();
      expect(h.query('[role="dialog"]')).not.toBeNull();
      h.press('Escape');
      await h.settle();
      expect(document.activeElement).toBe(field(h));
      byId<HTMLInputElement>(h, 'search-field').focus();
      h.press('Escape');
      expect(document.activeElement).toBe(field(h));
    });

    it('keeps the focus through a scan, an unknown code and a refused label', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      for (const code of [SALES_MOCK_DEMO.barcode, '0000', SALES_MOCK_DEMO.priceLabel]) {
        await scanAll(h, code);
        expect(document.activeElement, code).toBe(field(h));
      }
    });
  });

  describe('what is sent (AC-22)', () => {
    it('clears the field the moment Enter is pressed, before any answer', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      field(h).value = '4790000000011';
      field(h).dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
      );
      expect(field(h).value).toBe('');
      await settleFully(h);
      expect(linesOf(h)).toHaveLength(1);
    });

    it('trims the whitespace and alters nothing else, a plus sign included', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const scanCall = vi.spyOn(TestBed.inject(SalesApi), 'scan');
      await scanAll(h, '  12+34 ');
      await scanAll(h, 'ab/cd=');
      expect(scanCall.mock.calls.map((c) => c[0])).toEqual([{ code: '12+34' }, { code: 'ab/cd=' }]);
    });

    it('sends nothing for an empty or blank entry', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const scanCall = vi.spyOn(TestBed.inject(SalesApi), 'scan');
      await scanAll(h, '');
      await scanAll(h, '   ');
      expect(scanCall).not.toHaveBeenCalled();
    });

    it('does nothing and says so while there is no open shift', async () => {
      const h = await setupTill();
      const scanCall = vi.spyOn(TestBed.inject(SalesApi), 'scan');
      field(h).focus();
      await scanAll(h, SALES_MOCK_DEMO.barcode);
      expect(scanCall).not.toHaveBeenCalled();
      expect(h.text('message-bar')).toContain('Open a shift first');
    });
  });

  describe('what a code adds (AC-23, AC-24)', () => {
    it('adds the product by exact barcode with the unit and quantity the server resolved', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const addLine = vi.spyOn(TestBed.inject(SalesApi), 'addLine');
      await scanAll(h, SALES_MOCK_DEMO.barcode);
      expect(linesOf(h)).toEqual([{ name: 'Rice 1kg', quantity: '1.0000', unit: 'ea' }]);
      const [saleId, body] = addLine.mock.calls[0] ?? [];
      expect(saleId).toBe(serverSales(h, HARNESS_TENANT_ID, 'BR001')[0]?.id);
      expect(Object.keys(body ?? {}).sort()).toEqual(['product_id', 'quantity', 'unit_id']);
      expect(body?.quantity).toBe('1');
    });

    it('adds a PLU the same way', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await scanAll(h, SALES_MOCK_DEMO.plu);
      expect(linesOf(h).map((l) => l.name)).toEqual(['Rice 1kg']);
    });

    it('adds a pack barcode in the pack unit with the pack quantity', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await scanAll(h, SALES_MOCK_DEMO.packBarcode);
      expect(linesOf(h)).toEqual([
        { name: 'Mineral Water 500ml', quantity: '1.0000', unit: 'box' },
      ]);
      const line = storeOf(h, SaleStore).lines()[0];
      expect(line?.unit_price).toBe('450.0000');
    });

    it('adds a scale label with the weight and unit it carries, and does not ask for a weight', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await scanAll(h, SALES_MOCK_DEMO.weightLabel);
      expect(h.query('[role="dialog"]')).toBeNull();
      expect(linesOf(h)).toEqual([{ name: 'Loose Tomatoes', quantity: '1.2500', unit: 'kg' }]);
    });

    it('announces what was added in the polite region (AC-84)', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await scanAll(h, SALES_MOCK_DEMO.barcode);
      expect(h.text('polite-region')).toBe('Added Rice 1kg');
    });
  });

  describe('what a code cannot add (AC-25, AC-27)', () => {
    it('refuses a price-embedded label, adds nothing and creates no sale', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await scanAll(h, SALES_MOCK_DEMO.priceLabel);
      expect(h.text('assertive-region')).toBe('Price-embedded labels are not supported yet');
      expect(linesOf(h)).toEqual([]);
      expect(serverSales(h, HARNESS_TENANT_ID, 'BR001')).toHaveLength(0);
      expect(h.query('[role="dialog"]')).toBeNull();
    });

    it('says "Code not found: <code>" in the alert region and adds nothing', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await scanAll(h, '  0000123 ');
      expect(h.text('assertive-region')).toBe('Code not found: 0000123');
      expect(h.text('message-bar')).toContain('Code not found: 0000123');
      expect(linesOf(h)).toEqual([]);
      expect(serverSales(h, HARNESS_TENANT_ID, 'BR001')).toHaveLength(0);
    });
  });

  describe('the multiplier (AC-81)', () => {
    it('shows a chip for `3*` and adds the next code with quantity 3, sent as typed', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const addLine = vi.spyOn(TestBed.inject(SalesApi), 'addLine');
      await scanAll(h, '3*');
      expect(byId(h, 'multiplier-chip').textContent).toContain('3');
      expect(field(h).value).toBe('');
      expect(addLine).not.toHaveBeenCalled();
      await scanAll(h, SALES_MOCK_DEMO.barcode);
      expect(h.query('[data-testid="multiplier-chip"]')).toBeNull();
      expect(addLine.mock.calls[0]?.[1].quantity).toBe('3');
      expect(linesOf(h)).toEqual([{ name: 'Rice 1kg', quantity: '3.0000', unit: 'ea' }]);
      await scanAll(h, SALES_MOCK_DEMO.barcode);
      expect(linesOf(h).map((l) => l.quantity)).toEqual(['3.0000', '1.0000']);
    });

    it('clears the chip on Esc and the next code is quantity 1', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await scanAll(h, '5*');
      field(h).focus();
      const event = h.press('Escape');
      await h.settle();
      expect(event.defaultPrevented).toBe(true);
      expect(h.query('[data-testid="multiplier-chip"]')).toBeNull();
      await scanAll(h, SALES_MOCK_DEMO.barcode);
      expect(linesOf(h).map((l) => l.quantity)).toEqual(['1.0000']);
    });

    it('lets Esc go on to the page when there is no multiplier', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      field(h).focus();
      expect(h.press('Escape').defaultPrevented).toBe(true);
      // The page's own Esc handling ran: it keeps the focus on the scan field.
      expect(document.activeElement).toBe(field(h));
    });

    it('ignores the multiplier for a weighable product, which asks for its weight', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await scanAll(h, '4*');
      await scanAll(h, SALES_MOCK_DEMO.weighablePlu);
      expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Weight');
      expect(h.query('[data-testid="multiplier-chip"]')).toBeNull();
      typeInto(byId<HTMLInputElement>(h, 'weight-input'), '0.75');
      byId(h, 'weight-ok').click();
      await settleFully(h);
      expect(linesOf(h)).toEqual([{ name: 'Loose Tomatoes', quantity: '0.7500', unit: 'kg' }]);
    });

    it('keeps the quantity a code carries and says the multiplier was not used', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await scanAll(h, '3*');
      await scanAll(h, SALES_MOCK_DEMO.packBarcode);
      expect(linesOf(h)[0]?.quantity).toBe('1.0000');
      expect(h.text('polite-region')).toContain('3* was not used');
    });

    it.each(['0*', '-1*', '1.5*', '1234*', '*', 'x3*'])(
      'treats %s as a code, not a multiplier',
      async (text) => {
        const h = await setupTill({ given: ['shift_open'] });
        const scanCall = vi.spyOn(TestBed.inject(SalesApi), 'scan');
        await scanAll(h, text);
        expect(h.query('[data-testid="multiplier-chip"]')).toBeNull();
        expect(scanCall).toHaveBeenCalledWith({ code: text });
      },
    );
  });

  it('leaves no pending row behind once every code is answered', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    await scan(h, SALES_MOCK_DEMO.barcode);
    await settleFully(h);
    expect(allById(h, 'pending-scan')).toHaveLength(0);
    expect(branchState(h.state, HARNESS_TENANT_ID, 'BR001').sales).toHaveLength(1);
  });
});
