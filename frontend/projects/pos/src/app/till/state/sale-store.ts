import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import {
  ApiError,
  CustomerId,
  LINE_PRICER,
  Quantity,
  Sale,
  SaleLine,
  SaleStatus,
  SalesApi,
  SalesRefusalCode,
  TillProduct,
  UpdateLineRequest,
  asQuantity,
  refusalOf,
} from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { NETWORK_MESSAGE } from '../errors/refusal-catalogue';
import { Connectivity } from './connectivity';
import { DialogHost } from './dialog-host';
import { Notifier } from './notifier';
import { ShiftStore } from './shift-store';

/** Why a line cannot be changed once payment has started (AC-33). The catalogue says the same. */
export const PAYMENT_STARTED = 'Payment has started: remove the tenders to edit lines';

/** What one line action came to. The refusal has already been shown; the code is for the caller. */
export interface LineOutcome {
  ok: boolean;
  /** The refusal code, when the server refused with one of ours. */
  code: SalesRefusalCode | null;
  /** What the server (or the network) answered, so a dialog can show it in place. */
  error?: unknown;
}

/** How many held sales the recall list asks for at a time (AC-52). */
export const RECALL_PAGE_SIZE = 10;

/** A line to add: the product, the unit, and the quantity exactly as it is to be sent. */
export interface NewLine {
  productId: string;
  unitId: string;
  quantity: Quantity;
}

/**
 * The sale in the cart (T-037 AC-29 to AC-38). The server's `Sale` is the only truth: the store
 * replaces its state with whatever the server sent and never computes a figure (D-6).
 *
 * FROZEN SURFACE (Chunk 0). Track A calls only `current`, `reload()`, `clear()` and `load(sale)`;
 * the key-map dispatcher on `TillPage` calls the selection members and the two action stubs.
 * Step B4 filled in `removeSelectedLine()` and adds the line, add-product and removal members;
 * nothing listed in the Chunk 0 surface changed.
 *
 * Every line action runs through one queue, so two actions can never overlap and arrive at the
 * server in the order they were asked for (AC-28). The sale is created on the first line, never
 * before (AC-29, D-9).
 */
@Injectable()
export class SaleStore {
  private readonly api = inject(SalesApi);
  private readonly pricer = inject(LINE_PRICER);
  private readonly connectivity = inject(Connectivity);
  private readonly notifier = inject(Notifier);
  private readonly shift = inject(ShiftStore);
  private readonly dialogs = inject(DialogHost);
  private readonly sale = signal<Sale | null>(null);
  private readonly selected = signal<string | null>(null);
  private readonly confirmingLine = signal<string | null>(null);
  private readonly reloadNeeded = signal(false);
  private queue: Promise<unknown> = Promise.resolve();
  private resumeStarted = false;

  /** The sale in the cart, or null when the cart is empty and no sale exists yet (D-9). */
  readonly current = this.sale.asReadonly();
  readonly lines = computed<SaleLine[]>(() => this.sale()?.lines ?? []);
  readonly lineCount = computed(() => this.lines().length);
  readonly status = computed<SaleStatus | null>(() => this.sale()?.status ?? null);
  /** The line the Up and Down keys have selected. */
  readonly selectedLineId = this.selected.asReadonly();

  /** The line whose removal is waiting for Enter (AC-32), or null. */
  readonly confirmingRemoval = this.confirmingLine.asReadonly();
  /**
   * True after a line write whose answer never came (AC-75): the cart may not match the server,
   * so no line action is accepted until a reload has succeeded.
   */
  readonly needsReload = this.reloadNeeded.asReadonly();
  /** Why the lines cannot be changed now, or null when they can (AC-33). */
  readonly lockReason = computed<string | null>(() => {
    const sale = this.sale();
    if (!sale) {
      return null;
    }
    if (
      sale.status === 'pending_payment' ||
      (sale.status === 'held' && sale.held_from_status === 'pending_payment')
    ) {
      return PAYMENT_STARTED;
    }
    const editable =
      sale.status === 'draft' || (sale.status === 'held' && sale.held_from_status === 'draft');
    return editable ? null : 'This sale can no longer be changed';
  });
  readonly editable = computed(() => this.lockReason() === null);

  constructor() {
    // When the network comes back, the cart is read from the server before anything else (AC-75).
    effect(() => {
      if (this.connectivity.online() && this.reloadNeeded()) {
        untracked(() => void this.recover());
      }
    });
    // A till opened with an unfinished sale on the server picks it up instead of starting empty
    // (AC-88, D-9). Once per page, as soon as a shift is open and the server can be reached.
    effect(() => {
      if (this.shift.shiftOpen() && this.connectivity.online() && !this.resumeStarted) {
        untracked(() => void this.resume());
      }
    });
  }

  /** Puts a sale the server sent into the cart, replacing whatever was there. */
  load(sale: Sale): void {
    this.sale.set(sale);
    if (this.selected() !== null && !sale.lines.some((l) => l.id === this.selected())) {
      this.selected.set(null);
    }
    if (this.confirmingLine() !== null && !sale.lines.some((l) => l.id === this.confirmingLine())) {
      this.confirmingLine.set(null);
    }
  }

  /** Empties the cart. Nothing is created on the server (D-9). */
  clear(): void {
    this.sale.set(null);
    this.selected.set(null);
    this.confirmingLine.set(null);
    this.reloadNeeded.set(false);
  }

  /**
   * Asks the server for the sale again and shows what it says. Resolves true when the cart now
   * matches the server, false when the sale could not be read (the reason is shown).
   */
  async reload(): Promise<boolean> {
    const id = this.sale()?.id;
    if (!id) {
      return true;
    }
    try {
      this.load(await firstValueFrom(this.connectivity.track(this.api.getSale(id))));
      return true;
    } catch (err) {
      this.notifier.fail(err);
      return false;
    }
  }

  select(lineId: string | null): void {
    if (lineId !== this.selected()) {
      this.confirmingLine.set(null);
    }
    this.selected.set(lineId);
  }

  selectPrevious(): void {
    this.move(-1);
  }

  selectNext(): void {
    this.move(1);
  }

  /**
   * The Delete key (AC-32): the first press asks for confirmation on the selected line, a second
   * press, or Enter on the confirmation, removes it. Nothing is removed on the first press.
   */
  async removeSelectedLine(): Promise<void> {
    const id = this.selected();
    if (id === null) {
      return;
    }
    if (this.confirmingLine() === id) {
      await this.confirmRemoval();
    } else {
      this.requestRemoval(id);
    }
  }

  /** The Hold key (AC-51): parks the sale in the cart, empties the cart and shows its reference. */
  async hold(): Promise<void> {
    const id = this.sale()?.id;
    if (id !== undefined) {
      await this.holdSale(id);
    }
  }

  /**
   * Holds a sale of this shift (AC-51). When it is the sale in the cart the cart empties and the
   * scan field takes the focus; the confirmation names the held sale's short reference.
   */
  holdSale(saleId: string): Promise<LineOutcome> {
    return this.enqueue(async () => {
      if (!(await this.ready())) {
        return { ok: false, code: null };
      }
      try {
        const held = await firstValueFrom(this.connectivity.track(this.api.holdSale(saleId)));
        if (this.sale()?.id === saleId) {
          this.clear();
          if (!this.dialogs.isOpen()) {
            this.dialogs.focusScanField();
          }
        }
        this.notifier.success(held.reference ? `Sale ${held.reference} held` : 'Sale held');
        return { ok: true, code: null };
      } catch (err) {
        return this.failed(err, []);
      }
    });
  }

  /**
   * Brings a held sale into the cart in the status the server reports (AC-53). It never replaces
   * a sale that has lines: the caller holds or finishes that one first (AC-54).
   */
  recall(saleId: string): Promise<LineOutcome> {
    return this.enqueue(async () => {
      if (!(await this.ready())) {
        return { ok: false, code: null };
      }
      if (this.lineCount() > 0 && this.sale()?.id !== saleId) {
        this.notifier.info('Hold or finish the sale in the cart first');
        return { ok: false, code: null };
      }
      try {
        this.load(await firstValueFrom(this.connectivity.track(this.api.recallSale(saleId))));
        return { ok: true, code: null };
      } catch (err) {
        return this.failed(err, []);
      }
    });
  }

  /**
   * Puts a customer on the sale (AC-39), creating the sale first when the cart has none.
   * `not_found` (the customer is unknown, deleted or merged) is left for the caller to show.
   */
  attachCustomer(customerId: CustomerId): Promise<LineOutcome> {
    return this.enqueue(() =>
      this.write(() => {
        const sale = this.sale();
        const call = sale
          ? this.api.attachCustomer(sale.id, { customer_id: customerId })
          : this.api.createSale({ customer_id: customerId });
        return firstValueFrom(this.connectivity.track(call));
      }, ['not_found']),
    );
  }

  /** Selects the line and asks for confirmation before it is removed. */
  requestRemoval(lineId: string): void {
    const reason = this.lockReason();
    if (reason !== null) {
      this.notifier.info(reason);
      return;
    }
    this.selected.set(lineId);
    this.confirmingLine.set(lineId);
  }

  cancelRemoval(): void {
    this.confirmingLine.set(null);
  }

  /** Removes the line that was waiting for confirmation. */
  async confirmRemoval(): Promise<void> {
    const id = this.confirmingLine();
    if (id === null) {
      return;
    }
    this.confirmingLine.set(null);
    const name = this.lines().find((l) => l.id === id)?.product_name ?? 'line';
    const outcome = await this.removeLine(id);
    if (outcome.ok) {
      this.notifier.success(`Removed ${name}`);
    }
  }

  /**
   * Adds a product the cashier chose on the grid or in the search. A weighable product asks for
   * its weight first (AC-26); anything else goes in with quantity 1. Resolves true when a line
   * was added.
   */
  async addProduct(product: TillProduct): Promise<boolean> {
    if (!this.shift.shiftOpen()) {
      this.notifier.info('Open a shift first');
      return false;
    }
    let unitId = product.unit_id;
    let quantity: Quantity = asQuantity('1');
    if (product.is_weighable) {
      const entered = await this.dialogs.open('weight-prompt', { product }, null);
      if (typeof entered !== 'string') {
        return false;
      }
      quantity = asQuantity(entered);
      unitId = product.weigh_unit_id ?? product.unit_id;
    }
    const outcome = await this.addLine({ productId: product.id, unitId, quantity });
    if (outcome.ok) {
      this.notifier.success(`Added ${product.name}`);
    }
    return outcome.ok;
  }

  /**
   * Adds a line, creating the sale first when there is none (AC-29). The server's prices and
   * totals come back in the sale and replace the cart.
   */
  addLine(line: NewLine): Promise<LineOutcome> {
    return this.enqueue(() =>
      this.write(async () => {
        let sale = this.sale();
        if (!sale) {
          sale = await firstValueFrom(this.connectivity.track(this.api.createSale({})));
          this.load(sale);
        }
        return firstValueFrom(
          this.connectivity.track(
            this.pricer.addLine(sale.id, {
              product_id: line.productId,
              unit_id: line.unitId,
              quantity: line.quantity,
            }),
          ),
        );
      }),
    );
  }

  /**
   * Changes a line (quantity, unit, discount, price override). `handled` lists refusal codes the
   * caller deals with itself, so they are not announced here (a discount that turns out to need
   * a supervisor).
   */
  updateLine(
    lineId: string,
    request: UpdateLineRequest,
    handled: readonly SalesRefusalCode[] = [],
  ): Promise<LineOutcome> {
    return this.enqueue(() =>
      this.write(() => {
        const sale = this.sale();
        if (!sale) {
          throw new Error('There is no sale in the cart.');
        }
        return firstValueFrom(
          this.connectivity.track(this.pricer.updateLine(sale.id, lineId, request)),
        );
      }, handled),
    );
  }

  removeLine(lineId: string): Promise<LineOutcome> {
    return this.enqueue(() =>
      this.write(() => {
        const sale = this.sale();
        if (!sale) {
          throw new Error('There is no sale in the cart.');
        }
        return firstValueFrom(this.connectivity.track(this.api.removeLine(sale.id, lineId)));
      }),
    );
  }

  /** Runs one line action after every earlier one has finished. A job never rejects. */
  private enqueue<T>(job: () => Promise<T>): Promise<T> {
    const result = this.queue.then(job, job);
    this.queue = result.catch(() => undefined);
    return result;
  }

  /** Is the cart in a state to take a line action? Reloads it first when it may be stale. */
  private async ready(): Promise<boolean> {
    if (!this.connectivity.online()) {
      // Nothing is sent and nothing is queued while the server cannot be reached (AC-74, D-2).
      this.notifier.notify({ kind: 'refusal', text: NETWORK_MESSAGE, code: null });
      return false;
    }
    if (!this.shift.shiftOpen()) {
      this.notifier.info('Open a shift first');
      return false;
    }
    if (this.reloadNeeded()) {
      if (!(await this.reload())) {
        return false;
      }
      this.reloadNeeded.set(false);
    }
    return true;
  }

  private async write(
    run: () => Promise<Sale>,
    handled: readonly SalesRefusalCode[] = [],
  ): Promise<LineOutcome> {
    if (!(await this.ready())) {
      return { ok: false, code: null };
    }
    try {
      this.load(await run());
      return { ok: true, code: null };
    } catch (err) {
      return this.failed(err, handled);
    }
  }

  private async failed(err: unknown, handled: readonly SalesRefusalCode[]): Promise<LineOutcome> {
    if (!(err instanceof ApiError)) {
      this.notifier.fail(err);
      return { ok: false, code: null, error: err };
    }
    const code = refusalOf(err);
    if (err.status === 0) {
      // The answer was lost: the server may or may not have applied the write (AC-75).
      this.reloadNeeded.set(true);
    }
    this.shift.noteRefusal(err);
    if (code === null || !handled.includes(code)) {
      this.notifier.fail(err);
    }
    if (code === 'sale_not_editable') {
      await this.reload();
    }
    return { ok: false, code, error: err };
  }

  /** Looks for an unfinished sale of this terminal and puts it in an empty cart (AC-88). */
  private resume(): Promise<void> {
    this.resumeStarted = true;
    return this.enqueue(async () => {
      if (this.sale() !== null) {
        return;
      }
      try {
        const page = await firstValueFrom(
          this.connectivity.track(
            this.api.listSales({
              scope: 'current_terminal',
              status: ['draft', 'pending_payment'],
              limit: 1,
            }),
          ),
        );
        const found = page.items[0];
        if (!found) {
          return;
        }
        const sale = await firstValueFrom(this.connectivity.track(this.api.getSale(found.id)));
        // A sale started while this was on its way is the cashier's, and stays.
        if (this.sale() === null) {
          this.load(sale);
        }
      } catch (err) {
        if (err instanceof ApiError && err.status === 0) {
          // Not read: try again when the till is back online.
          this.resumeStarted = false;
        }
        this.notifier.fail(err);
      }
    });
  }

  private recover(): Promise<void> {
    return this.enqueue(async () => {
      if (this.reloadNeeded() && (await this.reload())) {
        this.reloadNeeded.set(false);
      }
    });
  }

  private move(step: -1 | 1): void {
    const lines = this.lines();
    if (lines.length === 0) {
      return;
    }
    const at = lines.findIndex((l) => l.id === this.selected());
    const next = at === -1 ? (step === 1 ? 0 : lines.length - 1) : at + step;
    const clamped = next < 0 ? 0 : next >= lines.length ? lines.length - 1 : next;
    this.select(lines[clamped]?.id ?? null);
  }
}
