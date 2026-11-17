/**
 * Helpers for the specs of voiding (T-037 A3): getting a mock sale into the cart the way the
 * server would have sent it, and driving the void and find-sale dialogs through the DOM.
 */
import { BranchState, Sale, branchState } from '@flexipos/shared';
import { DialogHost } from '../state/dialog-host';
import { SaleStore } from '../state/sale-store';
import { HARNESS_TENANT_ID, TillHarness } from './till-harness';

export function storeOf<T>(h: TillHarness, token: abstract new (...args: never[]) => T): T {
  return h.fixture.debugElement.injector.get(token);
}

export function dialogsOf(h: TillHarness): DialogHost {
  return storeOf(h, DialogHost);
}

/** The mock's stored sales of the harness branch. */
export function mockSales(h: TillHarness): BranchState['sales'] {
  return branchState(h.state, HARNESS_TENANT_ID, 'BR001').sales;
}

/** Puts the mock's nth sale (default the first) into the cart, as a copy of what the server holds. */
export function loadSale(h: TillHarness, index = 0): Sale {
  const stored = mockSales(h)[index];
  const sale = JSON.parse(JSON.stringify(stored?.data)) as Sale;
  storeOf(h, SaleStore).load(sale);
  return sale;
}

export function reasonField(h: TillHarness): HTMLInputElement {
  const field = h.query<HTMLInputElement>('pos-void-dialog input[name="reason"]');
  if (!field) {
    throw new Error('The void dialog has no reason field.');
  }
  return field;
}

/** Types a reason into the void dialog. */
export function typeReason(h: TillHarness, text: string): void {
  const field = reasonField(h);
  field.value = text;
  field.dispatchEvent(new Event('input', { bubbles: true }));
}

/** Types a sale number into the find-sale dialog and submits the search. */
export function searchNumber(h: TillHarness, text: string): void {
  const field = h.query<HTMLInputElement>('pos-find-sale-dialog input[name="number"]');
  if (!field) {
    throw new Error('The find-sale dialog is not open.');
  }
  field.value = text;
  field.form?.requestSubmit();
}

/** The rows of the find-sale list, as text. */
export function findSaleRows(h: TillHarness): string[] {
  return Array.from(h.host.querySelectorAll('pos-find-sale-dialog button.row')).map((row) =>
    (row.textContent ?? '').replace(/\s+/g, ' ').trim(),
  );
}

/** Lets a chain of calls (decide, then void, then the answer) run to its end. */
export async function settleAll(h: TillHarness): Promise<void> {
  for (let round = 0; round < 4; round++) {
    await h.settle();
  }
}

/** The heading of the dialog on top of the stack. */
export function topHeading(h: TillHarness): string {
  const dialogs = h.host.querySelectorAll('[role="dialog"] h2');
  return dialogs.item(dialogs.length - 1)?.textContent?.trim() ?? '';
}
