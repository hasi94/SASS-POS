import {
  HttpErrorResponse,
  HttpInterceptorFn,
  provideHttpClient,
  withInterceptors,
} from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter, withComponentInputBinding } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import {
  AuthService,
  CATALOG_PERMISSIONS,
  MOCK_LIMITED_USER,
  MOCK_PASSWORD,
  MOCK_USER,
  authInterceptor,
  mockBackendInterceptor,
  mockCatalogInterceptor,
  resetMockCatalog,
} from '@flexipos/shared';
import { firstValueFrom, throwError } from 'rxjs';
import { routes } from './app.routes';
import { pageEnvelope, seedSession } from './crud/testing/crud-testing';

/**
 * Answers every request a real page sent on arrival with an empty list, so the
 * `afterEach` verify holds whichever area has become real. Forbidden cases must
 * not call this: they keep `expectNone`.
 */
function drainRequests(http: HttpTestingController): void {
  for (const req of http.match(() => true)) {
    req.flush(pageEnvelope([]));
  }
}

describe('admin routing (T-036 AC-6, AC-9, AC-56)', () => {
  let http: HttpTestingController;
  let router: Router;

  /** `permissions` null means signed out. */
  async function open(url: string, permissions: readonly string[] | null, withContext = true) {
    sessionStorage.clear();
    if (permissions !== null) {
      seedSession(permissions, { withContext });
    }
    TestBed.configureTestingModule({
      providers: [
        provideRouter(routes, withComponentInputBinding()),
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });
    http = TestBed.inject(HttpTestingController);
    router = TestBed.inject(Router);
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(url);
    return harness;
  }

  afterEach(() => http.verify());

  it('sends a person without brands.view from /catalog/brands to /forbidden, with no request', async () => {
    const harness = await open('/catalog/brands', [CATALOG_PERMISSIONS.products.view]);
    expect(router.url).toBe('/forbidden');
    const text = (harness.routeNativeElement as HTMLElement).textContent;
    expect(text).toContain('You do not have permission to open this page.');
    expect(
      (harness.routeNativeElement as HTMLElement).querySelector('a[href="/dashboard"]'),
    ).not.toBeNull();
    http.expectNone(() => true);
  });

  it('does not render the area when only manage is held', async () => {
    await open('/catalog/brands', [CATALOG_PERMISSIONS.brands.manage]);
    expect(router.url).toBe('/forbidden');
    http.expectNone(() => true);
  });

  it('lets a holder of products.view open /catalog/products', async () => {
    const harness = await open('/catalog/products', [CATALOG_PERMISSIONS.products.view]);
    expect(router.url).toBe('/catalog/products');
    expect((harness.routeNativeElement as HTMLElement).textContent).toContain('Products');
    drainRequests(http);
  });

  it('sends /catalog to the first permitted area', async () => {
    await open('/catalog', [CATALOG_PERMISSIONS.units.view]);
    expect(router.url).toBe('/catalog/units');
    drainRequests(http);
  });

  it('sends /catalog to /forbidden when no area is permitted', async () => {
    await open('/catalog', []);
    expect(router.url).toBe('/forbidden');
  });

  it('sends a signed-out person who opens /catalog/products to /login (AC-9)', async () => {
    await open('/catalog/products', null);
    expect(router.url).toBe('/login');
    http.expectNone(() => true);
  });

  it('sends a person with no company chosen to /context (AC-9)', async () => {
    await open('/catalog/products', [CATALOG_PERMISSIONS.products.view], false);
    expect(router.url).toBe('/context');
    http.expectNone(() => true);
  });

  it('sends /customers to /forbidden even for a person holding every catalog key (AC-56)', async () => {
    const all = Object.values(CATALOG_PERMISSIONS).flatMap((p) => [p.view, p.manage]);
    await open('/customers', all);
    expect(router.url).toBe('/forbidden');
  });

  it('keeps the Customers placeholder, and its route data points at F-2 (AC-56)', () => {
    const shell = routes.find((r) => r.path === '' && r.children);
    const customers = shell?.children?.find((r) => r.path === 'customers');
    expect(customers).toBeDefined();
    expect((customers?.data as { arrivesWith: string }).arrivesWith).toContain('F-2');
    expect(customers?.canActivate).toHaveLength(1);
  });

  it('loads catalog lazily: no eager component on the catalog route (AC-56)', () => {
    const shell = routes.find((r) => r.path === '' && r.children);
    const catalog = shell?.children?.find((r) => r.path === 'catalog');
    expect(typeof catalog?.loadChildren).toBe('function');
    expect(catalog?.component).toBeUndefined();
    expect(catalog?.loadComponent).toBeUndefined();
  });
});

describe('admin end to end against the mock backend (T-036 plan 4.1, AC-8, AC-26)', () => {
  /** Refuses the products list the way the server would for a person without the key. */
  const refuseProducts: HttpInterceptorFn = (req, next) =>
    req.method === 'GET' && req.url.endsWith('/catalog/products')
      ? throwError(
          () =>
            new HttpErrorResponse({
              url: req.url,
              status: 403,
              error: {
                success: false,
                data: null,
                message: 'You do not have permission to do that.',
                errors: [{ code: 'kernel.forbidden' }],
                meta: { request_id: 'req-injected' },
              },
            }),
        )
      : next(req);

  /** The real interceptors, in the order an app wires them; the sign-in goes through them too. */
  async function signIn(email: string, injected: HttpInterceptorFn[] = []) {
    sessionStorage.clear();
    resetMockCatalog();
    TestBed.configureTestingModule({
      providers: [
        provideRouter(routes, withComponentInputBinding()),
        provideHttpClient(
          withInterceptors([
            authInterceptor,
            ...injected,
            mockCatalogInterceptor,
            mockBackendInterceptor,
          ]),
        ),
      ],
    });
    const auth = TestBed.inject(AuthService);
    await firstValueFrom(auth.login(email, MOCK_PASSWORD));
    await firstValueFrom(auth.selectTenant('abc-pharmacy'));
    auth.selectBranch('BR001');
    return TestBed.inject(Router);
  }

  async function show(url: string) {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(url);
    const root = harness.routeNativeElement as HTMLElement;
    const shell = harness.fixture.nativeElement as HTMLElement;
    const until = (check: () => boolean) =>
      vi.waitFor(() => {
        harness.detectChanges();
        expect(check()).toBe(true);
      });
    return { harness, root, shell, until };
  }

  afterEach(() => sessionStorage.clear());

  it('the demo user signs in, picks company and branch, and sees the seeded categories', async () => {
    const router = await signIn(MOCK_USER.email);
    const { root, until } = await show('/catalog/categories');
    expect(router.url).toBe('/catalog/categories');
    await until(() => root.querySelectorAll('tbody tr').length === 3);
    const rows = Array.from(root.querySelectorAll('tbody tr')).map((r) => r.textContent ?? '');
    for (const name of ['Food', 'Drinks', 'Retail']) {
      expect(
        rows.some((r) => r.includes(name)),
        name,
      ).toBe(true);
    }
  });

  it('the limited user sees only Products in the menu, and /catalog/brands goes to /forbidden', async () => {
    const router = await signIn(MOCK_LIMITED_USER.email);
    const { shell, harness, until } = await show('/dashboard');
    const links = () =>
      Array.from(shell.querySelectorAll('nav a')).map((a) => a.textContent?.trim());
    expect(links()).toEqual(['Dashboard', 'Products']);

    await harness.navigateByUrl('/catalog/brands');
    expect(router.url).toBe('/forbidden');
    await until(() => (shell.textContent ?? '').includes('You do not have permission'));
  });

  it('a 403 on the products list shows the permission message end to end', async () => {
    const router = await signIn(MOCK_LIMITED_USER.email, [refuseProducts]);
    const { root, until } = await show('/catalog/products');
    await until(() =>
      (root.textContent ?? '').includes('You do not have permission to see or change this.'),
    );
    expect(router.url).toBe('/catalog/products');
    expect(root.querySelector('[role="alert"]')).not.toBeNull();
  });

  it('the limited user can read products from the mock without the injected refusal', async () => {
    await signIn(MOCK_LIMITED_USER.email);
    const { root, until } = await show('/catalog/products');
    await until(() => root.querySelectorAll('tbody tr').length > 0);
    expect(root.textContent ?? '').not.toContain('You do not have permission');
  });
});
