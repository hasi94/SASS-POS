/**
 * Replays a fixture request against the sales mock through the real `HttpClient` stack (T-037
 * P-11): a fresh `TestBed` and so a fresh mock state, the `given` steps applied to it, a signed-in
 * session with a tenant bearer, then the request itself. Test-only.
 */
import {
  HttpErrorResponse,
  HttpClient,
  provideHttpClient,
  withInterceptors,
} from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { authInterceptor } from '../../../auth/auth.interceptor';
import { Session } from '../../../auth/auth.models';
import { SESSION_STORAGE_KEY } from '../../../auth/session-storage';
import { MOCK_TENANTS, MOCK_USER } from '../../../mock/mock-backend';
import { SALES_MOCK_LATENCY_MS, SALES_MOCK_STATE } from '../../../sales-mock/mock-state';
import { applyGiven } from '../../../sales-mock/scenarios';
import { salesMockInterceptor } from '../../../sales-mock/sales-mock.interceptor';
import { SalesCallId } from '../calls';

export const FIXTURE_TENANT_ID = 'TEN001';
export const FIXTURE_BRANCH_ID = 'BR001';

export interface FixtureRequest {
  method: 'GET' | 'POST' | 'PUT' | 'DELETE';
  /** Relative to `/api/v1`, without a query string. */
  path: string;
  query?: Record<string, string>;
  body?: unknown;
}

export interface Fixture {
  call: SalesCallId;
  case: string;
  given: string[];
  request: FixtureRequest;
  response: { status: number; body: unknown };
}

/** The signed-in session of the fixtures: ABC Pharmacy, Colombo 03, the demo user. */
export function fixtureSession(): Session {
  return {
    platform: { accessToken: 'mock-platform-access', refreshToken: 'mock-platform-refresh' },
    tenantTokens: {
      accessToken: `mock-tenant-access-${FIXTURE_TENANT_ID}`,
      refreshToken: `mock-tenant-refresh-${FIXTURE_TENANT_ID}`,
    },
    tenants: MOCK_TENANTS,
    defaultTenantId: FIXTURE_TENANT_ID,
    mustChangePassword: false,
    tenantId: FIXTURE_TENANT_ID,
    branchId: FIXTURE_BRANCH_ID,
    user: MOCK_USER,
  };
}

/** Writes the session where `AuthService` restores it from (the default storage key). */
export function seedSession(session: Session = fixtureSession()): void {
  sessionStorage.setItem('flexipos.session', JSON.stringify(session));
}

export interface Replayed {
  status: number;
  body: unknown;
}

export async function replay(fixture: Pick<Fixture, 'given' | 'request'>): Promise<Replayed> {
  sessionStorage.clear();
  seedSession();
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    providers: [
      provideRouter([]),
      provideHttpClient(withInterceptors([authInterceptor, salesMockInterceptor])),
      { provide: SESSION_STORAGE_KEY, useValue: 'flexipos.session' },
      { provide: SALES_MOCK_LATENCY_MS, useValue: 0 },
    ],
  });
  const state = TestBed.inject(SALES_MOCK_STATE);
  applyGiven(state, FIXTURE_TENANT_ID, FIXTURE_BRANCH_ID, MOCK_USER.name, fixture.given);

  const http = TestBed.inject(HttpClient);
  const { method, path, query, body } = fixture.request;
  try {
    const response = await firstValueFrom(
      http.request(method, `/api/v1/${path}`, {
        body,
        params: query,
        observe: 'response',
      }),
    );
    return { status: response.status, body: response.body };
  } catch (err) {
    if (err instanceof HttpErrorResponse) {
      return { status: err.status, body: err.error };
    }
    throw err;
  }
}
