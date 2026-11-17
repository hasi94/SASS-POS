import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import {
  ApiError,
  PaymentMethod,
  Sale,
  SaleTender,
  SalesApi,
  asMoney,
  compareDecimal,
  refusalOf,
  ulid,
} from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { Connectivity } from './connectivity';
import { DialogHost } from './dialog-host';
import { Notifier } from './notifier';
import { SaleStore } from './sale-store';
import { ShiftStore } from './shift-store';

/** Method types the till does not offer: nothing behind them can deduct a balance (AC-41). */
const NOT_OFFERED: readonly string[] = ['loyalty', 'voucher'];

/** What one payment action came to. A refusal is returned, not shown: the dialog shows it in place. */
export type PaymentOutcome =
  | { kind: 'done'; sale: Sale }
  | { kind: 'failed'; error: unknown }
  /** Nothing was sent: another action was in flight, or there is no sale, or the till is offline. */
  | { kind: 'ignored' };

/** What the cashier typed for one tender. */
export interface TenderInput {
  method: PaymentMethod;
  /** The amount handed over, exactly as typed (D-8: the till sends nothing else about money). */
  tendered: string;
  /** The reference, for a method that requires one. */
  reference: string | null;
}

/** The change a tender gave back, when it gave any. The figure is the server's string. */
function changeOf(tender: SaleTender): string | null {
  return compareDecimal(tender.change_given, '0') > 0 ? tender.change_given : null;
}

/**
 * Tenders on the sale in the cart, and what comes after them (T-037 AC-41 to AC-50, D-11, D-12).
 *
 * Every figure shown is the server's: a tender is sent as the typed `tendered` string plus the
 * method, and the server's answer (the tender, `paid_total`, `balance_due`, `change_given`)
 * replaces the cart's sale. One ULID is made per submission and kept for as long as the same
 * submission is unsettled, so a retry of a lost answer can never record a second tender; before
 * it is sent again the sale is read back and, if the tender is already on it, nothing is sent.
 * Only one payment action runs at a time (AC-50).
 */
@Injectable()
export class PaymentStore {
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  private readonly notifier = inject(Notifier);
  private readonly shift = inject(ShiftStore);
  private readonly cart = inject(SaleStore);
  private readonly dialogs = inject(DialogHost);
  private readonly methodList = signal<PaymentMethod[]>([]);
  private readonly methodsRead = signal(false);
  private readonly working = signal(false);
  private readonly changeGiven = signal<string | null>(null);
  private readonly finished = signal<Sale | null>(null);
  /** The submission being sent, or the last one whose answer is not known. */
  private attempt: { key: string; id: string } | null = null;
  /**
   * Set whenever any tender's answer is unknown (AC-49, D-11), whatever the cashier types next:
   * while set, the sale is read back before anything is sent, and a tender already on it is shown
   * instead of sending another. It holds only the latest lost tender because each read-back
   * settles the previous one before anything newer is sent.
   */
  private pendingCheck: { saleId: string; tenderId: string } | null = null;

  /** The methods the till offers, as the server lists them (AC-41). */
  readonly methods = computed(() => this.methodList().filter((m) => !NOT_OFFERED.includes(m.type)));
  readonly methodsLoaded = this.methodsRead.asReadonly();
  /** True while a tender, a removal or a completion is in flight: submit controls are off. */
  readonly busy = this.working.asReadonly();
  /** The change the last tender gave back (AC-44), or null. */
  readonly change = this.changeGiven.asReadonly();
  /** The sale that has just been completed and is on the completion screen (AC-47), or null. */
  readonly completed = this.finished.asReadonly();

  constructor() {
    // The completion screen goes the moment another sale is in the cart.
    effect(() => {
      if (this.cart.current() !== null) {
        untracked(() => this.finished.set(null));
      }
    });
  }

  /** Reads the payment methods once. Returns the error when they could not be read. */
  async loadMethods(): Promise<unknown> {
    if (this.methodsRead()) {
      return null;
    }
    try {
      this.methodList.set(await firstValueFrom(this.connectivity.track(this.api.paymentMethods())));
      this.methodsRead.set(true);
      return null;
    } catch (err) {
      return err;
    }
  }

  /** Forgets the change of an earlier tender (a dialog opening again). */
  clearChange(): void {
    this.changeGiven.set(null);
  }

  /** Records a tender (AC-43). A retry of an unanswered submission re-sends the same id. */
  async addTender(input: TenderInput): Promise<PaymentOutcome> {
    const sale = this.cart.current();
    if (!sale || this.working() || !this.connectivity.online()) {
      return { kind: 'ignored' };
    }
    this.working.set(true);
    try {
      const key = JSON.stringify([sale.id, input.method.id, input.tendered, input.reference]);
      if (this.attempt?.key !== key) {
        this.attempt = { key, id: ulid() };
      }
      const attempt = this.attempt;
      this.changeGiven.set(null);
      const pending = this.pendingCheck;
      if (pending !== null && pending.saleId === sale.id) {
        // The answer to an earlier try never came: look before sending anything (AC-49, D-11).
        const fresh = await this.read(sale.id);
        if (fresh.kind !== 'done') {
          return fresh;
        }
        this.cart.load(fresh.sale);
        const recorded = fresh.sale.tenders.find((t) => t.id === pending.tenderId);
        this.pendingCheck = null;
        if (recorded) {
          if (this.attempt?.id === recorded.id) {
            this.attempt = null;
          }
          this.changeGiven.set(changeOf(recorded));
          return fresh;
        }
      } else if (pending !== null) {
        this.pendingCheck = null;
      }
      try {
        const response = await firstValueFrom(
          this.connectivity.track(
            this.api.addTender(sale.id, {
              id: attempt.id,
              payment_method_id: input.method.id,
              tendered: asMoney(input.tendered),
              ...(input.reference !== null ? { reference: input.reference } : {}),
            }),
          ),
        );
        this.attempt = null;
        this.pendingCheck = null;
        this.cart.load(response.sale);
        this.changeGiven.set(changeOf(response.tender));
        return { kind: 'done', sale: response.sale };
      } catch (err) {
        // Only an answer in our own words settles it: anything else may have been recorded.
        if (!(err instanceof ApiError) || refusalOf(err) === null) {
          this.pendingCheck = { saleId: sale.id, tenderId: attempt.id };
        } else {
          this.attempt = null;
        }
        return this.failed(err);
      }
    } finally {
      this.working.set(false);
    }
  }

  /** Takes a tender off the sale while it is `pending_payment` (AC-46). */
  async removeTender(tenderId: string): Promise<PaymentOutcome> {
    const sale = this.cart.current();
    if (!sale || this.working() || !this.connectivity.online()) {
      return { kind: 'ignored' };
    }
    this.working.set(true);
    try {
      this.changeGiven.set(null);
      const updated = await firstValueFrom(
        this.connectivity.track(this.api.removeTender(sale.id, tenderId)),
      );
      this.cart.load(updated);
      return { kind: 'done', sale: updated };
    } catch (err) {
      if (err instanceof ApiError && refusalOf(err) === 'not_found') {
        await this.cart.reload();
      }
      return this.failed(err);
    } finally {
      this.working.set(false);
    }
  }

  /** Completes the sale (AC-47). On success the cart empties and the completion screen shows. */
  async complete(): Promise<PaymentOutcome> {
    const sale = this.cart.current();
    if (!sale || this.working() || !this.connectivity.online()) {
      return { kind: 'ignored' };
    }
    this.working.set(true);
    try {
      const done = await firstValueFrom(this.connectivity.track(this.api.completeSale(sale.id)));
      return this.finish(done);
    } catch (err) {
      if (err instanceof ApiError && refusalOf(err) === null) {
        // The answer may have been lost after the sale was completed: look before reporting.
        const fresh = await this.read(sale.id);
        if (fresh.kind === 'done' && fresh.sale.status === 'completed') {
          return this.finish(fresh.sale);
        }
      }
      if (err instanceof ApiError && refusalOf(err) === 'balance_due') {
        // The cart's figures were behind the server's: show the server's.
        await this.cart.reload();
      }
      return this.failed(err);
    } finally {
      this.working.set(false);
    }
  }

  /** "New sale" (AC-47): leaves the completion screen and starts no draft (D-9). */
  startNewSale(): void {
    this.finished.set(null);
    this.changeGiven.set(null);
    this.dialogs.focusScanField();
  }

  private finish(sale: Sale): PaymentOutcome {
    this.attempt = null;
    this.pendingCheck = null;
    this.changeGiven.set(null);
    this.cart.clear();
    this.finished.set(sale);
    return { kind: 'done', sale };
  }

  private async read(saleId: string): Promise<PaymentOutcome> {
    try {
      return {
        kind: 'done',
        sale: await firstValueFrom(this.connectivity.track(this.api.getSale(saleId))),
      };
    } catch (err) {
      return this.failed(err);
    }
  }

  private failed(error: unknown): PaymentOutcome {
    // A refusal that says the shift is gone sends the till back to the shift prompt (AC-15).
    this.shift.noteRefusal(error);
    if (!(error instanceof ApiError)) {
      this.notifier.fail(error);
    }
    return { kind: 'failed', error };
  }
}
