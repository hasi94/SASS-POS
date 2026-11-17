import { TestBed } from '@angular/core/testing';
import { SALES_MOCK_DEMO, SalesApi } from '@flexipos/shared';
import { SaleStore } from '../../state/sale-store';
import {
  allById,
  byId,
  serverSales,
  settleFully,
  storeOf,
  typeInto,
} from '../../testing/sale-screen-helpers';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from '../../testing/till-harness';

async function openPrompt(h: TillHarness): Promise<void> {
  h.typeAndEnter('[data-testid="scan-field"]', SALES_MOCK_DEMO.weighablePlu);
  await settleFully(h);
}

function weight(h: TillHarness): HTMLInputElement {
  return byId<HTMLInputElement>(h, 'weight-input');
}

async function enter(h: TillHarness, text: string): Promise<void> {
  typeInto(weight(h), text);
  weight(h).dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
  );
  await settleFully(h);
}

describe('WeightPromptDialog (AC-26)', () => {
  it('opens focused, naming the product and the weighing unit', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    await openPrompt(h);
    expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Weight');
    expect(h.text('weight-product')).toBe('Loose Tomatoes');
    expect(h.query('[role="dialog"] label')?.textContent).toContain('Weight in kg');
    expect(document.activeElement).toBe(weight(h));
  });

  it('adds the line with the weight as typed when Enter is pressed, in the weighing unit', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const addLine = vi.spyOn(TestBed.inject(SalesApi), 'addLine');
    await openPrompt(h);
    await enter(h, ' 1.375 ');
    expect(h.query('[role="dialog"]')).toBeNull();
    const body = addLine.mock.calls[0]?.[1];
    expect(body?.quantity).toBe('1.375');
    expect(body?.unit_id).toBe(storeOf(h, SaleStore).lines()[0]?.unit_id);
    expect(storeOf(h, SaleStore).lines()[0]).toMatchObject({
      product_name: 'Loose Tomatoes',
      quantity: '1.3750',
      unit_code: 'kg',
      line_total: '440.0000',
    });
    expect(document.activeElement).toBe(byId(h, 'scan-field'));
  });

  it('adds with the OK button too', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    await openPrompt(h);
    typeInto(weight(h), '2');
    byId(h, 'weight-ok').click();
    await settleFully(h);
    expect(storeOf(h, SaleStore).lines()[0]?.quantity).toBe('2.0000');
  });

  it.each([
    ['', 'Weight is required'],
    ['   ', 'Weight is required'],
    ['0', 'Weight must be more than zero'],
    ['0.0000', 'Weight must be more than zero'],
    ['-1', 'Weight cannot be negative'],
    ['abc', 'Weight must be a number such as 12.50'],
    ['1,5', 'Weight must be a number such as 12.50'],
    ['1.23456', 'Weight can have at most 4 decimal places'],
  ])('refuses "%s" in the prompt and sends nothing', async (text, message) => {
    const h = await setupTill({ given: ['shift_open'] });
    const addLine = vi.spyOn(TestBed.inject(SalesApi), 'addLine');
    await openPrompt(h);
    await enter(h, text);
    expect(h.text('weight-error')).toBe(message);
    expect(h.query('[role="dialog"]')).not.toBeNull();
    expect(addLine).not.toHaveBeenCalled();
    expect(serverSales(h, HARNESS_TENANT_ID, 'BR001')).toHaveLength(0);
  });

  it('adds nothing on Esc and gives the focus back to the scan field', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    await openPrompt(h);
    typeInto(weight(h), '2');
    h.press('Escape');
    await settleFully(h);
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(storeOf(h, SaleStore).lineCount()).toBe(0);
    expect(serverSales(h, HARNESS_TENANT_ID, 'BR001')).toHaveLength(0);
    expect(document.activeElement).toBe(byId(h, 'scan-field'));
  });

  it('lets the cashier fix a refused entry and add it', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    await openPrompt(h);
    await enter(h, '0');
    await enter(h, '0.5');
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(storeOf(h, SaleStore).lines()[0]?.quantity).toBe('0.5000');
  });

  it('holds a later scan behind it until the weight is answered', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    await openPrompt(h);
    h.typeAndEnter('[data-testid="scan-field"]', SALES_MOCK_DEMO.barcode);
    await settleFully(h);
    // The dialog holds the queue: the rice is still waiting, shown as a pending row.
    expect(allById(h, 'pending-scan').length).toBeGreaterThan(0);
    expect(storeOf(h, SaleStore).lineCount()).toBe(0);
    await enter(h, '1');
    await settleFully(h);
    expect(
      storeOf(h, SaleStore)
        .lines()
        .map((l) => l.product_name),
    ).toEqual(['Loose Tomatoes', 'Rice 1kg']);
  });
});
