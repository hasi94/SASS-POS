/**
 * Helpers for the Track B specs (shift, grid, search, scan, cart). The harness itself is frozen
 * after Chunk 0, so what these specs share lives here.
 */
import { Sale, branchState } from '@flexipos/shared';
import { Notifier } from '../state/notifier';
import { TillHarness } from './till-harness';

/** A store or other provider of the page, read from the page's own injector. */
export function storeOf<T>(harness: TillHarness, token: abstract new (...args: never[]) => T): T {
  return harness.fixture.debugElement.injector.get(token);
}

/** Puts text in an input the way typing does, without pressing a key. */
export function typeInto(input: HTMLInputElement, text: string): void {
  input.value = text;
  input.dispatchEvent(new Event('input', { bubbles: true }));
}

/** The element with `data-testid`, or a failure that names it. */
export function byId<T extends HTMLElement = HTMLElement>(harness: TillHarness, testId: string): T {
  const element = harness.query<T>(`[data-testid="${testId}"]`);
  if (!element) {
    throw new Error(`Nothing on the page has data-testid="${testId}"`);
  }
  return element;
}

/** Every element with `data-testid`. */
export function allById<T extends HTMLElement = HTMLElement>(
  harness: TillHarness,
  testId: string,
): T[] {
  return Array.from(harness.host.querySelectorAll<T>(`[data-testid="${testId}"]`));
}

/**
 * `settle()` a few times over: a store call that awaits another store call finishes a few
 * microtasks after the view first shows its result.
 */
export async function settleFully(harness: TillHarness): Promise<void> {
  for (let round = 0; round < 4; round++) {
    await harness.settle();
  }
}

/**
 * Takes every message off the screen. The notifier shows one message at a time and a refusal
 * waits behind a plain "Added ..." note until it has had its four seconds, so a spec that checks
 * a refusal right after a scan clears the notes first.
 */
export function clearNotes(harness: TillHarness): void {
  const notifier = storeOf(harness, Notifier);
  for (let guard = 0; notifier.current() !== null && guard < 50; guard++) {
    notifier.dismiss();
  }
}

/** Clicks the element with `data-testid` and lets the page catch up. */
export async function clickId(harness: TillHarness, testId: string): Promise<void> {
  byId(harness, testId).click();
  await harness.settle();
}

/** Types a code into the scan field, presses Enter and lets the page catch up. */
export async function scan(harness: TillHarness, code: string): Promise<void> {
  harness.typeAndEnter('[data-testid="scan-field"]', code);
  await harness.settle();
}

/** The sales the mock holds for the signed-in branch, as the server sent them. */
export function serverSales(harness: TillHarness, tenantId: string, branchId: string): Sale[] {
  return branchState(harness.state, tenantId, branchId).sales.map((s) => s.data);
}
