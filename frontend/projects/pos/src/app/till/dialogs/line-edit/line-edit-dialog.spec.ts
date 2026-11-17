import { TestBed } from '@angular/core/testing';
import { SALES_MOCK_DEMO, SalesApi } from '@flexipos/shared';
import { SaleStore } from '../../state/sale-store';
import {
  byId,
  clearNotes,
  clickId,
  scan,
  settleFully,
  storeOf,
  typeInto,
} from '../../testing/sale-screen-helpers';
import { TillHarness, setupTill } from '../../testing/till-harness';

/** Rice and a box of water in the cart, the water selected, the edit dialog open. */
async function openEditOnWater(): Promise<TillHarness> {
  const h = await setupTill({ given: ['shift_open'] });
  await scan(h, SALES_MOCK_DEMO.barcode);
  await scan(h, SALES_MOCK_DEMO.packBarcode);
  await settleFully(h);
  storeOf(h, SaleStore).select(storeOf(h, SaleStore).lines()[1]!.id);
  await h.settle();
  await clickId(h, 'action-edit');
  await settleFully(h);
  return h;
}

function quantityBox(h: TillHarness): HTMLInputElement {
  return byId<HTMLInputElement>(h, 'edit-quantity');
}

async function save(h: TillHarness): Promise<void> {
  byId(h, 'edit-quantity')
    .closest('form')
    ?.dispatchEvent(new Event('submit', { cancelable: true }));
  await settleFully(h);
}

describe('LineEditDialog (AC-31)', () => {
  it('opens on the selected line with its quantity, and focus in the quantity field', async () => {
    const h = await openEditOnWater();
    expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Change the line');
    expect(h.text('edit-product')).toBe('Mineral Water 500ml');
    expect(quantityBox(h).value).toBe('1.0000');
    expect(document.activeElement).toBe(quantityBox(h));
  });

  it("offers the product's own units, the line's unit selected", async () => {
    const h = await openEditOnWater();
    const select = byId<HTMLSelectElement>(h, 'edit-unit');
    expect(Array.from(select.options).map((o) => o.textContent?.trim())).toEqual(['ea', 'box']);
    expect(select.selectedOptions[0]?.textContent?.trim()).toBe('box');
  });

  it('sends the new quantity as typed and shows the line as the server priced it', async () => {
    const h = await openEditOnWater();
    const update = vi.spyOn(TestBed.inject(SalesApi), 'updateLine');
    typeInto(quantityBox(h), ' 3 ');
    await save(h);
    expect(update.mock.calls[0]?.[2]).toEqual({ quantity: '3' });
    expect(h.query('[role="dialog"]')).toBeNull();
    const line = storeOf(h, SaleStore).lines()[1]!;
    expect(line.quantity).toBe('3.0000');
    expect(line.line_total).toBe('1458.0000');
    expect(document.activeElement).toBe(byId(h, 'scan-field'));
  });

  it('changes the unit and lets the server reprice it', async () => {
    const h = await openEditOnWater();
    const update = vi.spyOn(TestBed.inject(SalesApi), 'updateLine');
    const select = byId<HTMLSelectElement>(h, 'edit-unit');
    const each = Array.from(select.options).find((o) => o.textContent?.trim() === 'ea');
    select.value = each?.value ?? '';
    typeInto(quantityBox(h), '2');
    await save(h);
    expect(Object.keys(update.mock.calls[0]?.[2] ?? {}).sort()).toEqual(['quantity', 'unit_id']);
    const line = storeOf(h, SaleStore).lines()[1]!;
    expect(line.unit_code).toBe('ea');
    expect(line.unit_price).toBe('80.0000');
    expect(line.quantity).toBe('2.0000');
  });

  it.each([
    ['', 'Quantity is required'],
    ['0', 'Quantity must be more than zero'],
    ['-1', 'Quantity cannot be negative'],
    ['abc', 'Quantity must be a number such as 12.50'],
    ['1.00005', 'Quantity can have at most 4 decimal places'],
  ])('refuses "%s" in the form before any call', async (text, message) => {
    const h = await openEditOnWater();
    const update = vi.spyOn(TestBed.inject(SalesApi), 'updateLine');
    typeInto(quantityBox(h), text);
    await save(h);
    expect(h.text('edit-error')).toBe(message);
    expect(update).not.toHaveBeenCalled();
    expect(h.query('[role="dialog"]')).not.toBeNull();
    expect(storeOf(h, SaleStore).lines()[1]?.quantity).toBe('1.0000');
  });

  it('cancels on Esc with nothing sent and the focus back on the scan field', async () => {
    const h = await openEditOnWater();
    const update = vi.spyOn(TestBed.inject(SalesApi), 'updateLine');
    typeInto(quantityBox(h), '9');
    h.press('Escape');
    await h.settle();
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(update).not.toHaveBeenCalled();
    expect(document.activeElement).toBe(byId(h, 'scan-field'));
  });

  it('keeps the dialog open and shows the refusal when the server refuses', async () => {
    const h = await openEditOnWater();
    const select = byId<HTMLSelectElement>(h, 'edit-unit');
    // A unit the product does not have: the till would not offer it, the server still refuses it.
    const option = document.createElement('option');
    option.value = '01JMCKN0000000000000000002';
    option.textContent = 'kg';
    select.append(option);
    select.value = option.value;
    clearNotes(h);
    await save(h);
    expect(h.query('[role="dialog"]')).not.toBeNull();
    expect(h.text('message-bar')).toContain('This unit does not apply to the product');
    expect(storeOf(h, SaleStore).lines()[1]?.unit_code).toBe('box');
  });
});
