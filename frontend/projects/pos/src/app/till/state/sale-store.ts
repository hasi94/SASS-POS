import { Injectable, computed, inject, signal } from '@angular/core';
import { Sale, SaleLine, SaleStatus, SalesApi } from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { Connectivity } from './connectivity';
import { Notifier } from './notifier';

/**
 * The sale in the cart (T-037 AC-29 to AC-38). The server's `Sale` is the only truth: the store
 * replaces its state with whatever the server sent and never computes a figure (D-6).
 *
 * FROZEN SURFACE (Chunk 0). Track A calls only `current`, `reload()`, `clear()` and `load(sale)`;
 * the key-map dispatcher on `TillPage` calls the selection members and the two action stubs.
 * Step B4 fills the bodies of the stubs and adds line, customer and resume members; it does not
 * change anything listed here.
 */
@Injectable()
export class SaleStore {
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  private readonly notifier = inject(Notifier);
  private readonly sale = signal<Sale | null>(null);
  private readonly selected = signal<string | null>(null);

  /** The sale in the cart, or null when the cart is empty and no sale exists yet (D-9). */
  readonly current = this.sale.asReadonly();
  readonly lines = computed<SaleLine[]>(() => this.sale()?.lines ?? []);
  readonly lineCount = computed(() => this.lines().length);
  readonly status = computed<SaleStatus | null>(() => this.sale()?.status ?? null);
  /** The line the Up and Down keys have selected. */
  readonly selectedLineId = this.selected.asReadonly();

  /** Puts a sale the server sent into the cart, replacing whatever was there. */
  load(sale: Sale): void {
    this.sale.set(sale);
    if (this.selected() !== null && !sale.lines.some((l) => l.id === this.selected())) {
      this.selected.set(null);
    }
  }

  /** Empties the cart. Nothing is created on the server (D-9). */
  clear(): void {
    this.sale.set(null);
    this.selected.set(null);
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
    this.selected.set(lineId);
  }

  selectPrevious(): void {
    this.move(-1);
  }

  selectNext(): void {
    this.move(1);
  }

  /** Step B4 removes the selected line. Until then it does nothing. */
  removeSelectedLine(): Promise<void> {
    return Promise.resolve();
  }

  /** Step B6 holds the sale. Until then it does nothing. */
  hold(): Promise<void> {
    return Promise.resolve();
  }

  private move(step: -1 | 1): void {
    const lines = this.lines();
    if (lines.length === 0) {
      return;
    }
    const at = lines.findIndex((l) => l.id === this.selected());
    const next = at === -1 ? (step === 1 ? 0 : lines.length - 1) : at + step;
    const clamped = next < 0 ? 0 : next >= lines.length ? lines.length - 1 : next;
    this.selected.set(lines[clamped]?.id ?? null);
  }
}
