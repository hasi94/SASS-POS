import { SaleSummary } from '@flexipos/shared';
import { setupTill } from '../../testing/till-harness';
import { dialogsOf, findSaleRows, mockSales, searchNumber } from '../../testing/void-helpers';

const TWO_COMPLETED = [
  'shift_open',
  'completed_sale_open_shift',
  'completed_sale_open_shift',
  'draft_sale_with_line',
];

describe('the find-sale dialog (AC-56)', () => {
  it("lists this shift's completed sales, latest first, and nothing else", async () => {
    const h = await setupTill({ given: TWO_COMPLETED });
    void dialogsOf(h).open('find-sale');
    await h.settle();
    const rows = findSaleRows(h);
    expect(rows.length).toBe(2);
    expect(rows[0]).toContain('INV-000002');
    expect(rows[1]).toContain('INV-000001');
    expect(rows[0]).toContain('500.00 LKR');
    expect(document.activeElement).toBe(h.query('pos-find-sale-dialog input[name="number"]'));
  });

  it('finds a sale by its number, and says so when there is none', async () => {
    const h = await setupTill({ given: TWO_COMPLETED });
    void dialogsOf(h).open('find-sale');
    await h.settle();
    searchNumber(h, 'INV-000001');
    await h.settle();
    expect(findSaleRows(h).length).toBe(1);
    expect(findSaleRows(h)[0]).toContain('INV-000001');
    searchNumber(h, 'INV-999999');
    await h.settle();
    expect(findSaleRows(h)).toEqual([]);
    expect(h.text('find-sale-empty')).toBe('No sales found');
    // An empty number lists the latest again.
    searchNumber(h, '');
    await h.settle();
    expect(findSaleRows(h).length).toBe(2);
  });

  it('does not list a sale of an earlier, closed shift', async () => {
    const h = await setupTill({
      given: [
        'shift_open',
        'completed_sale_closed_shift',
        'shift_open',
        'completed_sale_open_shift',
      ],
    });
    void dialogsOf(h).open('find-sale');
    await h.settle();
    const rows = findSaleRows(h);
    expect(rows.length).toBe(1);
    expect(rows[0]).toContain('INV-000002');
  });

  it('closes with the chosen sale', async () => {
    const h = await setupTill({ given: TWO_COMPLETED });
    const chosen = dialogsOf(h).open('find-sale');
    await h.settle();
    h.query<HTMLButtonElement>('pos-find-sale-dialog button.row')?.click();
    await h.settle();
    const row = (await chosen) as SaleSummary;
    expect(row.id).toBe(mockSales(h)[1]?.data.id);
    expect(row.number).toBe('INV-000002');
    expect(h.query('[role="dialog"]')).toBeNull();
  });

  it('closes with nothing on Esc', async () => {
    const h = await setupTill({ given: TWO_COMPLETED });
    const chosen = dialogsOf(h).open('find-sale');
    await h.settle();
    h.press('Escape');
    await h.settle();
    expect(await chosen).toBeUndefined();
  });

  it('moves between rows with Up and Down', async () => {
    const h = await setupTill({ given: TWO_COMPLETED });
    void dialogsOf(h).open('find-sale');
    await h.settle();
    const rows = Array.from(
      h.host.querySelectorAll<HTMLButtonElement>('pos-find-sale-dialog button.row'),
    );
    rows[0]?.focus();
    h.press('ArrowDown');
    expect(document.activeElement).toBe(rows[1]);
    h.press('ArrowUp');
    expect(document.activeElement).toBe(rows[0]);
  });

  it('lists the statuses the opener asks for', async () => {
    const h = await setupTill({
      given: ['shift_open', 'completed_sale_open_shift', 'draft_sale_with_line'],
    });
    void dialogsOf(h).open('find-sale', { statuses: ['draft'] });
    await h.settle();
    const rows = findSaleRows(h);
    expect(rows.length).toBe(1);
    expect(rows[0]).toContain('S-0002');
  });

  it('shows why when the list cannot be read', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    h.state.faults.unreachable = true;
    void dialogsOf(h).open('find-sale');
    await h.settle();
    expect(h.text('find-sale-problem')).toContain('Cannot reach the server');
  });
});
