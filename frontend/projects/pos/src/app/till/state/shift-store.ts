import { Injectable, computed, inject, signal } from '@angular/core';
import { CashShift, SalesApi } from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { Connectivity } from './connectivity';
import { Notifier } from './notifier';

/**
 * The terminal's cash shift (T-037 AC-9 to AC-15).
 *
 * Chunk 0 ships the read the header and the key map need: `load()` asks the server for the open
 * shift and publishes it. Step B1 adds opening, movements and closing to this same class; the
 * public members below stay as they are.
 */
@Injectable()
export class ShiftStore {
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  private readonly notifier = inject(Notifier);
  private readonly shift = signal<CashShift | null>(null);
  private readonly isLoaded = signal(false);

  /** The open shift, or null when there is none (or it has not loaded yet: see `loaded`). */
  readonly current = this.shift.asReadonly();
  /** True once the first answer has come back, so "no shift" is not shown while it is unknown. */
  readonly loaded = this.isLoaded.asReadonly();
  readonly shiftOpen = computed(() => this.shift() !== null);

  /** Asks the server for the terminal's open shift. */
  async load(): Promise<void> {
    try {
      this.shift.set(await firstValueFrom(this.connectivity.track(this.api.currentShift())));
      this.isLoaded.set(true);
    } catch (err) {
      this.notifier.fail(err);
    }
  }
}
