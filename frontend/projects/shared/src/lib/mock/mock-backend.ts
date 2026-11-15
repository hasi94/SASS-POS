import { HttpErrorResponse, HttpInterceptorFn, HttpResponse } from '@angular/common/http';
import { Observable, delay, of, throwError, timer } from 'rxjs';
import { mergeMap } from 'rxjs/operators';
import { LoginResult, LoginTenant, LogoutResult, SelectTenantResult } from '../auth/auth.models';

/**
 * Stand-in for the backend until a real master database and signing key exist. It
 * speaks the real, shipped T-009 shapes (reconciled in T-025, D-3/D-4/D-7/D-8), so
 * building against the mock is building against the real contract. Removing it is one
 * line in an app's config. Development only: never include it in a production build.
 *
 * Order matters: put it after `authInterceptor` so it sees the Authorization header.
 *
 * Mock-only extras on otherwise real-shaped bodies (named once, here and in README.md):
 * - `tenants[].branches` (D-5: no real endpoint supplies a branch list);
 * - the top-level `user` on a login success (D-6: `LoginResult` carries no user object).
 */
export const MOCK_TENANTS: LoginTenant[] = [
  {
    id: 'TEN001',
    code: 'abc-pharmacy',
    name: 'ABC Pharmacy',
    status: 'active',
    selectable: true,
    is_default: true,
    branches: [
      { id: 'BR001', name: 'Colombo 03' },
      { id: 'BR002', name: 'Kandy' },
    ],
  },
  {
    id: 'TEN002',
    code: 'xyz-pharmacy',
    name: 'XYZ Pharmacy',
    status: 'active',
    selectable: true,
    is_default: false,
    branches: [{ id: 'BR010', name: 'Galle' }],
  },
  {
    id: 'TEN003',
    code: 'old-shop',
    name: 'Old Shop',
    status: 'suspended',
    selectable: false,
    is_default: false,
    branches: [],
  },
];

export const MOCK_USER = { id: 'USR001', name: 'Demo Owner', email: 'demo@flexipos.test' };
export const MOCK_PASSWORD = 'demo1234';

const PLATFORM_ACCESS_TOKEN = 'mock-platform-access';
const PLATFORM_REFRESH_TOKEN = 'mock-platform-refresh';

const LATENCY_MS = 150;

function ok<T>(url: string, body: T): Observable<HttpResponse<unknown>> {
  return of(new HttpResponse({ url, status: 200, body })).pipe(delay(LATENCY_MS));
}

function refuse(
  url: string,
  status: number,
  error: Record<string, unknown>,
): Observable<never> {
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

  // auth/logout has no middleware on the real route: it answers before any
  // Authorization check, always 200, whatever is presented (including none).
  if (req.method === 'POST' && path === 'auth/logout') {
    const data: LogoutResult = { status: 'signed_out' };
    return ok(req.url, data);
  }

  if (req.method === 'POST' && path === 'auth/login') {
    if (typeof body['email'] !== 'string' || typeof body['password'] !== 'string') {
      return refuse(req.url, 422, {
        error: 'invalid_request',
        reason: 'email and password are required',
      });
    }
    if (body['email'] === MOCK_USER.email && body['password'] === MOCK_PASSWORD) {
      const data: LoginResult = {
        access_token: PLATFORM_ACCESS_TOKEN,
        refresh_token: PLATFORM_REFRESH_TOKEN,
        tenants: MOCK_TENANTS,
        default_tenant_id: MOCK_TENANTS.find((t) => t.is_default)?.id ?? null,
        must_change_password: false,
        user: MOCK_USER,
      };
      return ok(req.url, data);
    }
    return refuse(req.url, 401, { error: 'invalid_credentials' });
  }

  if (req.method === 'POST' && path === 'auth/tenant') {
    const bearer = req.headers.get('Authorization');
    if (!bearer || bearer !== `Bearer ${PLATFORM_ACCESS_TOKEN}`) {
      if (bearer?.startsWith('Bearer mock-tenant-access-')) {
        return refuse(req.url, 403, { error: 'wrong_token_type' });
      }
      return refuse(req.url, 401, { error: 'invalid_credentials' });
    }
    if (typeof body['code'] !== 'string') {
      return refuse(req.url, 422, { error: 'invalid_request', reason: 'code is required' });
    }
    const tenant = MOCK_TENANTS.find((t) => t.code === body['code']);
    if (!tenant) {
      return refuse(req.url, 403, { error: 'no_access' });
    }
    if (!tenant.selectable) {
      return refuse(req.url, 403, { error: 'tenant_not_servable', status: tenant.status });
    }
    const data: SelectTenantResult = {
      access_token: `mock-tenant-access-${tenant.id}`,
      refresh_token: `mock-tenant-refresh-${tenant.id}`,
      tenant_id: tenant.id,
      tenant_code: tenant.code,
    };
    return ok(req.url, data);
  }

  if (!req.headers.has('Authorization')) {
    return refuse(req.url, 401, { error: 'invalid_credentials' });
  }

  return refuse(req.url, 404, { error: 'not_found' });
};
