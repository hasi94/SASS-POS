import { HttpErrorResponse, HttpInterceptorFn, HttpResponse } from '@angular/common/http';
import { Observable, delay, of, throwError, timer } from 'rxjs';
import { mergeMap } from 'rxjs/operators';
import { ApiEnvelope, ApiErrorItem } from '../api/envelope';
import { LoginResult, SelectTenantResult, TenantSummary } from '../auth/auth.models';

/**
 * Stand-in for the backend until FP-215 (API foundation) and the auth module exist.
 * It speaks the same envelope and the same endpoints the client expects, so removing it is
 * one line in an app's config. Development only: never include it in a production build.
 *
 * Order matters: put it after `authInterceptor` so it sees the Authorization header.
 */
export const MOCK_TENANTS: TenantSummary[] = [
  {
    id: 'TEN001',
    name: 'ABC Pharmacy',
    branches: [
      { id: 'BR001', name: 'Colombo 03' },
      { id: 'BR002', name: 'Kandy' },
    ],
  },
  { id: 'TEN002', name: 'XYZ Pharmacy', branches: [{ id: 'BR010', name: 'Galle' }] },
];

export const MOCK_USER = { id: 'USR001', name: 'Demo Owner', email: 'demo@flexipos.test' };
export const MOCK_PASSWORD = 'demo1234';

const LATENCY_MS = 150;
let requestCounter = 0;

function envelope<T>(success: boolean, data: T | null, message: string, errors: ApiErrorItem[]) {
  const body: ApiEnvelope<T> = {
    success,
    data,
    message,
    errors,
    meta: { request_id: `mock-${++requestCounter}` },
  };
  return body;
}

function ok<T>(url: string, data: T, message: string): Observable<HttpResponse<unknown>> {
  return of(new HttpResponse({ url, status: 200, body: envelope(true, data, message, []) })).pipe(
    delay(LATENCY_MS),
  );
}

function failure(
  url: string,
  status: number,
  message: string,
  errors: ApiErrorItem[],
): Observable<never> {
  const error = envelope(false, null, message, errors);
  return timer(LATENCY_MS).pipe(
    mergeMap(() => throwError(() => new HttpErrorResponse({ url, status, error }))),
  );
}

export const mockBackendInterceptor: HttpInterceptorFn = (req, next) => {
  if (!req.url.includes('/api/v1/')) {
    return next(req);
  }
  const path = req.url.replace(/^.*\/api\/v1\//, '');
  const body = (req.body ?? {}) as Record<string, unknown>;

  if (req.method === 'POST' && path === 'auth/login') {
    if (body['email'] === MOCK_USER.email && body['password'] === MOCK_PASSWORD) {
      const data: LoginResult = {
        token: 'mock-identity-token',
        user: MOCK_USER,
        tenants: MOCK_TENANTS,
      };
      return ok(req.url, data, 'Signed in.');
    }
    return failure(req.url, 401, 'Email or password is wrong.', [
      { code: 'auth.invalid_credentials', field: null, detail: 'Email or password is wrong.' },
    ]);
  }

  if (!req.headers.has('Authorization')) {
    return failure(req.url, 401, 'Not signed in.', [{ code: 'auth.unauthenticated' }]);
  }

  if (req.method === 'POST' && path === 'auth/select-tenant') {
    const tenant = MOCK_TENANTS.find((t) => t.id === body['tenant_id']);
    if (!tenant) {
      return failure(req.url, 403, 'You do not have access to that company.', [
        { code: 'tenant.forbidden', field: 'tenant_id' },
      ]);
    }
    const data: SelectTenantResult = { token: `mock-tenant-token-${tenant.id}`, tenant };
    return ok(req.url, data, 'Company selected.');
  }

  if (req.method === 'POST' && path === 'auth/logout') {
    return ok(req.url, {}, 'Signed out.');
  }

  return failure(req.url, 404, 'Not found.', [{ code: 'api.not_found' }]);
};
