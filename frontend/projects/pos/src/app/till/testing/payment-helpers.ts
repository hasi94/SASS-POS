/**
 * Helpers for the specs of customer, payment, completion, hold and recall (T-037 B5, B6). They
 * drive the real dialogs through the DOM, the way a person would, over the real mock.
 */
import { Observable, defer } from 'rxjs';
import { TestBed } from '@angular/core/testing';
import {
  ApiError,
  BranchState,
  Sale,
  SalesApi,
  SalesRefusalCode,
  branchState,
} from '@flexipos/shared';
import { byId, settleFully, typeInto } from './sale-screen-helpers';
import { HARNESS_TENANT_ID, TillHarness } from './till-harness';

export function branchOf(h: TillHarness): BranchState {
  return branchState(h.state, HARNESS_TENANT_ID, 'BR001');
}

/** The mock's nth stored sale, as the server holds it (a spec may change it to stage a fact). */
export function serverSale(h: TillHarness, index = 0): Sale {
  const stored = branchOf(h).sales[index];
  if (!stored) {
    throw new Error(`The mock has no sale number ${index}.`);
  }
  return stored.data;
}

export function api(): SalesApi {
  return TestBed.inject(SalesApi);
}

/** A refusal as the server words it on the wire: namespaced code, with the fact it names. */
export function refusal(
  code: SalesRefusalCode,
  fact?: { field: string; detail: string },
): ApiError {
  return new ApiError(code === 'validation' ? 422 : 403, 'refused', [
    { code: `sales.${code}`, field: fact?.field ?? null, ...(fact ? { detail: fact.detail } : {}) },
  ]);
}

/** What a dropped connection looks like to the till (status 0). */
export function lost(): ApiError {
  return new ApiError(0, 'Network error');
}

/**
 * Holds one call open: the spy returns an observable that does nothing until `release()` runs the
 * real call, so a spec can look at the dialog while a submit is in flight.
 */
export function gateCall<A extends unknown[], T>(
  real: (...args: A) => Observable<T>,
): { wrapped: (...args: A) => Observable<T>; release: () => void; calls: () => number } {
  let pending: (() => void) | null = null;
  let count = 0;
  const wrapped = (...args: A): Observable<T> =>
    new Observable<T>((subscriber) => {
      count += 1;
      pending = () => real(...args).subscribe(subscriber);
    });
  return {
    wrapped,
    release: () => {
      const run = pending;
      pending = null;
      run?.();
    },
    calls: () => count,
  };
}

/** A call whose answer is dropped after the server applied it: `real` runs, the till sees status 0. */
export function applyThenLose<A extends unknown[], T>(
  real: (...args: A) => Observable<T>,
): (...args: A) => Observable<T> {
  return (...args: A) =>
    defer(
      () =>
        new Observable<T>((subscriber) => {
          real(...args).subscribe({
            next: () => undefined,
            error: (err: unknown) => subscriber.error(err),
            complete: () => subscriber.error(lost()),
          });
        }),
    );
}

export async function openPayment(h: TillHarness): Promise<void> {
  h.press('F2');
  await settleFully(h);
}

export function typeAmount(h: TillHarness, text: string): void {
  typeInto(byId<HTMLInputElement>(h, 'pay-amount'), text);
}

export function typeReference(h: TillHarness, text: string): void {
  typeInto(byId<HTMLInputElement>(h, 'pay-reference'), text);
}

/** Submits the payment form, the way Enter in the amount field does. */
export async function submitPayment(h: TillHarness): Promise<void> {
  byId(h, 'pay-amount')
    .closest('form')
    ?.dispatchEvent(new Event('submit', { cancelable: true }));
  await settleFully(h);
}

export async function chooseMethod(h: TillHarness, code: string): Promise<void> {
  byId(h, `method-${code}`).click();
  await settleFully(h);
}

/** Pays an amount by cash through the dialog, which must be open. */
export async function payCash(h: TillHarness, amount: string): Promise<void> {
  await chooseMethod(h, 'CASH');
  typeAmount(h, amount);
  await submitPayment(h);
}

/** Every row of the held list, as text with the white space collapsed. */
export function recallRows(h: TillHarness): string[] {
  return Array.from(h.host.querySelectorAll('[data-testid="recall-row"]')).map((row) =>
    (row.textContent ?? '').replace(/\s+/g, ' ').trim(),
  );
}
