import { DestroyRef } from '@angular/core';
import { ApiError } from '@flexipos/shared';
import { Observable, Subject, throwError } from 'rxjs';
import { SubmitState } from './submit-state';

describe('SubmitState (T-036 AC-18)', () => {
  it('does not subscribe a second time while busy', () => {
    const state = new SubmitState();
    let subscriptions = 0;
    const source = new Observable<number>(() => {
      subscriptions += 1;
    });
    expect(state.run(source)).toBe(true);
    expect(state.busy()).toBe(true);
    expect(state.run(source)).toBe(false);
    expect(subscriptions).toBe(1);
  });

  it('is not busy again once the request completes', () => {
    const state = new SubmitState();
    const done = new Subject<number>();
    const seen: number[] = [];
    state.run(done, { next: (v) => seen.push(v) });
    done.next(1);
    done.complete();
    expect(seen).toEqual([1]);
    expect(state.busy()).toBe(false);
    expect(state.run(new Subject())).toBe(true);
  });

  it('resets busy after an error and hands the error over', () => {
    const state = new SubmitState();
    let got: ApiError | null = null;
    state.run(
      throwError(() => new ApiError(422, 'Nope.')),
      { error: (e) => (got = e) },
    );
    expect(state.busy()).toBe(false);
    expect((got as ApiError | null)?.message).toBe('Nope.');
    expect(state.run(new Subject())).toBe(true);
  });

  it('runs no handler once its owner is destroyed (review S-3)', () => {
    const callbacks: (() => void)[] = [];
    const owner = { onDestroy: (cb: () => void) => (callbacks.push(cb), () => undefined) };
    const state = new SubmitState(owner as DestroyRef);
    const late = new Subject<number>();
    const seen: number[] = [];
    state.run(late, { next: (v) => seen.push(v) });
    callbacks.forEach((cb) => cb());
    late.next(1);
    expect(seen).toEqual([]);
    expect(state.busy()).toBe(false);
  });
});
