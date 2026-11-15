import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { MOCK_PASSWORD, MOCK_USER, mockBackendInterceptor } from '../mock/mock-backend';
import { authInterceptor } from './auth.interceptor';
import { AuthService } from './auth.service';

function setup(): AuthService {
  TestBed.configureTestingModule({
    providers: [
      provideRouter([]),
      provideHttpClient(withInterceptors([authInterceptor, mockBackendInterceptor])),
    ],
  });
  return TestBed.inject(AuthService);
}

describe('AuthService against the mock backend', () => {
  beforeEach(() => sessionStorage.clear());

  it('starts signed out', () => {
    const auth = setup();
    expect(auth.isAuthenticated()).toBe(false);
    expect(auth.hasContext()).toBe(false);
  });

  it('signs in and lists the companies the server allows', async () => {
    const auth = setup();
    await firstValueFrom(auth.login(MOCK_USER.email, MOCK_PASSWORD));
    expect(auth.isAuthenticated()).toBe(true);
    expect(auth.tenants().length).toBeGreaterThan(0);
    expect(auth.hasContext()).toBe(false);
  });

  it('refuses a wrong password and stays signed out', async () => {
    const auth = setup();
    await expect(firstValueFrom(auth.login(MOCK_USER.email, 'nope'))).rejects.toMatchObject({
      status: 401,
    });
    expect(auth.isAuthenticated()).toBe(false);
  });

  it('has a context only after a company and a branch are chosen', async () => {
    const auth = setup();
    await firstValueFrom(auth.login(MOCK_USER.email, MOCK_PASSWORD));
    await firstValueFrom(auth.selectTenant('abc-pharmacy'));
    expect(auth.tenant()?.code).toBe('abc-pharmacy');
    expect(auth.hasContext()).toBe(false);

    const branchId = auth.tenant()?.branches?.[0]?.id;
    expect(branchId).toBeDefined();
    auth.selectBranch(branchId!);
    expect(auth.hasContext()).toBe(true);
  });

  it('ignores a branch that belongs to another company', async () => {
    const auth = setup();
    await firstValueFrom(auth.login(MOCK_USER.email, MOCK_PASSWORD));
    await firstValueFrom(auth.selectTenant('abc-pharmacy'));
    auth.selectBranch('not-a-real-branch');
    expect(auth.branch()).toBeNull();
  });

  it('rejects a company the person has no access to (no_access)', async () => {
    const auth = setup();
    await firstValueFrom(auth.login(MOCK_USER.email, MOCK_PASSWORD));
    await expect(firstValueFrom(auth.selectTenant('unknown-shop'))).rejects.toMatchObject({});
    const err: unknown = await firstValueFrom(auth.selectTenant('unknown-shop')).catch((e) => e);
    expect((err as { hasCode: (c: string) => boolean }).hasCode('no_access')).toBe(true);
    expect(auth.tenant()).toBeNull();
  });

  it('rejects a non-selectable tenant (tenant_not_servable)', async () => {
    const auth = setup();
    await firstValueFrom(auth.login(MOCK_USER.email, MOCK_PASSWORD));
    const err: unknown = await firstValueFrom(auth.selectTenant('old-shop')).catch((e) => e);
    expect((err as { hasCode: (c: string) => boolean }).hasCode('tenant_not_servable')).toBe(true);
    expect(auth.tenant()).toBeNull();
  });

  it('restores the session in the same tab and forgets it on logout', async () => {
    const first = setup();
    await firstValueFrom(first.login(MOCK_USER.email, MOCK_PASSWORD));

    TestBed.resetTestingModule();
    const second = setup();
    expect(second.isAuthenticated()).toBe(true);

    second.logout();
    expect(second.isAuthenticated()).toBe(false);
    expect(sessionStorage.length).toBe(0);
  });
});

/**
 * D-7a/AC-2/AC-2a: the real T-009 contract, asserted at the wire level
 * against `HttpTestingController` — no mock backend. This is the Step 4 red
 * (against the pre-rewrite service) then green proof the plan requires.
 */
describe('AuthService against the real T-009 contract', () => {
  let auth: AuthService;
  let http: HttpTestingController;

  beforeEach(() => {
    sessionStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
      ],
    });
    auth = TestBed.inject(AuthService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('login(): POST auth/login with {email,password}; reads the real LoginResult fields', () => {
    auth.login('a@b.c', 'secret').subscribe();
    const req = http.expectOne('/api/v1/auth/login');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ email: 'a@b.c', password: 'secret' });

    req.flush({
      access_token: 'platform-at',
      refresh_token: 'platform-rt',
      tenants: [
        {
          id: 'TEN001',
          code: 'abc-pharmacy',
          name: 'ABC Pharmacy',
          status: 'active',
          selectable: true,
          is_default: true,
        },
      ],
      default_tenant_id: 'TEN001',
      must_change_password: false,
    });

    expect(auth.token()).toBe('platform-at');
    expect(auth.tenants()).toHaveLength(1);
    expect(auth.tenants()[0]).toMatchObject({
      id: 'TEN001',
      code: 'abc-pharmacy',
      name: 'ABC Pharmacy',
      status: 'active',
      selectable: true,
      is_default: true,
    });
    // No `user` in the body leaves user() null (AC-4).
    expect(auth.user()).toBeNull();
  });

  it('selectTenant(code): POST auth/tenant with {code}, bearing the platform token', () => {
    auth.login('a@b.c', 'secret').subscribe();
    http.expectOne('/api/v1/auth/login').flush({
      access_token: 'platform-at',
      refresh_token: 'platform-rt',
      tenants: [
        {
          id: 'TEN001',
          code: 'abc-pharmacy',
          name: 'ABC Pharmacy',
          status: 'active',
          selectable: true,
          is_default: true,
        },
      ],
      default_tenant_id: 'TEN001',
      must_change_password: false,
    });

    auth.selectTenant('abc-pharmacy').subscribe();
    const req = http.expectOne('/api/v1/auth/tenant');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ code: 'abc-pharmacy' });
    expect(req.request.headers.get('Authorization')).toBe('Bearer platform-at');

    req.flush({
      access_token: 'tenant-at',
      refresh_token: 'tenant-rt',
      tenant_id: 'TEN001',
      tenant_code: 'abc-pharmacy',
    });

    expect(auth.token()).toBe('tenant-at');
    expect(auth.tenant()?.id).toBe('TEN001');
  });

  it('a second selectTenant() after clearContext() still sends the platform bearer, never the stale tenant one', () => {
    auth.login('a@b.c', 'secret').subscribe();
    http.expectOne('/api/v1/auth/login').flush({
      access_token: 'platform-at',
      refresh_token: 'platform-rt',
      tenants: [],
      default_tenant_id: null,
      must_change_password: false,
    });

    auth.selectTenant('abc-pharmacy').subscribe();
    http.expectOne('/api/v1/auth/tenant').flush({
      access_token: 'tenant-at-abc',
      refresh_token: 'tenant-rt-abc',
      tenant_id: 'TEN001',
      tenant_code: 'abc-pharmacy',
    });

    auth.clearContext();

    auth.selectTenant('xyz-pharmacy').subscribe();
    const second = http.expectOne('/api/v1/auth/tenant');
    expect(second.request.headers.get('Authorization')).toBe('Bearer platform-at');
    second.flush({
      access_token: 'tenant-at-xyz',
      refresh_token: 'tenant-rt-xyz',
      tenant_id: 'TEN002',
      tenant_code: 'xyz-pharmacy',
    });
  });

  // QA discrimination check (AC-2a): the spec's own wording is "logs in, selects
  // tenant A, then selects tenant B" with no mention of an intervening
  // clearContext(). The existing test above only exercises the case where
  // clearContext() has already nulled tenantTokens before the second call,
  // which happens to make `auth.token()` fall back to the platform token on
  // its own — so that test still passes even if the interceptor used plain
  // `auth.token()` instead of `auth.platformToken()` for auth/tenant. This
  // test calls selectTenant() a second time with no clearContext() in
  // between, which is the one scenario that actually discriminates the
  // interceptor's special-casing.
  it('QA: a second selectTenant() with NO intervening clearContext() still sends the platform bearer, not the first tenant token', () => {
    auth.login('a@b.c', 'secret').subscribe();
    http.expectOne('/api/v1/auth/login').flush({
      access_token: 'platform-at',
      refresh_token: 'platform-rt',
      tenants: [],
      default_tenant_id: null,
      must_change_password: false,
    });

    auth.selectTenant('abc-pharmacy').subscribe();
    http.expectOne('/api/v1/auth/tenant').flush({
      access_token: 'tenant-at-abc',
      refresh_token: 'tenant-rt-abc',
      tenant_id: 'TEN001',
      tenant_code: 'abc-pharmacy',
    });

    // No clearContext() here, unlike the test above.
    auth.selectTenant('xyz-pharmacy').subscribe();
    const second = http.expectOne('/api/v1/auth/tenant');
    expect(second.request.headers.get('Authorization')).toBe('Bearer platform-at');
    second.flush({
      access_token: 'tenant-at-xyz',
      refresh_token: 'tenant-rt-xyz',
      tenant_id: 'TEN002',
      tenant_code: 'xyz-pharmacy',
    });
  });

  it('logout(): fires one POST auth/logout per non-null token (tenant, then platform), clearing state first', () => {
    auth.login('a@b.c', 'secret').subscribe();
    http.expectOne('/api/v1/auth/login').flush({
      access_token: 'platform-at',
      refresh_token: 'platform-rt',
      tenants: [],
      default_tenant_id: null,
      must_change_password: false,
    });

    auth.selectTenant('abc-pharmacy').subscribe();
    http.expectOne('/api/v1/auth/tenant').flush({
      access_token: 'tenant-at',
      refresh_token: 'tenant-rt',
      tenant_id: 'TEN001',
      tenant_code: 'abc-pharmacy',
    });

    auth.logout();

    // State is cleared synchronously, before either request is flushed.
    expect(auth.isAuthenticated()).toBe(false);
    expect(sessionStorage.length).toBe(0);

    const requests = http.match('/api/v1/auth/logout');
    expect(requests).toHaveLength(2);
    expect(requests[0].request.body).toEqual({ refresh_token: 'tenant-rt' });
    expect(requests[1].request.body).toEqual({ refresh_token: 'platform-rt' });

    requests[0].flush({ status: 'signed_out' });
    requests[1].flush({ status: 'signed_out' });
    expect(auth.isAuthenticated()).toBe(false);
  });

  it('discards a stored session in the old T-012 shape (no platform pair) on restore', () => {
    sessionStorage.setItem(
      'flexipos.session',
      JSON.stringify({ token: 't', user: { id: '1', name: 'A', email: 'a@b.c' }, tenants: [] }),
    );
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
      ],
    });
    const restored = TestBed.inject(AuthService);
    expect(restored.isAuthenticated()).toBe(false);
  });
});
