import { DOCUMENT } from '@angular/common';
import { Injectable, computed, inject, signal } from '@angular/core';

/**
 * Every dialog the till has, named up front (T-037 AC-21, AC-82), so the one outlet on `TillPage`
 * never changes after Chunk 0: a later chunk fills in its own component and nothing else.
 */
export type DialogKind =
  | 'weight-prompt'
  | 'line-edit'
  | 'price-override'
  | 'discount-picker'
  | 'customer'
  | 'payment'
  | 'recall'
  | 'confirm'
  | 'shift-menu'
  | 'supervisor'
  | 'void'
  | 'find-sale'
  | 'refund'
  | 'help';

export interface OpenDialog {
  /** Unique per opening, so the outlet can tell two openings of one kind apart. */
  readonly id: number;
  readonly kind: DialogKind;
  readonly data: unknown;
  /** The control to give the focus back to; null means the scan field. */
  readonly opener: HTMLElement | null;
}

/** The attribute the scan field carries so a closing dialog can return the focus to it. */
export const SCAN_FIELD_ATTRIBUTE = 'data-till-scan-field';

/**
 * The stack of open dialogs. A dialog can open another (a price override asks a supervisor), so
 * the host keeps a stack; the outlet shows them all and only the top one is live. Closing
 * resolves the promise `open()` returned and returns the focus to the control that opened it, or
 * to the scan field.
 */
@Injectable()
export class DialogHost {
  private readonly doc = inject(DOCUMENT);
  private readonly stack = signal<OpenDialog[]>([]);
  private readonly resolvers = new Map<number, (result: unknown) => void>();
  private nextId = 1;

  /** Every open dialog, bottom first. */
  readonly dialogs = this.stack.asReadonly();
  /** The one the cashier is looking at, or null. */
  readonly current = computed(() => this.stack().at(-1) ?? null);
  readonly isOpen = computed(() => this.stack().length > 0);

  /**
   * Opens a dialog. The promise resolves with whatever the dialog closes with (`undefined` when
   * it is cancelled). `opener` defaults to the element that has the focus now.
   */
  open(kind: DialogKind, data?: unknown, opener?: HTMLElement | null): Promise<unknown> {
    const active = this.doc.activeElement;
    const from =
      opener !== undefined
        ? opener
        : active instanceof HTMLElement && active !== this.doc.body
          ? active
          : null;
    const dialog: OpenDialog = { id: this.nextId, kind, data, opener: from };
    this.nextId = this.nextId + 1;
    this.stack.update((open) => [...open, dialog]);
    return new Promise((resolve) => this.resolvers.set(dialog.id, resolve));
  }

  /** Closes the top dialog and gives the result to whoever opened it. */
  close(result?: unknown): void {
    const top = this.current();
    if (!top) {
      return;
    }
    this.stack.update((open) => open.slice(0, -1));
    this.resolvers.get(top.id)?.(result);
    this.resolvers.delete(top.id);
    this.returnFocus(top);
  }

  /** Closes every dialog (sign out, switch branch), each with `undefined`. */
  closeAll(): void {
    while (this.isOpen()) {
      this.close(undefined);
    }
  }

  /** Puts the focus on the scan field. */
  focusScanField(): void {
    this.doc.querySelector<HTMLElement>(`[${SCAN_FIELD_ATTRIBUTE}]`)?.focus();
  }

  private returnFocus(closed: OpenDialog): void {
    const opener = closed.opener;
    if (opener && opener.isConnected && !opener.hasAttribute('disabled')) {
      opener.focus();
    } else {
      this.focusScanField();
    }
  }
}
