import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter, withComponentInputBinding } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { ALL_CATALOG_PERMISSIONS, CATALOG_PERMISSIONS } from '@flexipos/shared';
import { routes } from '../app.routes';
import { refusal, seedSession } from '../crud/testing/crud-testing';

/**
 * QA matrix for T-036 AC-4 to AC-9: every catalog route against the keys it needs. For each
 * route the person is let in with exactly the required keys and with every key, and is sent
 * to /forbidden when any single required key is the only one missing, with nothing held,
 * with an absent set, with a set that is not an array, and as the limited demo user. The
 * signed-out and no-company cases are checked on every route too. A forbidden outcome must
 * also send no HTTP request at all.
 */

const P = CATALOG_PERMISSIONS;

interface RouteCase {
  name: string;
  url: string;
  needs: readonly string[];
}

const ROUTES: RouteCase[] = [
  { name: 'categories list', url: '/catalog/categories', needs: [P.categories.view] },
  {
    name: 'categories new',
    url: '/catalog/categories/new',
    needs: [P.categories.view, P.categories.manage],
  },
  {
    name: 'categories edit',
    url: '/catalog/categories/C1/edit',
    needs: [P.categories.view, P.categories.manage],
  },
  { name: 'brands list', url: '/catalog/brands', needs: [P.brands.view] },
  { name: 'brands new', url: '/catalog/brands/new', needs: [P.brands.view, P.brands.manage] },
  {
    name: 'brands edit',
    url: '/catalog/brands/B1/edit',
    needs: [P.brands.view, P.brands.manage],
  },
  { name: 'units list', url: '/catalog/units', needs: [P.units.view] },
  { name: 'units new', url: '/catalog/units/new', needs: [P.units.view, P.units.manage] },
  {
    name: 'conversions new',
    url: '/catalog/units/conversions/new',
    needs: [P.units.view, P.units.manage],
  },
  { name: 'units edit', url: '/catalog/units/U1/edit', needs: [P.units.view, P.units.manage] },
  { name: 'products list', url: '/catalog/products', needs: [P.products.view] },
  {
    name: 'products new',
    url: '/catalog/products/new',
    needs: [P.products.view, P.products.manage],
  },
  { name: 'product detail', url: '/catalog/products/P1', needs: [P.products.view] },
  {
    name: 'products edit',
    url: '/catalog/products/P1/edit',
    needs: [P.products.view, P.products.manage],
  },
  {
    name: 'barcode new',
    url: '/catalog/products/P1/barcodes/new',
    needs: [P.products.view, P.barcodes.view, P.barcodes.manage],
  },
  {
    name: 'barcode edit',
    url: '/catalog/products/P1/barcodes/K1/edit',
    needs: [P.products.view, P.barcodes.view, P.barcodes.manage],
  },
  { name: 'modifier groups list', url: '/catalog/modifiers', needs: [P.modifiers.view] },
  {
    name: 'modifier group new',
    url: '/catalog/modifiers/new',
    needs: [P.modifiers.view, P.modifiers.manage],
  },
  { name: 'modifier group detail', url: '/catalog/modifiers/G1', needs: [P.modifiers.view] },
  {
    name: 'modifier group edit',
    url: '/catalog/modifiers/G1/edit',
    needs: [P.modifiers.view, P.modifiers.manage],
  },
  {
    name: 'modifier new',
    url: '/catalog/modifiers/G1/modifiers/new',
    needs: [P.modifiers.view, P.modifiers.manage],
  },
  {
    name: 'modifier edit',
    url: '/catalog/modifiers/G1/modifiers/M1/edit',
    needs: [P.modifiers.view, P.modifiers.manage],
  },
];

describe('catalog route permission matrix (T-036 AC-4 to AC-9, QA)', () => {
  let http: HttpTestingController;
  let router: Router;

  /** Opens `url`. `permissions` null is signed out; undefined leaves the field absent. */
  async function open(
    url: string,
    permissions: readonly string[] | null | undefined,
    options: { withContext?: boolean; raw?: unknown } = {},
  ): Promise<void> {
    sessionStorage.clear();
    if (permissions !== null) {
      seedSession(permissions, { withContext: options.withContext ?? true });
      if (options.raw !== undefined) {
        const stored = JSON.parse(sessionStorage.getItem('flexipos.session') as string) as Record<
          string,
          unknown
        >;
        stored['permissions'] = options.raw;
        sessionStorage.setItem('flexipos.session', JSON.stringify(stored));
      }
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
  }

  /** A route that was let through sends requests; answer them all with a 404 so nothing renders data. */
  function settle(): void {
    for (const req of http.match(() => true)) {
      req.flush(...refusal(404, 'kernel.not_found'));
    }
  }

  function expectForbiddenWithoutRequests(): void {
    expect(router.url).toBe('/forbidden');
    http.expectNone(() => true);
  }

  afterEach(() => http.verify());

  describe.each(ROUTES)('$name ($url)', ({ url, needs }) => {
    it('lets in a person holding exactly the required keys', async () => {
      await open(url, needs);
      expect(router.url).toBe(url);
      settle();
    });

    it('lets in a person holding every catalog key', async () => {
      await open(url, ALL_CATALOG_PERMISSIONS);
      expect(router.url).toBe(url);
      settle();
    });

    it.each(needs)('sends to /forbidden when only %s is missing', async (missing) => {
      await open(
        url,
        ALL_CATALOG_PERMISSIONS.filter((k) => k !== missing),
      );
      expectForbiddenWithoutRequests();
    });

    it('sends to /forbidden with nothing held', async () => {
      await open(url, []);
      expectForbiddenWithoutRequests();
    });

    it('sends to /forbidden when the session has no permissions field at all', async () => {
      await open(url, undefined);
      expectForbiddenWithoutRequests();
    });

    it.each([
      ['a string', P.products.view],
      ['an object', { [P.products.view]: true }],
      ['null', null],
      ['a number', 1],
    ])('sends to /forbidden when the stored permissions are %s, not a list', async (_n, raw) => {
      await open(url, [], { raw });
      expectForbiddenWithoutRequests();
    });

    it('sends the limited demo user (products.view only) to /forbidden unless this is the products list or detail', async () => {
      await open(url, [P.products.view]);
      const allowed = needs.length === 1 && needs[0] === P.products.view;
      if (allowed) {
        expect(router.url).toBe(url);
        settle();
      } else {
        expectForbiddenWithoutRequests();
      }
    });

    it('sends a signed-out person to /login with no request', async () => {
      await open(url, null);
      expect(router.url).toBe('/login');
      http.expectNone(() => true);
    });

    it('sends a signed-in person with no company to /context with no request', async () => {
      await open(url, ALL_CATALOG_PERMISSIONS, { withContext: false });
      expect(router.url).toBe('/context');
      http.expectNone(() => true);
    });
  });

  it('control: a permitted list route really requests data, so "no request" above can fail', async () => {
    await open('/catalog/brands', [P.brands.view]);
    const sent = http.match(() => true);
    expect(sent.length).toBeGreaterThan(0);
    for (const r of sent) {
      r.flush(...refusal(404, 'kernel.not_found'));
    }
  });
});
