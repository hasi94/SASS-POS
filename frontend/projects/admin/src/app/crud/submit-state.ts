import { signal } from '@angular/core';
import { ApiError } from '@flexipos/shared';
import { Observable, finalize } from 'rxjs';

/**
 * Keeps a form from sending twice (T-036 AC-18, D-15). While one request is in flight
 * `busy` is true and a further `run` does nothing at all: it does not subscribe, so no
 * second request leaves the browser. `busy` goes back to false when the request ends,
 * whether it succeeded or failed.
 */
export class SubmitState {
  private readonly state = signal(false);
  readonly busy = this.state.asReadonly();

  /** Returns true when the request was started, false when it was ignored. */
  run<T>(
    source: Observable<T>,
    handlers: { next?: (value: T) => void; error?: (err: ApiError) => void } = {},
  ): boolean {
    if (this.state()) {
      return false;
    }
    this.state.set(true);
    source.pipe(finalize(() => this.state.set(false))).subscribe({
      next: handlers.next,
      error: handlers.error,
    });
    return true;
  }
}
