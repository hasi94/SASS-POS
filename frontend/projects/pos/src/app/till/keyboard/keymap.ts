import { SaleStatus } from '@flexipos/shared';

/**
 * The till's key map as data (T-037 D-22, AC-80): one place to read it and one place to change
 * it. `TillPage` maps a key event through {@link matchKey} to an action; the help dialog lists
 * {@link KEYMAP}. Browser-reserved F5, F11 and F12 are deliberately unbound.
 */

export type TillAction =
  | 'pay'
  | 'hold'
  | 'recall'
  | 'customer'
  | 'refund'
  | 'void'
  | 'shift-menu'
  | 'search'
  | 'remove-line'
  | 'select-previous'
  | 'select-next'
  | 'confirm'
  | 'cancel'
  | 'help';

/**
 * What a binding does while a text field has the focus. `when-empty` keys (`/`, `?`, `Delete`,
 * the arrows) belong to the field unless it is empty, so a scanned code containing `/` is typed
 * and not taken as a command. The default is `always`.
 */
export type TypingPolicy = 'always' | 'when-empty';

export interface KeyBinding {
  /** A `KeyboardEvent.key` value. Letters are compared without regard to case. */
  key: string;
  ctrl?: boolean;
  typing?: TypingPolicy;
}

/** What the till knows when a key is pressed. */
export interface KeymapContext {
  shiftOpen: boolean;
  lineCount: number;
  hasSelectedLine: boolean;
  saleStatus: SaleStatus | null;
  dialogOpen: boolean;
}

export interface KeymapEntry {
  action: TillAction;
  keys: readonly KeyBinding[];
  label: string;
  /**
   * `page` bindings are handled by the `TillPage` dispatcher; a `control` binding (Enter) belongs
   * to whichever control has the focus and the dispatcher leaves it alone.
   */
  scope: 'page' | 'control';
  /** Null when the action may run now, otherwise the reason it cannot (shown to the cashier). */
  available(ctx: KeymapContext): string | null;
}

const NEEDS_SHIFT = 'Open a shift first';

function needsShift(ctx: KeymapContext): string | null {
  return ctx.shiftOpen ? null : NEEDS_SHIFT;
}

function always(): null {
  return null;
}

export const KEYMAP: readonly KeymapEntry[] = [
  {
    action: 'pay',
    keys: [{ key: 'F2' }],
    label: 'Pay',
    scope: 'page',
    available: (ctx) => {
      if (!ctx.shiftOpen) {
        return NEEDS_SHIFT;
      }
      if (ctx.lineCount === 0) {
        return 'Add a product before paying';
      }
      if (
        ctx.saleStatus !== null &&
        ctx.saleStatus !== 'draft' &&
        ctx.saleStatus !== 'pending_payment'
      ) {
        return 'This sale is not open for payment';
      }
      return null;
    },
  },
  {
    action: 'hold',
    keys: [{ key: 'F3' }],
    label: 'Hold the sale',
    scope: 'page',
    available: (ctx) => {
      if (!ctx.shiftOpen) {
        return NEEDS_SHIFT;
      }
      if (ctx.lineCount === 0) {
        return 'There is nothing to hold';
      }
      if (
        ctx.saleStatus !== null &&
        ctx.saleStatus !== 'draft' &&
        ctx.saleStatus !== 'pending_payment'
      ) {
        return 'Only a draft or part-paid sale can be held';
      }
      return null;
    },
  },
  {
    action: 'recall',
    keys: [{ key: 'F4' }],
    label: 'Recall a held sale',
    scope: 'page',
    available: needsShift,
  },
  {
    action: 'customer',
    keys: [{ key: 'F6' }],
    label: 'Customer',
    scope: 'page',
    available: needsShift,
  },
  {
    action: 'refund',
    keys: [{ key: 'F7' }],
    label: 'Refund',
    scope: 'page',
    available: needsShift,
  },
  {
    action: 'void',
    keys: [{ key: 'F8' }],
    label: 'Void a sale',
    scope: 'page',
    available: needsShift,
  },
  {
    action: 'shift-menu',
    keys: [{ key: 'F9' }],
    label: 'Shift menu',
    scope: 'page',
    available: (ctx) => (ctx.shiftOpen ? null : 'There is no open shift'),
  },
  {
    action: 'search',
    keys: [
      { key: '/', typing: 'when-empty' },
      { key: 'k', ctrl: true },
    ],
    label: 'Search products',
    scope: 'page',
    available: always,
  },
  {
    action: 'remove-line',
    keys: [{ key: 'Delete', typing: 'when-empty' }],
    label: 'Remove the selected line',
    scope: 'page',
    available: (ctx) => (ctx.hasSelectedLine ? null : 'Select a line first'),
  },
  {
    action: 'select-previous',
    keys: [{ key: 'ArrowUp', typing: 'when-empty' }],
    label: 'Select the previous line',
    scope: 'page',
    available: (ctx) => (ctx.lineCount > 0 ? null : 'The cart is empty'),
  },
  {
    action: 'select-next',
    keys: [{ key: 'ArrowDown', typing: 'when-empty' }],
    label: 'Select the next line',
    scope: 'page',
    available: (ctx) => (ctx.lineCount > 0 ? null : 'The cart is empty'),
  },
  {
    action: 'confirm',
    keys: [{ key: 'Enter' }],
    label: 'Confirm the primary action',
    scope: 'control',
    available: always,
  },
  {
    action: 'cancel',
    keys: [{ key: 'Escape' }],
    label: 'Cancel and return to the scan field',
    scope: 'page',
    available: always,
  },
  {
    action: 'help',
    keys: [{ key: '?', typing: 'when-empty' }, { key: 'F1' }],
    label: 'Show this list',
    scope: 'page',
    available: always,
  },
];

/** The slice of a keyboard event the map reads (a real event, or a test double). */
export interface KeyLike {
  key: string;
  ctrlKey: boolean;
  altKey: boolean;
  metaKey: boolean;
  shiftKey: boolean;
}

function sameKey(binding: KeyBinding, event: KeyLike): boolean {
  if (binding.key.length === 1) {
    return binding.key.toLowerCase() === event.key.toLowerCase();
  }
  return binding.key === event.key;
}

export interface KeyMatch {
  entry: KeymapEntry;
  binding: KeyBinding;
}

/** The entry a key event is bound to, or null. Shift is ignored only for the printable `?`. */
export function matchKey(event: KeyLike): KeyMatch | null {
  if (event.altKey || event.metaKey) {
    return null;
  }
  for (const entry of KEYMAP) {
    for (const binding of entry.keys) {
      const wantsCtrl = binding.ctrl === true;
      const shiftOk = !event.shiftKey || binding.key === '?';
      if (sameKey(binding, event) && event.ctrlKey === wantsCtrl && shiftOk) {
        return { entry, binding };
      }
    }
  }
  return null;
}

/**
 * Why `entry` cannot run now, or null. While a dialog is open only `cancel` runs: everything
 * else would act on a screen the cashier cannot see.
 */
export function unavailableReason(entry: KeymapEntry, ctx: KeymapContext): string | null {
  if (ctx.dialogOpen && entry.action !== 'cancel') {
    return 'Close this dialog first';
  }
  return entry.available(ctx);
}

/** `F2`, `Ctrl+K`, `/`: how a binding reads in the help list. */
export function formatBinding(binding: KeyBinding): string {
  const names: Record<string, string> = { ArrowUp: 'Up', ArrowDown: 'Down', Escape: 'Esc' };
  const key =
    names[binding.key] ?? (binding.key.length === 1 ? binding.key.toUpperCase() : binding.key);
  return binding.ctrl ? `Ctrl+${key}` : key;
}

/** `N*` typed in the scan field before a code: a positive whole number of at most three digits. */
export const MULTIPLIER = /^([1-9][0-9]{0,2})\*$/;

/** The multiplier in `text` as the digits typed (never multiplied by the till), or null. */
export function parseMultiplier(text: string): string | null {
  const match = MULTIPLIER.exec(text.trim());
  return match ? (match[1] ?? null) : null;
}
