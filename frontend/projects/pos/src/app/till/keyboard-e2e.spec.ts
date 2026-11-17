import { SALES_MOCK_DEMO } from '@flexipos/shared';
import { KEYMAP, TillAction } from './keyboard/keymap';
import { DialogHost } from './state/dialog-host';
import { SaleStore } from './state/sale-store';
import {
  byId,
  scan,
  serverSales,
  settleFully,
  storeOf,
  typeInto,
} from './testing/sale-screen-helpers';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from './testing/till-harness';
import { topHeading } from './testing/void-helpers';

/**
 * Every binding of the key map (T-037 AC-80), driven the way a keyboard drives it: a
 * `KeyboardEvent` on whatever has the focus, no pointer. Each case records the action it covered,
 * and the last case fails when the key map has an action no case drove.
 */
const covered = new Set<TillAction>();

async function loaded(
  given: string[] = ['shift_open', 'draft_sale_with_line'],
): Promise<TillHarness> {
  const h = await setupTill({ given });
  const stored = serverSales(h, HARNESS_TENANT_ID, 'BR001')[0];
  if (stored) {
    storeOf(h, SaleStore).load(JSON.parse(JSON.stringify(stored)));
    await h.settle();
  }
  return h;
}

/** Presses a key and lets the page catch up. */
async function key(h: TillHarness, name: string, modifiers = {}): Promise<KeyboardEvent> {
  const event = h.press(name, modifiers);
  await settleFully(h);
  return event;
}

const dialogOpen = (h: TillHarness) => h.query('[role="dialog"]') !== null;
const scanField = (h: TillHarness) => h.query('[data-till-scan-field]');

describe('The keyboard map, end to end (AC-80)', () => {
  describe('the dialog keys', () => {
    const dialogs: [string, string, string, TillAction][] = [
      ['F4', 'Recall a held sale', 'recall', 'recall'],
      ['F6', 'Customer', 'customer', 'customer'],
      ['F7', 'Refund', 'refund', 'refund'],
      ['F8', 'Void a sale', 'void', 'void'],
      ['F9', 'Shift', 'shift-menu', 'shift-menu'],
      ['F2', 'Payment', 'pay', 'pay'],
    ];

    it.each(dialogs)(
      '%s opens "%s", Esc closes it and returns to the scan field',
      async (name, heading, _id, action) => {
        const h = await loaded();
        expect((await key(h, name)).defaultPrevented).toBe(true);
        expect(dialogOpen(h)).toBe(true);
        expect(topHeading(h)).toBe(heading);
        const dialog = h.query('[role="dialog"]');
        expect(dialog?.contains(document.activeElement)).toBe(true);
        await key(h, 'Escape');
        expect(dialogOpen(h)).toBe(false);
        expect(document.activeElement).toBe(scanField(h));
        covered.add(action);
      },
    );

    it('F1 and ? show the shortcut list, and it names every binding', async () => {
      const h = await loaded();
      await key(h, 'F1');
      expect(topHeading(h)).toBe('Keyboard shortcuts');
      const list = byId(h, 'shortcut-list').textContent ?? '';
      for (const label of ['F2', 'F3', 'F4', 'F6', 'F7', 'F8', 'F9', 'Ctrl+K', 'Delete', 'Esc']) {
        expect(list, label).toContain(label);
      }
      await key(h, 'Escape');
      expect(dialogOpen(h)).toBe(false);
      await key(h, '?', { shift: true });
      expect(topHeading(h)).toBe('Keyboard shortcuts');
      covered.add('help');
    });
  });

  describe('Hold (F3)', () => {
    it('holds the sale in the cart and leaves an empty cart with the scan field focused', async () => {
      const h = await loaded();
      expect(storeOf(h, SaleStore).lineCount()).toBe(1);
      await key(h, 'F3');
      expect(storeOf(h, SaleStore).current()).toBeNull();
      expect(serverSales(h, HARNESS_TENANT_ID, 'BR001')[0]?.status).toBe('held');
      expect(document.activeElement).toBe(scanField(h));
      covered.add('hold');
    });
  });

  describe('Search (/ and Ctrl+K)', () => {
    it('focus the product search from the scan field and from the grid', async () => {
      const h = await loaded();
      const search = h.query('pos-product-search [data-till-search-field]');
      await key(h, 'k', { ctrl: true });
      expect(document.activeElement).toBe(search);
      scanField(h)?.focus();
      await key(h, '/');
      expect(document.activeElement).toBe(search);
      covered.add('search');
    });
  });

  describe('The cart keys (Up, Down, Delete)', () => {
    it('Down and Up select a line, Delete asks and a second Delete removes it', async () => {
      const h = await loaded();
      const store = storeOf(h, SaleStore);
      const lineId = store.lines()[0]!.id;
      expect(store.selectedLineId()).toBeNull();
      await key(h, 'ArrowDown');
      expect(store.selectedLineId()).toBe(lineId);
      covered.add('select-next');
      await key(h, 'ArrowUp');
      expect(store.selectedLineId()).toBe(lineId);
      covered.add('select-previous');

      await key(h, 'Delete');
      expect(store.lineCount()).toBe(1);
      expect(store.confirmingRemoval()).toBe(lineId);
      await key(h, 'Delete');
      expect(store.lineCount()).toBe(0);
      covered.add('remove-line');
    });

    it('Esc keeps the line when the confirmation is on screen', async () => {
      const h = await loaded();
      const store = storeOf(h, SaleStore);
      await key(h, 'ArrowDown');
      await key(h, 'Delete');
      await key(h, 'Escape');
      expect(store.lineCount()).toBe(1);
    });
  });

  describe('Enter confirms the primary action of the dialog that has the focus', () => {
    it('the weight prompt takes the weight and adds the line', async () => {
      const h = await loaded(['shift_open']);
      await scan(h, SALES_MOCK_DEMO.weighablePlu);
      await settleFully(h);
      expect(topHeading(h)).toBe('Weight');
      typeInto(byId<HTMLInputElement>(h, 'weight-input'), '2');
      await key(h, 'Enter');
      expect(dialogOpen(h)).toBe(false);
      const sale = storeOf(h, SaleStore).current();
      expect(sale?.lines).toHaveLength(1);
      expect(sale?.lines[0]?.quantity).toBe('2.0000');
      covered.add('confirm');
    });
  });

  describe('Esc', () => {
    it('closes the top dialog only, then dismisses a message, then returns to the scan field', async () => {
      const h = await loaded();
      await key(h, 'F9');
      void storeOf(h, DialogHost).open('confirm', {
        heading: 'Sure?',
        message: 'Really?',
        confirmLabel: 'Yes',
        cancelLabel: 'No',
      });
      await settleFully(h);
      expect(topHeading(h)).toBe('Sure?');
      await key(h, 'Escape');
      expect(topHeading(h)).toBe('Shift');
      await key(h, 'Escape');
      expect(dialogOpen(h)).toBe(false);
      expect(document.activeElement).toBe(scanField(h));

      await key(h, 'F6');
      await key(h, 'Escape');
      storeOf(h, SaleStore).clear();
      await key(h, 'F2');
      expect(h.query('[data-testid="message-bar"]')).not.toBeNull();
      h.query<HTMLInputElement>('[data-till-search-field]')?.focus();
      await key(h, 'Escape');
      expect(h.query('[data-testid="message-bar"]')).toBeNull();
      await key(h, 'Escape');
      expect(document.activeElement).toBe(scanField(h));
      covered.add('cancel');
    });
  });

  describe('a binding that is not available posts its reason and does nothing', () => {
    const cases: [string, string[], string, string][] = [
      ['F2', ['shift_open'], 'Add a product before paying', 'empty cart'],
      ['F3', ['shift_open'], 'There is nothing to hold', 'empty cart'],
      ['Delete', ['shift_open'], 'Select a line first', 'no selected line'],
      ['ArrowDown', ['shift_open'], 'The cart is empty', 'empty cart'],
      ['F4', [], 'Open a shift first', 'no shift'],
      ['F6', [], 'Open a shift first', 'no shift'],
      ['F9', [], 'There is no open shift', 'no shift'],
    ];

    it.each(cases)('%s says "%s" (%s)', async (name, given, reason) => {
      const h = await setupTill({ given });
      scanField(h)?.blur();
      const event = await key(h, name);
      expect(event.defaultPrevented).toBe(true);
      expect(dialogOpen(h)).toBe(false);
      expect(h.text('message-bar')).toContain(reason);
      expect(h.text('polite-region')).toContain(reason);
      expect(serverSales(h, HARNESS_TENANT_ID, 'BR001')).toHaveLength(0);
    });

    it('anything but Esc says to close the open dialog first', async () => {
      const h = await loaded();
      await key(h, 'F9');
      await key(h, 'F3');
      expect(h.text('message-bar')).toContain('Close this dialog first');
      expect(topHeading(h)).toBe('Shift');
      expect(storeOf(h, SaleStore).lineCount()).toBe(1);
    });
  });

  it('has driven every action of the key map', () => {
    const missing = KEYMAP.map((e) => e.action).filter((a) => !covered.has(a));
    expect(missing).toEqual([]);
  });
});
