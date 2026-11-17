import { DestroyRef, Injectable, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  ApiError,
  BARCODE_RESOLVER,
  Quantity,
  ScanResolution,
  asQuantity,
  refusalOf,
} from '@flexipos/shared';
import { Subject, concatMap, firstValueFrom, from } from 'rxjs';
import { parseMultiplier } from '../keyboard/keymap';
import { Connectivity } from './connectivity';
import { Notifier } from './notifier';
import { SaleStore } from './sale-store';
import { ShiftStore } from './shift-store';

/** A code that has been entered and not finished yet: shown at once as an "adding" row (AC-28). */
export interface PendingScan {
  /** Unique per entry, so the same code scanned twice shows two rows. */
  key: number;
  code: string;
}

interface Entry {
  key: number;
  code: string;
  /** The `N` of an `N*` entered before this code, as typed. */
  multiplier: string | null;
}

const EMBEDDED_PRICE_REFUSAL = 'Price-embedded labels are not supported yet';

/**
 * Scanned codes, resolved strictly in arrival order, one call at a time, none dropped (T-037
 * AC-21 to AC-28, AC-81, D-10). A hardware scanner types a code and Enter in a few milliseconds,
 * so several arrive while the first is still being answered: each is acknowledged at once with a
 * pending row, and the queue works through them one after another. A refusal is shown and the
 * codes behind it carry on.
 *
 * The till resolves nothing itself: the `BarcodeResolver` port says what a code is, and the
 * line is added with the product, unit and quantity it returned.
 */
@Injectable()
export class ScanQueue {
  private readonly resolver = inject(BARCODE_RESOLVER);
  private readonly connectivity = inject(Connectivity);
  private readonly notifier = inject(Notifier);
  private readonly sale = inject(SaleStore);
  private readonly shift = inject(ShiftStore);
  private readonly entries = new Subject<Entry>();
  private readonly waiting = signal<PendingScan[]>([]);
  private readonly pendingMultiplier = signal<string | null>(null);
  private nextKey = 1;

  /** Codes entered and not yet finished, oldest first. */
  readonly pending = this.waiting.asReadonly();
  /** The `N` of an `N*` waiting for the next code, or null (the chip). */
  readonly multiplier = this.pendingMultiplier.asReadonly();

  constructor() {
    this.entries
      .pipe(
        concatMap((entry) => from(this.process(entry))),
        takeUntilDestroyed(inject(DestroyRef)),
      )
      .subscribe();
  }

  /**
   * Takes what the cashier entered. `N*` sets the multiplier; anything else is a code, queued
   * now. Whitespace around the code is trimmed and nothing else about it is changed (AC-22).
   */
  submit(text: string): void {
    const code = text.trim();
    if (code === '') {
      return;
    }
    const multiplier = parseMultiplier(code);
    if (multiplier !== null) {
      this.pendingMultiplier.set(multiplier);
      return;
    }
    if (!this.shift.shiftOpen()) {
      this.notifier.info('Open a shift first');
      return;
    }
    const entry: Entry = { key: this.nextKey, code, multiplier: this.pendingMultiplier() };
    this.nextKey += 1;
    this.pendingMultiplier.set(null);
    this.waiting.update((rows) => [...rows, { key: entry.key, code }]);
    this.entries.next(entry);
  }

  /** Esc: drops a multiplier nobody has used. Returns true when there was one. */
  clearMultiplier(): boolean {
    if (this.pendingMultiplier() === null) {
      return false;
    }
    this.pendingMultiplier.set(null);
    return true;
  }

  private async process(entry: Entry): Promise<void> {
    try {
      const resolution = await this.resolve(entry.code);
      if (resolution !== null) {
        await this.add(entry, resolution);
      }
    } finally {
      this.waiting.update((rows) => rows.filter((r) => r.key !== entry.key));
    }
  }

  private async resolve(code: string): Promise<ScanResolution | null> {
    try {
      return await firstValueFrom(this.connectivity.track(this.resolver.resolve(code)));
    } catch (err) {
      if (err instanceof ApiError && refusalOf(err) === 'not_found') {
        this.notifier.notify({
          kind: 'refusal',
          text: `Code not found: ${code}`,
          code: 'not_found',
        });
      } else {
        this.notifier.fail(err);
      }
      return null;
    }
  }

  /** Adds what the code resolved to, with the quantity and unit the server gave (AC-23, AC-24). */
  private async add(entry: Entry, resolution: ScanResolution): Promise<void> {
    if (resolution.kind === 'embedded_price') {
      this.notifier.notify({ kind: 'refusal', text: EMBEDDED_PRICE_REFUSAL });
      return;
    }
    const product = resolution.product;
    if (product.is_weighable && resolution.quantity === null) {
      // No quantity in the code: the cashier weighs it. A multiplier means nothing here.
      await this.sale.addProduct(product);
      return;
    }
    let quantity: Quantity;
    let note = '';
    if (resolution.quantity !== null) {
      quantity = resolution.quantity;
      if (entry.multiplier !== null) {
        note = ` (the code carries its own quantity, so ${entry.multiplier}* was not used)`;
      }
    } else {
      quantity = asQuantity(entry.multiplier ?? '1');
    }
    const outcome = await this.sale.addLine({
      productId: product.id,
      unitId: resolution.unit_id,
      quantity,
    });
    if (outcome.ok) {
      this.notifier.success(`Added ${product.name}${note}`);
    }
  }
}
