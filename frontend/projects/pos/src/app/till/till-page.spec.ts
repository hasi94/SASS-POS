import { Sale, branchState, contextGuard } from '@flexipos/shared';
import { routes } from '../app.routes';
import { KEYMAP } from './keyboard/keymap';
import { SaleStore } from './state/sale-store';
import { ShiftStore } from './state/shift-store';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from './testing/till-harness';

function storeOf<T>(harness: TillHarness, token: abstract new (...args: never[]) => T): T {
  return harness.fixture.debugElement.injector.get(token);
}

/** Puts the mock's first sale into the cart, as the server would have sent it. */
function loadFirstSale(harness: TillHarness): Sale {
  const stored = branchState(harness.state, HARNESS_TENANT_ID, 'BR001').sales[0];
  const sale = JSON.parse(JSON.stringify(stored?.data)) as Sale;
  storeOf(harness, SaleStore).load(sale);
  return sale;
}

describe('TillPage (T-037 scaffold)', () => {
  describe('the frame', () => {
    it('shows who is signed in, where, and the open shift', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      expect(h.text('tenant')).toBe('ABC Pharmacy');
      expect(h.text('branch')).toBe('Colombo 03');
      expect(h.text('employee')).toBe('Demo Owner');
      expect(h.text('shift')).toBe('Shift SH-000001');
      expect(h.query('[data-testid="shift-open-prompt"]')).toBeNull();
    });

    it('offers the shift prompt when there is no open shift', async () => {
      const h = await setupTill();
      expect(h.text('shift')).toBe('No open shift');
      expect(h.query('[data-testid="shift-open-prompt"]')).not.toBeNull();
    });

    it('focuses the scan field on load (AC-21)', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      expect(document.activeElement).toBe(h.query('[data-till-scan-field]'));
    });

    it('lays out the scan field, the search, the grid, the cart and the message bar', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      for (const selector of [
        'pos-network-banner',
        'pos-scan-field',
        'pos-product-search',
        'pos-product-grid',
        'pos-cart',
        'pos-completion-panel',
        'pos-message-bar',
      ]) {
        expect(h.query(selector), selector).not.toBeNull();
      }
    });

    it('keeps /till behind the context guard (AC-90)', () => {
      const till = routes.find((r) => r.path === 'till');
      expect(till?.canActivate).toEqual([contextGuard]);
    });
  });

  describe('the key dispatcher (AC-80)', () => {
    it('opens the shift menu on F9, focuses its first control, and returns the focus on Esc', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const scan = h.query('[data-till-scan-field]');
      expect(h.press('F9').defaultPrevented).toBe(true);
      await h.settle();
      const dialog = h.query('[role="dialog"]');
      expect(dialog).not.toBeNull();
      expect(dialog?.getAttribute('aria-modal')).toBe('true');
      expect(dialog?.contains(document.activeElement)).toBe(true);
      h.press('Escape');
      await h.settle();
      expect(h.query('[role="dialog"]')).toBeNull();
      expect(document.activeElement).toBe(scan);
    });

    it('opens every dialog key', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const opens: [string, string][] = [
        ['F4', 'Recall a held sale'],
        ['F6', 'Customer'],
        ['F7', 'Refund'],
        ['F8', 'Void a sale'],
        ['F9', 'Shift'],
        ['F1', 'Keyboard shortcuts'],
      ];
      for (const [key, heading] of opens) {
        h.press(key);
        await h.settle();
        expect(h.query('[role="dialog"] h2')?.textContent?.trim(), key).toBe(heading);
        h.press('Escape');
        await h.settle();
      }
    });

    it('lists every binding in the help dialog', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      h.press('?', { shift: true });
      await h.settle();
      const list = h.text('shortcut-list') ?? '';
      expect(h.query('[data-testid="shortcut-list"]')).not.toBeNull();
      for (const entry of KEYMAP) {
        expect(list, entry.action).toContain(entry.label);
      }
      expect(list).toContain('F2');
      expect(list).toContain('Ctrl+K');
    });

    it('says why F2 does nothing on an empty cart', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const event = h.press('F2');
      await h.settle();
      expect(event.defaultPrevented).toBe(true);
      expect(h.query('[role="dialog"]')).toBeNull();
      expect(h.text('message-bar')).toContain('Add a product before paying');
    });

    it('says why when there is no open shift', async () => {
      const h = await setupTill();
      h.press('F4');
      await h.settle();
      expect(h.query('[role="dialog"]')).toBeNull();
      expect(h.text('message-bar')).toContain('Open a shift first');
    });

    it('opens payment on F2 once the cart has a line', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      loadFirstSale(h);
      h.press('F2');
      await h.settle();
      expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Payment');
    });

    it('selects lines with the arrows and says so when none is selected for Delete', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      const sale = loadFirstSale(h);
      const store = storeOf(h, SaleStore);
      h.press('Delete');
      await h.settle();
      expect(h.text('message-bar')).toContain('Select a line first');
      h.press('ArrowDown');
      expect(store.selectedLineId()).toBe(sale.lines[0]?.id);
    });

    it('leaves the browser-reserved keys alone', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      for (const key of ['F5', 'F11', 'F12']) {
        expect(h.press(key).defaultPrevented, key).toBe(false);
      }
      expect(h.query('[role="dialog"]')).toBeNull();
    });

    it('focuses the search field on Ctrl+K and on / in an empty scan field', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const search = h.query('[data-till-search-field]');
      h.press('k', { ctrl: true });
      expect(document.activeElement).toBe(search);
      h.query<HTMLInputElement>('[data-till-scan-field]')?.focus();
      h.press('/');
      expect(document.activeElement).toBe(search);
    });

    it('leaves `/` and `?` to the scan field once something is typed in it', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const scan = h.query<HTMLInputElement>('[data-till-scan-field]');
      scan!.value = '12';
      expect(h.press('/').defaultPrevented).toBe(false);
      expect(h.press('?', { shift: true }).defaultPrevented).toBe(false);
      expect(document.activeElement).toBe(scan);
      expect(h.query('[role="dialog"]')).toBeNull();
    });

    it('ignores text keys while a dialog is open, and refuses function keys with a reason', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      h.press('F9');
      await h.settle();
      expect(h.press('ArrowDown').defaultPrevented).toBe(false);
      h.press('F3');
      await h.settle();
      expect(h.text('message-bar')).toContain('Close this dialog first');
      expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Shift');
    });

    it('dismisses a message with Esc, then returns to the scan field', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      h.press('F2');
      await h.settle();
      expect(h.query('[data-testid="message-bar"]')).not.toBeNull();
      h.query<HTMLInputElement>('[data-till-search-field]')?.focus();
      h.press('Escape');
      await h.settle();
      expect(h.query('[data-testid="message-bar"]')).toBeNull();
      h.press('Escape');
      expect(document.activeElement).toBe(h.query('[data-till-scan-field]'));
    });

    it('announces what it says in a live region (AC-84)', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      h.press('F2');
      await h.settle();
      expect(h.text('polite-region')).toContain('Add a product before paying');
    });
  });

  describe('the stores', () => {
    it('read the shift through the server', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const shift = storeOf(h, ShiftStore);
      expect(shift.shiftOpen()).toBe(true);
      expect(shift.current()?.number).toBe('SH-000001');
    });

    it('reload the cart from the server and clear it', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      const store = storeOf(h, SaleStore);
      const sale = loadFirstSale(h);
      expect(store.lineCount()).toBe(1);
      store.load({ ...sale, lines: [] });
      expect(store.lineCount()).toBe(0);
      expect(await store.reload()).toBe(true);
      expect(store.lineCount()).toBe(1);
      store.clear();
      expect(store.current()).toBeNull();
    });
  });
});
