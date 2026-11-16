import { ApiPagination } from '@flexipos/shared';

/**
 * Helpers for specs only (T-036). Never imported by production code, so a build does not
 * bundle them.
 */

/** The storage key the admin app really uses; specs that do not override it use the default. */
export const ADMIN_SESSION_KEY = 'flexipos.admin.session';
const DEFAULT_SESSION_KEY = 'flexipos.session';

/**
 * Writes a signed-in session with a company, a branch and these permission keys, so guards
 * let the person through. Call it before the first `AuthService` is created.
 */
export function seedSession(
  permissions: readonly string[] | undefined,
  options: { key?: string; withContext?: boolean; withUser?: boolean } = {},
): void {
  const withContext = options.withContext ?? true;
  const withUser = options.withUser ?? true;
  const session = {
    platform: { accessToken: 'mock-platform-access', refreshToken: 'mock-platform-refresh' },
    tenantTokens: withContext
      ? { accessToken: 'mock-tenant-access-TEN001', refreshToken: 'mock-tenant-refresh-TEN001' }
      : null,
    tenants: [
      {
        id: 'TEN001',
        code: 'abc-pharmacy',
        name: 'ABC Pharmacy',
        status: 'active',
        selectable: true,
        is_default: true,
        branches: [{ id: 'BR001', name: 'Colombo 03' }],
      },
    ],
    defaultTenantId: 'TEN001',
    mustChangePassword: false,
    tenantId: withContext ? 'TEN001' : null,
    branchId: withContext ? 'BR001' : null,
    ...(withUser
      ? { user: { id: 'USR001', name: 'Demo Owner', email: 'demo-owner@example.test' } }
      : {}),
    ...(permissions === undefined ? {} : { permissions: [...permissions] }),
  };
  sessionStorage.setItem(options.key ?? DEFAULT_SESSION_KEY, JSON.stringify(session));
}

/** A success envelope body, for `TestRequest.flush`. */
export function envelope(
  data: unknown,
  meta: { request_id?: string; pagination?: ApiPagination } = {},
): object {
  return {
    success: true,
    data,
    message: '',
    errors: [],
    meta: { request_id: 'req-test', ...meta },
  };
}

/** A list envelope with offset pagination. */
export function pageEnvelope(
  rows: unknown[],
  pagination: Partial<ApiPagination> = {},
  requestId = 'req-test',
): object {
  return envelope(rows, {
    request_id: requestId,
    pagination: { page: 1, per_page: 25, total: rows.length, ...pagination },
  });
}

/** Arguments for `TestRequest.flush(body, opts)` that answer with a refusal envelope. */
export function refusal(
  status: number,
  code: string,
  field?: string,
  detail?: string,
  message = 'The request was refused.',
): [object, { status: number; statusText: string }] {
  const item: Record<string, unknown> = { code };
  if (field !== undefined) {
    item['field'] = field;
  }
  if (detail !== undefined) {
    item['detail'] = detail;
  }
  return [
    { success: false, data: null, message, errors: [item], meta: { request_id: 'req-refused' } },
    { status, statusText: 'Refused' },
  ];
}
