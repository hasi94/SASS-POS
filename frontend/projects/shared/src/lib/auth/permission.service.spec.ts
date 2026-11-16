import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { CATALOG_PERMISSIONS } from '../contracts/catalog/catalog-permissions';
import { LoginResult, Session } from './auth.models';
import { AuthService } from './auth.service';
import { PermissionService } from './permission.service';

const KEY = 'flexipos.session';

const LOGIN: LoginResult = {
  access_token: 'pat',
  refresh_token: 'prt',
  tenants: [
    { id: 'T', code: 't', name: 'T', status: 'active', selectable: true, is_default: true },
  ],
  default_tenant_id: 'T',
  must_change_password: false,
};

function tenantReply(permissions?: unknown): Record<string, unknown> {
  return {
    access_token: 'tat',
    refresh_token: 'trt',
    tenant_id: 'T',
    tenant_code: 't',
    ...(permissions === undefined ? {} : { permissions }),
  };
}

describe('PermissionService (T-036 AC-2, AC-3, AC-10)', () => {
  let auth: AuthService;
  let perms: PermissionService;
  let http: HttpTestingController;

  function setup(): void {
    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()],
    });
    auth = TestBed.inject(AuthService);
    perms = TestBed.inject(PermissionService);
    http = TestBed.inject(HttpTestingController);
  }

  function signIn(): void {
    auth.login('a@b.c', 'x').subscribe();
    http.expectOne('/api/v1/auth/login').flush(LOGIN);
  }

  function selectTenant(permissions?: unknown): void {
    auth.selectTenant('t').subscribe();
    http.expectOne('/api/v1/auth/tenant').flush(tenantReply(permissions));
  }

  beforeEach(() => {
    sessionStorage.clear();
  });

  afterEach(() => http?.verify());

  it('is filled from a selectTenant reply that carries permissions', () => {
    setup();
    signIn();
    selectTenant([CATALOG_PERMISSIONS.products.view, CATALOG_PERMISSIONS.brands.manage]);
    expect(perms.has(CATALOG_PERMISSIONS.products.view)).toBe(true);
    expect(perms.has(CATALOG_PERMISSIONS.brands.view)).toBe(false);
    expect(perms.hasAny([CATALOG_PERMISSIONS.brands.view, CATALOG_PERMISSIONS.brands.manage])).toBe(
      true,
    );
    expect(
      perms.hasAll([CATALOG_PERMISSIONS.products.view, CATALOG_PERMISSIONS.brands.manage]),
    ).toBe(true);
    expect(perms.hasAll([CATALOG_PERMISSIONS.products.view, CATALOG_PERMISSIONS.brands.view])).toBe(
      false,
    );
  });

  it('gives an empty set, and every check false, when the reply has no permissions', () => {
    setup();
    signIn();
    selectTenant();
    expect(perms.keys().size).toBe(0);
    expect(perms.has(CATALOG_PERMISSIONS.products.view)).toBe(false);
    expect(perms.hasAny([CATALOG_PERMISSIONS.products.view])).toBe(false);
    expect(perms.hasAll([CATALOG_PERMISSIONS.products.view])).toBe(false);
  });

  it('denies for a restored session that has no permissions field', () => {
    const stored: Session = {
      platform: { accessToken: 'pt', refreshToken: 'prt' },
      tenantTokens: { accessToken: 'tt', refreshToken: 'trt' },
      tenants: [],
      defaultTenantId: null,
      mustChangePassword: false,
      tenantId: 'T',
      branchId: null,
    };
    sessionStorage.setItem(KEY, JSON.stringify(stored));
    setup();
    expect(auth.isAuthenticated()).toBe(true);
    expect(perms.keys().size).toBe(0);
    expect(perms.has(CATALOG_PERMISSIONS.products.view)).toBe(false);
  });

  it('restores the keys of a stored session that has them', () => {
    const stored = {
      platform: { accessToken: 'pt', refreshToken: 'prt' },
      tenantTokens: null,
      tenants: [],
      defaultTenantId: null,
      mustChangePassword: false,
      tenantId: null,
      branchId: null,
      permissions: [CATALOG_PERMISSIONS.units.view],
    };
    sessionStorage.setItem(KEY, JSON.stringify(stored));
    setup();
    expect(perms.has(CATALOG_PERMISSIONS.units.view)).toBe(true);
  });

  it.each([
    ['a string', 'catalog'],
    ['an object', { a: 1 }],
    ['a number', 7],
    ['null', null],
  ])('denies when permissions is %s instead of an array', (_label, value) => {
    sessionStorage.setItem(
      KEY,
      JSON.stringify({
        platform: { accessToken: 'pt', refreshToken: 'prt' },
        tenantTokens: null,
        tenants: [],
        defaultTenantId: null,
        mustChangePassword: false,
        tenantId: null,
        branchId: null,
        permissions: value,
      }),
    );
    setup();
    expect(perms.keys().size).toBe(0);
    expect(perms.has('catalog')).toBe(false);
  });

  it('keeps only string entries of a stored array', () => {
    sessionStorage.setItem(
      KEY,
      JSON.stringify({
        platform: { accessToken: 'pt', refreshToken: 'prt' },
        tenantTokens: null,
        tenants: [],
        defaultTenantId: null,
        mustChangePassword: false,
        tenantId: null,
        branchId: null,
        permissions: [CATALOG_PERMISSIONS.units.view, 7, null, { a: 1 }],
      }),
    );
    setup();
    expect([...perms.keys()]).toEqual([CATALOG_PERMISSIONS.units.view]);
  });

  it('treats an empty list as no match for hasAny and hasAll', () => {
    setup();
    signIn();
    selectTenant([CATALOG_PERMISSIONS.products.view]);
    expect(perms.hasAny([])).toBe(false);
    expect(perms.hasAll([])).toBe(false);
  });

  it('empties the set on clearContext()', () => {
    setup();
    signIn();
    selectTenant([CATALOG_PERMISSIONS.products.view]);
    expect(perms.has(CATALOG_PERMISSIONS.products.view)).toBe(true);
    auth.clearContext();
    expect(perms.keys().size).toBe(0);
    expect(perms.has(CATALOG_PERMISSIONS.products.view)).toBe(false);
  });

  it('empties the set on logout()', () => {
    setup();
    signIn();
    selectTenant([CATALOG_PERMISSIONS.products.view]);
    auth.logout();
    // logout() fires one auth/logout per refresh token held; answer them so verify() holds.
    for (const req of http.match('/api/v1/auth/logout')) {
      req.flush({ status: 'signed_out' });
    }
    expect(perms.keys().size).toBe(0);
    expect(perms.has(CATALOG_PERMISSIONS.products.view)).toBe(false);
  });

  it('does not let a second person who signs in inherit the first person keys', () => {
    setup();
    signIn();
    selectTenant([CATALOG_PERMISSIONS.products.view]);
    // The first person leaves without signing out; another login replaces the session.
    signIn();
    expect(perms.keys().size).toBe(0);
    expect(perms.has(CATALOG_PERMISSIONS.products.view)).toBe(false);
  });
});
