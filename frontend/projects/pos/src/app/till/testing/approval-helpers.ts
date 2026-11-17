/**
 * Helpers for the specs of the approval flow and everything that asks a supervisor (T-037 A2).
 * They drive the real supervisor dialog through the DOM, the way a person would.
 */
import { ApprovalFlow, ApprovalSummary } from '../state/approval-flow';
import { TillHarness } from './till-harness';

export const SUPERVISOR = { code: 'S100', pin: '2468' } as const;
export const CASHIER = { code: 'D001', pin: '1111' } as const;
export const NON_APPROVER = { code: 'C200', pin: '1357' } as const;

export const VOID_SUMMARY: ApprovalSummary = {
  title: 'Void sale INV-000001',
  lines: ['500.00 LKR'],
};

export function flowOf(h: TillHarness): ApprovalFlow {
  return h.fixture.debugElement.injector.get(ApprovalFlow);
}

export function codeField(h: TillHarness): HTMLInputElement {
  const field = h.query<HTMLInputElement>('pos-supervisor-dialog input[name="employee_code"]');
  if (!field) {
    throw new Error('The supervisor dialog is not open.');
  }
  return field;
}

export function pinField(h: TillHarness): HTMLInputElement {
  const field = h.query<HTMLInputElement>('pos-supervisor-dialog input[name="pin"]');
  if (!field) {
    throw new Error('The supervisor dialog is not open.');
  }
  return field;
}

/** Types a code and a PIN into the supervisor dialog. */
export function typeCredentials(h: TillHarness, code: string, pin: string): void {
  for (const [field, value] of [
    [codeField(h), code],
    [pinField(h), pin],
  ] as const) {
    field.value = value;
    field.dispatchEvent(new Event('input', { bubbles: true }));
  }
}

/** The dialog on top of the stack: the outlet renders every open dialog, bottom first. */
export function topDialog(h: TillHarness): HTMLElement | null {
  const dialogs = h.host.querySelectorAll<HTMLElement>('[role="dialog"]');
  return dialogs.item(dialogs.length - 1);
}

/** Clicks the button of the top dialog with this label. */
export function clickButton(h: TillHarness, label: string): void {
  const button = Array.from(topDialog(h)?.querySelectorAll<HTMLButtonElement>('button') ?? []).find(
    (b) => b.textContent?.trim() === label,
  );
  if (!button) {
    throw new Error(`No button "${label}" in the top dialog.`);
  }
  button.click();
}

/** The text of the supervisor dialog's message line. */
export function supervisorMessage(h: TillHarness): string {
  return h.text('supervisor-message') ?? '';
}

/** Every value in `sessionStorage` and `localStorage`, joined, for a leak check. */
export function storedText(): string {
  const parts: string[] = [];
  for (const store of [sessionStorage, localStorage]) {
    for (let i = 0; i < store.length; i++) {
      const key = store.key(i);
      parts.push(key ?? '', key ? (store.getItem(key) ?? '') : '');
    }
  }
  return parts.join('\n');
}
