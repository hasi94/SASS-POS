import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import {
  ApiError,
  CashMovementType,
  CashShift,
  ClosedShift,
  SaleSummary,
  SalesApi,
  Ulid,
  asMoney,
  refusalOf,
  ulid,
} from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { Connectivity } from './connectivity';
import { Notifier } from './notifier';

/** How a close attempt ended. */
export type CloseResult =
  | { kind: 'closed'; shift: ClosedShift }
  /** Refused `shift_has_open_sales`: the open sales are now in {@link ShiftStore.openSales}. */
  | { kind: 'open-sales' }
  | { kind: 'failed' };

/**
 * The terminal's cash shift (T-037 AC-9 to AC-15): the read the header and the key map need, and
 * the three things a cashier does with a shift: open it, record cash movements, close it.
 *
 * Every figure is the server's string. The opening and counted amounts are the cashier's own
 * typing, checked by the form before they get here and sent as typed.
 */
@Injectable()
export class ShiftStore {
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  private readonly notifier = inject(Notifier);
  private readonly shift = signal<CashShift | null>(null);
  private readonly isLoaded = signal(false);
  private readonly isBusy = signal(false);
  private readonly listed = signal<SaleSummary[]>([]);
  private movementAttempt: { key: string; id: Ulid } | null = null;
  private sawOffline = false;

  /** The open shift, or null when there is none (or it has not loaded yet: see `loaded`). */
  readonly current = this.shift.asReadonly();
  /** True once the first answer has come back, so "no shift" is not shown while it is unknown. */
  readonly loaded = this.isLoaded.asReadonly();
  readonly shiftOpen = computed(() => this.shift() !== null);
  /** True while an open, movement or close call is in flight: the forms ignore a second submit. */
  readonly busy = this.isBusy.asReadonly();
  /** The draft and pending-payment sales of this shift, as the server listed them (AC-14). */
  readonly openSales = this.listed.asReadonly();

  constructor() {
    // A shift that could not be read because the network was down is read again the moment the
    // till is back, instead of leaving the screen without a shift prompt for good.
    effect(() => {
      if (!this.connectivity.online()) {
        this.sawOffline = true;
      } else if (this.sawOffline) {
        this.sawOffline = false;
        if (!untracked(this.isLoaded)) {
          untracked(() => void this.load());
        }
      }
    });
  }

  /** Asks the server for the terminal's open shift. */
  async load(): Promise<void> {
    try {
      this.shift.set(await firstValueFrom(this.connectivity.track(this.api.currentShift())));
      this.isLoaded.set(true);
    } catch (err) {
      this.notifier.fail(err);
    }
  }

  /**
   * Opens a shift with the opening cash typed (AC-10). A terminal that already has one open
   * (`shift_already_open`) just uses it (AC-11). Resolves true when a shift is open afterwards.
   */
  async openShift(openingCash: string): Promise<boolean> {
    if (this.isBusy()) {
      return false;
    }
    this.isBusy.set(true);
    try {
      this.shift.set(
        await firstValueFrom(
          this.connectivity.track(this.api.openShift({ opening_cash: asMoney(openingCash) })),
        ),
      );
      this.isLoaded.set(true);
      return true;
    } catch (err) {
      if (err instanceof ApiError && refusalOf(err) === 'shift_already_open') {
        await this.load();
        return this.shiftOpen();
      }
      this.notifier.fail(err);
      return false;
    } finally {
      this.isBusy.set(false);
    }
  }

  /**
   * Records a cash movement (AC-12). The call carries a ULID that is kept while the same movement
   * is submitted again (a timeout, a dropped connection) and replaced only by a different one, so
   * a retry can never record it twice.
   */
  async recordMovement(type: CashMovementType, amount: string, reason: string): Promise<boolean> {
    const shift = this.shift();
    if (!shift) {
      this.notifier.info('There is no open shift');
      return false;
    }
    if (this.isBusy()) {
      return false;
    }
    const key = JSON.stringify([shift.id, type, amount, reason]);
    if (this.movementAttempt?.key !== key) {
      this.movementAttempt = { key, id: ulid() };
    }
    const id = this.movementAttempt.id;
    this.isBusy.set(true);
    try {
      await firstValueFrom(
        this.connectivity.track(
          this.api.recordMovement(shift.id, { id, type, amount: asMoney(amount), reason }),
        ),
      );
      this.movementAttempt = null;
      return true;
    } catch (err) {
      this.noteRefusal(err);
      this.notifier.fail(err);
      return false;
    } finally {
      this.isBusy.set(false);
    }
  }

  /**
   * Closes the shift with the cashier's blind count (AC-13). A shift that still has sales in
   * progress is refused `shift_has_open_sales`; the sales are listed for the cashier to deal with
   * (AC-14).
   */
  async closeShift(countedCash: string): Promise<CloseResult> {
    const shift = this.shift();
    if (!shift) {
      this.notifier.info('There is no open shift');
      return { kind: 'failed' };
    }
    if (this.isBusy()) {
      return { kind: 'failed' };
    }
    this.isBusy.set(true);
    try {
      const closed = await firstValueFrom(
        this.connectivity.track(
          this.api.closeShift(shift.id, { counted_cash: asMoney(countedCash) }),
        ),
      );
      this.shift.set(null);
      this.listed.set([]);
      return { kind: 'closed', shift: closed };
    } catch (err) {
      this.noteRefusal(err);
      this.notifier.fail(err);
      if (err instanceof ApiError && refusalOf(err) === 'shift_has_open_sales') {
        this.isBusy.set(false);
        await this.loadOpenSales();
        return { kind: 'open-sales' };
      }
      return { kind: 'failed' };
    } finally {
      this.isBusy.set(false);
    }
  }

  /** Reads the draft and pending-payment sales of this shift (a held sale is not one of them). */
  async loadOpenSales(): Promise<void> {
    try {
      const page = await firstValueFrom(
        this.connectivity.track(
          this.api.listSales({
            status: ['draft', 'pending_payment'],
            scope: 'current_shift',
            limit: 50,
          }),
        ),
      );
      this.listed.set(page.items);
    } catch (err) {
      this.notifier.fail(err);
    }
  }

  /**
   * Any store that sees a refusal calls this with it. `no_open_shift` and `shift_not_open` mean
   * the shift is gone, so the till goes back to the shift prompt (AC-15). The cart is not touched.
   * Returns true when it was one of those two.
   */
  noteRefusal(err: unknown): boolean {
    if (!(err instanceof ApiError)) {
      return false;
    }
    const code = refusalOf(err);
    if (code !== 'no_open_shift' && code !== 'shift_not_open') {
      return false;
    }
    this.shift.set(null);
    this.isLoaded.set(true);
    return true;
  }
}
