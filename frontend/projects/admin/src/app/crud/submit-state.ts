import { DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
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

  /**
   * Created in a component's field initialiser it follows the component: once the component is
   * destroyed a late reply runs no handler (review S-3), so a person who left the page is not
   * pulled back by an old success. Created anywhere else it simply has no owner to follow.
   */
  constructor(private readonly owner: DestroyRef | null = currentOwner()) {}

  /** Returns true when the request was started, false when it was ignored. */
  run<T>(
    source: Observable<T>,
    handlers: { next?: (value: T) => void; error?: (err: ApiError) => void } = {},
  ): boolean {
    if (this.state()) {
      return false;
    }
    this.state.set(true);
    const owned = this.owner ? source.pipe(takeUntilDestroyed(this.owner)) : source;
    owned.pipe(finalize(() => this.state.set(false))).subscribe({
      next: handlers.next,
      error: handlers.error,
    });
    return true;
  }
}

/** The surrounding component's `DestroyRef`, or null when not called in an injection context. */
function currentOwner(): DestroyRef | null {
  try {
    return inject(DestroyRef);
  } catch {
    return null;
  }
}
