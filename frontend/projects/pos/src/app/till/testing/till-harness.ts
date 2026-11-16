/**
 * The test harness every till spec uses (T-037 step 0.9). FROZEN after Chunk 0: a later chunk adds
 * its own helpers in `testing/<area>-helpers.ts` instead of editing this file.
 *
 * `setupTill()` builds the real thing: `TillPage` over the real HTTP stack with the auth
 * interceptor, the sales mock and the auth mock, a signed-in session with a chosen branch, and the
 * mock's state prepared by named `given` steps. No component is faked. A spec swaps a port or a
 * store by passing `providers`.
 */
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { Provider } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  MOCK_TENANTS,
  MOCK_USER,
  MockState,
  SALES_MOCK_LATENCY_MS,
  SALES_MOCK_STATE,
  SESSION_STORAGE_KEY,
  Session,
  applyGiven,
  authInterceptor,
  mockBackendInterceptor,
  salesMockInterceptor,
} from '@flexipos/shared';
import { TillPage } from '../till-page';

export const HARNESS_TENANT_ID = 'TEN001';
export const HARNESS_STORAGE_KEY = 'flexipos.pos.session';

export interface TillSetup {
  /** Named mock steps applied before the page opens, for example `['shift_open']`. */
  given?: string[];
  /** Milliseconds of mock latency. Default 0, so answers arrive in the same turn. */
  latencyMs?: number;
  /** The branch the session has chosen. Default `BR001` (Colombo 03). */
  branchId?: string;
  /** Extra providers, for example `{ provide: LINE_PRICER, useValue: stub }`. */
  providers?: Provider[];
}

export interface Modifiers {
  ctrl?: boolean;
  alt?: boolean;
  meta?: boolean;
  shift?: boolean;
}

export interface TillHarness {
  fixture: ComponentFixture<TillPage>;
  host: HTMLElement;
  /** The mock's in-memory state, for steps that prepare or inspect the server. */
  state: MockState;
  /** Presses a key on the focused element (or the page), the way a keyboard would. Returns the event. */
  press(key: string, modifiers?: Modifiers): KeyboardEvent;
  /** Puts `text` in the input matching `selector` and presses Enter in it. */
  typeAndEnter(selector: string, text: string): void;
  /** Lets pending work finish and the view catch up. */
  settle(): Promise<void>;
  /** The first element in the page matching `selector`, or null. */
  query<T extends HTMLElement = HTMLElement>(selector: string): T | null;
  /** The text of the element with `data-testid`, trimmed, or null. */
  text(testId: string): string | null;
}

export function sessionFor(branchId: string): Session {
  return {
    platform: { accessToken: 'mock-platform-access', refreshToken: 'mock-platform-refresh' },
    tenantTokens: {
      accessToken: `mock-tenant-access-${HARNESS_TENANT_ID}`,
      refreshToken: `mock-tenant-refresh-${HARNESS_TENANT_ID}`,
    },
    tenants: MOCK_TENANTS,
    defaultTenantId: HARNESS_TENANT_ID,
    mustChangePassword: false,
    tenantId: HARNESS_TENANT_ID,
    branchId,
    user: MOCK_USER,
  };
}

export async function setupTill(setup: TillSetup = {}): Promise<TillHarness> {
  const branchId = setup.branchId ?? 'BR001';
  sessionStorage.clear();
  sessionStorage.setItem(HARNESS_STORAGE_KEY, JSON.stringify(sessionFor(branchId)));

  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    providers: [
      provideRouter([]),
      provideHttpClient(
        withInterceptors([authInterceptor, salesMockInterceptor, mockBackendInterceptor]),
      ),
      { provide: SESSION_STORAGE_KEY, useValue: HARNESS_STORAGE_KEY },
      { provide: SALES_MOCK_LATENCY_MS, useValue: setup.latencyMs ?? 0 },
      ...(setup.providers ?? []),
    ],
  });

  const state = TestBed.inject(SALES_MOCK_STATE);
  applyGiven(state, HARNESS_TENANT_ID, branchId, MOCK_USER.name, setup.given ?? []);

  const fixture = TestBed.createComponent(TillPage);
  const host = fixture.nativeElement as HTMLElement;

  const settle = async (): Promise<void> => {
    for (let round = 0; round < 3; round++) {
      fixture.detectChanges();
      await fixture.whenStable();
      await Promise.resolve();
    }
    fixture.detectChanges();
  };

  const harness: TillHarness = {
    fixture,
    host,
    state,
    settle,
    press: (key, modifiers = {}) => {
      const active = host.ownerDocument.activeElement;
      const target = active && host.contains(active) ? active : host;
      const event = new KeyboardEvent('keydown', {
        key,
        bubbles: true,
        cancelable: true,
        ctrlKey: modifiers.ctrl ?? false,
        altKey: modifiers.alt ?? false,
        metaKey: modifiers.meta ?? false,
        shiftKey: modifiers.shift ?? false,
      });
      target.dispatchEvent(event);
      return event;
    },
    typeAndEnter: (selector, text) => {
      const input = host.querySelector<HTMLInputElement>(selector);
      if (!input) {
        throw new Error(`No input matches ${selector}`);
      }
      input.focus();
      input.value = text;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
      );
    },
    query: <T extends HTMLElement = HTMLElement>(selector: string) =>
      host.querySelector<T>(selector),
    text: (testId) => host.querySelector(`[data-testid="${testId}"]`)?.textContent?.trim() ?? null,
  };

  await settle();
  return harness;
}
