import { DestroyRef, Injectable, inject, signal } from '@angular/core';
import { ApiError, SalesApi } from '@flexipos/shared';
import { Observable, firstValueFrom, tap } from 'rxjs';

/** How often the till asks the server whether it is back, while it is not. */
export const REPROBE_MS = 5000;

/**
 * Whether the server can be reached (T-037 D-2, AC-74). The till is online-only: when a call
 * fails with status 0 it goes offline, a banner says so, every write is disabled and nothing is
 * queued. While offline it asks the server a cheap read every {@link REPROBE_MS}; any answer
 * from the server, even a refusal, means the network is back and the till comes online again.
 *
 * Stores report what they see through {@link track}, or {@link reportSuccess} and
 * {@link reportFailure}.
 */
@Injectable()
export class Connectivity {
  private readonly api = inject(SalesApi);
  private readonly state = signal(true);
  private timer: ReturnType<typeof setTimeout> | null = null;

  readonly online = this.state.asReadonly();

  constructor() {
    inject(DestroyRef).onDestroy(() => this.stopTimer());
  }

  /** Wraps a call so its outcome is reported here. The result is passed through untouched. */
  track<T>(source: Observable<T>): Observable<T> {
    return source.pipe(
      tap({
        next: () => this.reportSuccess(),
        error: (err: unknown) => this.reportFailure(err),
      }),
    );
  }

  /** The server answered: it can be reached. */
  reportSuccess(): void {
    if (!this.state()) {
      this.stopTimer();
      this.state.set(true);
    }
  }

  /** A call failed. Status 0 means the server could not be reached; any other status means it could. */
  reportFailure(err: unknown): void {
    if (err instanceof ApiError && err.status === 0) {
      this.goOffline();
    } else if (err instanceof ApiError) {
      this.reportSuccess();
    }
  }

  /** Asks the server now instead of waiting for the next automatic try (the Retry control). */
  async retryNow(): Promise<void> {
    this.stopTimer();
    await this.probe();
  }

  private goOffline(): void {
    if (this.state()) {
      this.state.set(false);
    }
    if (this.timer === null) {
      this.timer = setTimeout(() => void this.probe(), REPROBE_MS);
    }
  }

  private async probe(): Promise<void> {
    this.timer = null;
    try {
      await firstValueFrom(this.api.currentShift());
      this.reportSuccess();
    } catch (err) {
      if (err instanceof ApiError && err.status === 0) {
        this.goOffline();
      } else {
        this.reportSuccess();
      }
    }
  }

  private stopTimer(): void {
    if (this.timer !== null) {
      clearTimeout(this.timer);
      this.timer = null;
    }
  }
}
