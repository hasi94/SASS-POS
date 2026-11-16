import {
  HttpClient,
  HttpHeaders,
  HttpInterceptorFn,
  provideHttpClient,
  withInterceptors,
} from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Observable, firstValueFrom } from 'rxjs';
import { ApiClient } from '../../api/api-client';
import { ApiError } from '../../api/envelope';
import { SelectTenantResult } from '../../auth/auth.models';
import { authInterceptor } from '../../auth/auth.interceptor';
import { AuthService } from '../../auth/auth.service';
import {
  ALL_CATALOG_PERMISSIONS,
  CATALOG_PERMISSIONS,
} from '../../contracts/catalog/catalog-permissions';
import { CATALOG_ENDPOINTS } from '../../contracts/catalog/catalog.endpoints';
import {
  Brand,
  Category,
  Modifier,
  ModifierGroup,
  Product,
  ProductBarcode,
  Unit,
  UnitConversion,
} from '../../contracts/catalog/catalog.models';
import {
  MOCK_LIMITED_USER,
  MOCK_PASSWORD,
  MOCK_USER,
  mockBackendInterceptor,
} from '../mock-backend';
import { mockCatalogInterceptor } from './mock-catalog.interceptor';
import { MOCK_CATALOG_SENTINEL, resetMockCatalog } from './mock-catalog.store';

interface LoginBody {
  access_token: string;
  refresh_token: string;
}

describe('the auth mock: two demo users (T-036 AC-26, first half)', () => {
  let http: HttpClient;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(withInterceptors([mockBackendInterceptor]))],
    });
    http = TestBed.inject(HttpClient);
  });

  function bearer(token: string): { headers: HttpHeaders } {
    return { headers: new HttpHeaders({ Authorization: `Bearer ${token}` }) };
  }

  async function login(email: string): Promise<LoginBody> {
    return firstValueFrom(
      http.post<LoginBody>('/api/v1/auth/login', { email, password: MOCK_PASSWORD }),
    );
  }

  async function selectTenant(platformToken: string): Promise<SelectTenantResult> {
    return firstValueFrom(
      http.post<SelectTenantResult>(
        '/api/v1/auth/tenant',
        { code: 'abc-pharmacy' },
        bearer(platformToken),
      ),
    );
  }

  it('gives the demo user every catalog key', async () => {
    const platform = await login(MOCK_USER.email);
    const tenant = await selectTenant(platform.access_token);
    expect(tenant.permissions).toEqual([...ALL_CATALOG_PERMISSIONS]);
    expect(tenant.permissions).toHaveLength(12);
  });

  it('gives the limited user exactly the products view key', async () => {
    const platform = await login(MOCK_LIMITED_USER.email);
    const tenant = await selectTenant(platform.access_token);
    expect(tenant.permissions).toEqual([CATALOG_PERMISSIONS.products.view]);
  });

  it('keeps the demo user tokens exactly as they were', async () => {
    const platform = await login(MOCK_USER.email);
    expect(platform.access_token).toBe('mock-platform-access');
    expect(platform.refresh_token).toBe('mock-platform-refresh');
    const tenant = await selectTenant(platform.access_token);
    expect(tenant.access_token).toBe('mock-tenant-access-TEN001');
    expect(tenant.refresh_token).toBe('mock-tenant-refresh-TEN001');
  });

  it('lets the limited user platform token select a tenant, with a distinct tenant token', async () => {
    const platform = await login(MOCK_LIMITED_USER.email);
    expect(platform.access_token).not.toBe('mock-platform-access');
    const tenant = await selectTenant(platform.access_token);
    expect(tenant.tenant_code).toBe('abc-pharmacy');
    expect(tenant.access_token.endsWith('-limited')).toBe(true);
  });

  it('refuses a wrong password for the limited user', async () => {
    await expect(
      firstValueFrom(
        http.post('/api/v1/auth/login', { email: MOCK_LIMITED_USER.email, password: 'nope' }),
      ),
    ).rejects.toMatchObject({ status: 401 });
  });

  it('still refuses a tenant token at auth/tenant with wrong_token_type, for both users', async () => {
    for (const email of [MOCK_USER.email, MOCK_LIMITED_USER.email]) {
      const platform = await login(email);
      const tenant = await selectTenant(platform.access_token);
      await expect(
        firstValueFrom(
          http.post('/api/v1/auth/tenant', { code: 'abc-pharmacy' }, bearer(tenant.access_token)),
        ),
      ).rejects.toMatchObject({ status: 403, error: { error: 'wrong_token_type' } });
    }
  });
});

// ---------------------------------------------------------------------------
// The catalog mock itself (T-036 AC-24, AC-25, AC-26 second half)
// ---------------------------------------------------------------------------

const DEMO_BEARER = 'mock-tenant-access-TEN001';
const LIMITED_BEARER = 'mock-tenant-access-TEN001-limited';

/** Stamps a fixed bearer on every request, standing in for a signed-in session. */
function asBearer(token: string | null): HttpInterceptorFn {
  return (req, next) =>
    next(token ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } }) : req);
}

function configure(token: string | null): ApiClient {
  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(
        withInterceptors([asBearer(token), mockCatalogInterceptor, mockBackendInterceptor]),
      ),
    ],
  });
  return TestBed.inject(ApiClient);
}

/** The refusal an observable ends with; fails the test if it succeeds. */
async function refused(source: Observable<unknown>): Promise<ApiError> {
  try {
    await firstValueFrom(source);
  } catch (e) {
    return e as ApiError;
  }
  throw new Error('expected the mock to refuse, but it answered');
}

function onlyItem(err: ApiError): { code: string; field?: string | null; detail?: string } {
  expect(err.errors).toHaveLength(1);
  return err.errors[0];
}

describe('the catalog mock (T-036 AC-24, AC-25)', () => {
  let api: ApiClient;

  beforeEach(() => {
    resetMockCatalog();
    api = configure(DEMO_BEARER);
  });

  describe('envelope and pagination', () => {
    it('lists inside the real envelope with pagination and the sentinel request id', async () => {
      const r = await firstValueFrom(api.getWithMeta<Category[]>(CATALOG_ENDPOINTS.categories));
      expect(r.data.map((c) => c.code)).toEqual(['FOOD', 'DRINKS', 'RETAIL']);
      expect(r.meta.pagination).toEqual({ page: 1, per_page: 25, total: 3 });
      expect(r.meta.request_id).toContain(MOCK_CATALOG_SENTINEL);
    });

    it('pages with page and per_page', async () => {
      const r = await firstValueFrom(
        api.getWithMeta<Category[]>(CATALOG_ENDPOINTS.categories, { page: 2, per_page: 2 }),
      );
      expect(r.data.map((c) => c.code)).toEqual(['RETAIL']);
      expect(r.meta.pagination).toEqual({ page: 2, per_page: 2, total: 3 });
    });

    it('searches case-insensitively', async () => {
      const r = await firstValueFrom(
        api.getWithMeta<Product[]>(CATALOG_ENDPOINTS.products, { search: 'wAtEr' }),
      );
      expect(r.data.map((p) => p.sku)).toEqual(['WATER-500']);
      expect(r.meta.pagination?.total).toBe(1);
    });

    it('stamps the sentinel on a refusal too', async () => {
      const err = await refused(api.get(CATALOG_ENDPOINTS.brand('NOPE')));
      expect(err.status).toBe(404);
      expect(err.requestId).toContain(MOCK_CATALOG_SENTINEL);
      expect(onlyItem(err).code).toBe('kernel.not_found');
    });
  });

  describe('categories', () => {
    it('lists the tree with parent name, path and depth', async () => {
      const rows = await firstValueFrom(api.get<Category[]>(CATALOG_ENDPOINTS.categories));
      const drinks = rows.find((c) => c.code === 'DRINKS');
      expect(drinks).toMatchObject({
        parent_id: 'CAT0001',
        parent_name: 'Food',
        depth: 1,
        path: 'CAT0001/CAT0002',
      });
    });

    it('creates, gets, updates (a move) and deletes', async () => {
      const created = await firstValueFrom(
        api.post<Category>(CATALOG_ENDPOINTS.categories, {
          code: 'SNACKS',
          name: 'Snacks',
          parent_id: 'CAT0003',
        }),
      );
      expect(created).toMatchObject({ code: 'SNACKS', depth: 1, parent_name: 'Retail' });
      const moved = await firstValueFrom(
        api.put<Category>(CATALOG_ENDPOINTS.category(created.id), {
          code: 'SNACKS',
          name: 'Snacks',
          parent_id: 'CAT0001',
        }),
      );
      expect(moved.parent_name).toBe('Food');
      expect(
        (await firstValueFrom(api.get<Category>(CATALOG_ENDPOINTS.category(created.id)))).path,
      ).toBe(`CAT0001/${created.id}`);
      await firstValueFrom(api.delete(CATALOG_ENDPOINTS.category(created.id)));
      const rows = await firstValueFrom(api.get<Category[]>(CATALOG_ENDPOINTS.categories));
      expect(rows.map((c) => c.code)).not.toContain('SNACKS');
    });

    it('refuses a blank code and a blank name, one item each, with the field', async () => {
      const err = await refused(api.post(CATALOG_ENDPOINTS.categories, { code: ' ', name: '' }));
      expect(err.status).toBe(422);
      expect(err.fieldDetail('code')).toBeDefined();
      expect(err.fieldDetail('name')).toBeDefined();
      expect(err.hasCode('kernel.validation_failed')).toBe(true);
    });

    it('refuses a duplicate code on the code field and names the owner', async () => {
      const err = await refused(
        api.post(CATALOG_ENDPOINTS.categories, { code: 'food', name: 'Other' }),
      );
      expect(err.status).toBe(422);
      expect(onlyItem(err)).toMatchObject({ code: 'catalog.duplicate', field: 'code' });
      expect(err.fieldDetail('code')).toContain('Food');
    });

    it('refuses a missing parent on parent_id', async () => {
      const err = await refused(
        api.post(CATALOG_ENDPOINTS.categories, { code: 'X', name: 'X', parent_id: 'NOPE' }),
      );
      expect(onlyItem(err)).toMatchObject({ code: 'kernel.validation_failed', field: 'parent_id' });
    });

    it('refuses a soft-deleted parent', async () => {
      const made = await firstValueFrom(
        api.post<Category>(CATALOG_ENDPOINTS.categories, { code: 'T', name: 'T' }),
      );
      await firstValueFrom(api.delete(CATALOG_ENDPOINTS.category(made.id)));
      const err = await refused(
        api.post(CATALOG_ENDPOINTS.categories, { code: 'U', name: 'U', parent_id: made.id }),
      );
      expect(onlyItem(err).field).toBe('parent_id');
    });

    it('refuses a cycle on parent_id', async () => {
      const err = await refused(
        api.put(CATALOG_ENDPOINTS.category('CAT0001'), {
          code: 'FOOD',
          name: 'Food',
          parent_id: 'CAT0002',
        }),
      );
      expect(err.status).toBe(422);
      expect(onlyItem(err)).toMatchObject({ code: 'catalog.cycle', field: 'parent_id' });
    });

    it('refuses a move under itself', async () => {
      const err = await refused(
        api.put(CATALOG_ENDPOINTS.category('CAT0001'), {
          code: 'FOOD',
          name: 'Food',
          parent_id: 'CAT0001',
        }),
      );
      expect(onlyItem(err).code).toBe('catalog.cycle');
    });

    it('refuses a tree deeper than 17 levels on parent_id', async () => {
      let parent: string | null = null;
      for (let level = 0; level <= 17; level++) {
        const made: Category = await firstValueFrom(
          api.post<Category>(CATALOG_ENDPOINTS.categories, {
            code: `L${level}`,
            name: `L${level}`,
            parent_id: parent,
          }),
        );
        expect(made.depth).toBe(level);
        parent = made.id;
      }
      const err = await refused(
        api.post(CATALOG_ENDPOINTS.categories, { code: 'L18', name: 'L18', parent_id: parent }),
      );
      expect(onlyItem(err)).toMatchObject({ code: 'catalog.too_deep', field: 'parent_id' });
    });

    it('blocks a delete while an active child exists, naming it, and keeps the row', async () => {
      const err = await refused(api.delete(CATALOG_ENDPOINTS.category('CAT0001')));
      expect(err.status).toBe(409);
      expect(onlyItem(err)).toMatchObject({ code: 'catalog.in_use' });
      expect(err.message).toContain('Drinks');
      const rows = await firstValueFrom(api.get<Category[]>(CATALOG_ENDPOINTS.categories));
      expect(rows.map((c) => c.code)).toContain('FOOD');
    });

    it('blocks a delete while an active product holds the category, naming it', async () => {
      const err = await refused(api.delete(CATALOG_ENDPOINTS.category('CAT0002')));
      expect(err.status).toBe(409);
      expect(err.message).toContain('WATER-500');
    });
  });

  describe('brands', () => {
    it('creates, reads, updates and deletes', async () => {
      const made = await firstValueFrom(
        api.post<Brand>(CATALOG_ENDPOINTS.brands, { code: 'NEW', name: 'New brand' }),
      );
      expect(made.is_active).toBe(true);
      const updated = await firstValueFrom(
        api.put<Brand>(CATALOG_ENDPOINTS.brand(made.id), { code: 'NEW', name: 'Renamed' }),
      );
      expect(updated.name).toBe('Renamed');
      await firstValueFrom(api.delete(CATALOG_ENDPOINTS.brand(made.id)));
      const rows = await firstValueFrom(api.get<Brand[]>(CATALOG_ENDPOINTS.brands));
      expect(rows.map((b) => b.code)).toEqual(['ACME', 'GLOBEX']);
    });

    it('refuses a duplicate code on code and a duplicate name on name', async () => {
      const code = await refused(
        api.post(CATALOG_ENDPOINTS.brands, { code: 'acme', name: 'Other' }),
      );
      expect(onlyItem(code)).toMatchObject({ code: 'catalog.duplicate', field: 'code' });
      const name = await refused(
        api.post(CATALOG_ENDPOINTS.brands, { code: 'OTHER', name: 'ACME' }),
      );
      expect(onlyItem(name)).toMatchObject({ code: 'catalog.duplicate', field: 'name' });
    });

    it('blocks a delete while an active product uses the brand, naming the product', async () => {
      const err = await refused(api.delete(CATALOG_ENDPOINTS.brand('BRA0001')));
      expect(err.status).toBe(409);
      expect(err.message).toContain('WATER-500');
      await firstValueFrom(api.delete(CATALOG_ENDPOINTS.brand('BRA0002')));
    });
  });

  describe('units and conversions', () => {
    it('lists units with the base unit code and factor', async () => {
      const rows = await firstValueFrom(api.get<Unit[]>(CATALOG_ENDPOINTS.units));
      expect(rows.find((u) => u.code === 'CASE')).toMatchObject({
        base_unit_code: 'EA',
        factor: '24.0000',
        dimension: 'count',
      });
    });

    it('creates with the default precision of 4, updates, and deletes an unused unit', async () => {
      const made = await firstValueFrom(
        api.post<Unit>(CATALOG_ENDPOINTS.units, {
          code: 'M',
          name: 'Metre',
          dimension: 'length',
          base_unit_id: null,
          factor: null,
        }),
      );
      expect(made.precision).toBe(4);
      const updated = await firstValueFrom(
        api.put<Unit>(CATALOG_ENDPOINTS.unit(made.id), {
          code: 'M',
          name: 'Meter',
          precision: 2,
          base_unit_id: null,
          factor: null,
        }),
      );
      expect(updated).toMatchObject({ name: 'Meter', precision: 2, dimension: 'length' });
      await firstValueFrom(api.delete(CATALOG_ENDPOINTS.unit(made.id)));
      const rows = await firstValueFrom(api.get<Unit[]>(CATALOG_ENDPOINTS.units));
      expect(rows.map((u) => u.code)).not.toContain('M');
    });

    it.each([5, -1, 1.5])('refuses precision %s on the precision field', async (precision) => {
      const err = await refused(
        api.post(CATALOG_ENDPOINTS.units, { code: 'Q', name: 'Q', dimension: 'count', precision }),
      );
      expect(err.status).toBe(422);
      expect(err.fieldDetail('precision')).toBeDefined();
    });

    it('refuses a base unit without a factor, and a factor without a base unit', async () => {
      const a = await refused(
        api.post(CATALOG_ENDPOINTS.units, {
          code: 'Q',
          name: 'Q',
          dimension: 'count',
          base_unit_id: 'UNI0001',
        }),
      );
      expect(a.fieldDetail('factor')).toContain('together');
      const b = await refused(
        api.post(CATALOG_ENDPOINTS.units, {
          code: 'Q',
          name: 'Q',
          dimension: 'count',
          factor: '2',
        }),
      );
      expect(b.fieldDetail('factor')).toContain('together');
    });

    it('refuses a factor of zero', async () => {
      const err = await refused(
        api.post(CATALOG_ENDPOINTS.units, {
          code: 'Q',
          name: 'Q',
          dimension: 'count',
          base_unit_id: 'UNI0001',
          factor: 0,
        }),
      );
      expect(err.fieldDetail('factor')).toBeDefined();
    });

    it('refuses a base unit of another dimension on base_unit_id', async () => {
      const err = await refused(
        api.post(CATALOG_ENDPOINTS.units, {
          code: 'Q',
          name: 'Q',
          dimension: 'count',
          base_unit_id: 'UNI0003',
          factor: 2,
        }),
      );
      expect(onlyItem(err)).toMatchObject({
        code: 'catalog.dimension_mismatch',
        field: 'base_unit_id',
      });
    });

    it('refuses a change of dimension as immutable', async () => {
      const err = await refused(
        api.put(CATALOG_ENDPOINTS.unit('UNI0001'), {
          code: 'EA',
          name: 'Each',
          dimension: 'mass',
          precision: 0,
        }),
      );
      expect(onlyItem(err)).toMatchObject({ code: 'catalog.immutable', field: 'dimension' });
    });

    it('refuses a duplicate unit code', async () => {
      const err = await refused(
        api.post(CATALOG_ENDPOINTS.units, { code: 'ea', name: 'Again', dimension: 'count' }),
      );
      expect(onlyItem(err)).toMatchObject({ code: 'catalog.duplicate', field: 'code' });
    });

    it('blocks deleting a referenced unit, naming the blocker', async () => {
      const used = await refused(api.delete(CATALOG_ENDPOINTS.unit('UNI0001')));
      expect(used.status).toBe(409);
      expect(used.message).toContain('EA');
      const based = await refused(api.delete(CATALOG_ENDPOINTS.unit('UNI0005')));
      expect(based.message).toContain('ML');
    });

    it('creates, reads, filters and deletes a conversion', async () => {
      const made = await firstValueFrom(
        api.post<UnitConversion>(CATALOG_ENDPOINTS.unitConversions, {
          from_unit_id: 'UNI0005',
          to_unit_id: 'UNI0006',
          factor: '1000',
          product_id: null,
        }),
      );
      expect(made).toMatchObject({
        from_unit_code: 'L',
        to_unit_code: 'ML',
        product_name: null,
        factor: '1000.0000',
      });
      expect(
        (await firstValueFrom(api.get<UnitConversion>(CATALOG_ENDPOINTS.unitConversion(made.id))))
          .id,
      ).toBe(made.id);
      const scoped = await firstValueFrom(
        api.get<UnitConversion[]>(CATALOG_ENDPOINTS.unitConversions, { product_id: 'PRO0005' }),
      );
      expect(scoped).toEqual([]);
      await firstValueFrom(api.delete(CATALOG_ENDPOINTS.unitConversion(made.id)));
      const all = await firstValueFrom(
        api.get<UnitConversion[]>(CATALOG_ENDPOINTS.unitConversions),
      );
      expect(all.map((c) => c.id)).toEqual(['CNV0001']);
    });

    it('refuses equal units, a bad factor, a cross-dimension pair and a duplicate pair naming the row', async () => {
      const base = {
        from_unit_id: 'UNI0003',
        to_unit_id: 'UNI0004',
        factor: '1000',
        product_id: null,
      };
      expect(
        (
          await refused(
            api.post(CATALOG_ENDPOINTS.unitConversions, { ...base, to_unit_id: 'UNI0003' }),
          )
        ).fieldDetail('to_unit_id'),
      ).toBeDefined();
      expect(
        (
          await refused(api.post(CATALOG_ENDPOINTS.unitConversions, { ...base, factor: '0' }))
        ).fieldDetail('factor'),
      ).toBeDefined();
      const cross = await refused(
        api.post(CATALOG_ENDPOINTS.unitConversions, { ...base, to_unit_id: 'UNI0001' }),
      );
      expect(onlyItem(cross)).toMatchObject({
        code: 'catalog.dimension_mismatch',
        field: 'to_unit_id',
      });
      const twin = await refused(api.post(CATALOG_ENDPOINTS.unitConversions, base));
      expect(onlyItem(twin)).toMatchObject({ code: 'catalog.duplicate', field: 'to_unit_id' });
      expect(twin.fieldDetail('to_unit_id')).toContain('CNV0001');
    });

    it('refuses a product-scoped conversion whose to unit is not the stock unit', async () => {
      const err = await refused(
        api.post(CATALOG_ENDPOINTS.unitConversions, {
          from_unit_id: 'UNI0004',
          to_unit_id: 'UNI0003',
          factor: '0.001',
          product_id: 'PRO0001',
        }),
      );
      expect(err.status).toBe(422);
      const wrongStock = await refused(
        api.post(CATALOG_ENDPOINTS.unitConversions, {
          from_unit_id: 'UNI0002',
          to_unit_id: 'UNI0001',
          factor: '24',
          product_id: 'PRO0005',
        }),
      );
      expect(wrongStock.fieldDetail('to_unit_id')).toContain('KG');
    });
  });

  describe('products and variants', () => {
    it('excludes a soft-deleted product from the list but returns it by id', async () => {
      const rows = await firstValueFrom(api.get<Product[]>(CATALOG_ENDPOINTS.products));
      expect(rows.map((p) => p.sku)).not.toContain('OLD-ITEM');
      const byId = await firstValueFrom(api.get<Product>(CATALOG_ENDPOINTS.product('PRO0006')));
      expect(byId.deleted_at).not.toBeNull();
    });

    it('filters by type and by parent', async () => {
      const variants = await firstValueFrom(
        api.get<Product[]>(CATALOG_ENDPOINTS.products, { type: 'variant' }),
      );
      expect(variants.map((p) => p.sku)).toEqual(['TEE-S', 'TEE-M']);
      const children = await firstValueFrom(
        api.get<Product[]>(CATALOG_ENDPOINTS.products, { parent_id: 'PRO0002' }),
      );
      expect(children).toHaveLength(2);
      const parents = await firstValueFrom(
        api.get<Product[]>(CATALOG_ENDPOINTS.products, { type: 'variant_parent' }),
      );
      expect(parents.map((p) => p.sku)).toEqual(['TEE']);
    });

    const valid = {
      type: 'simple',
      sku: 'NEW-1',
      name: 'New product',
      category_id: 'CAT0002',
      brand_id: null,
      stock_unit_id: 'UNI0001',
      purchase_unit_id: null,
      sales_unit_id: null,
      is_weighable: false,
      price_mode: 'per_unit',
      track_stock: true,
      tracking_mode: 'none',
    };

    it('creates a product with display names and version 1', async () => {
      const made = await firstValueFrom(api.post<Product>(CATALOG_ENDPOINTS.products, valid));
      expect(made).toMatchObject({
        version: 1,
        category_name: 'Drinks',
        stock_unit_code: 'EA',
        plu_code: null,
        parent_id: null,
        tracking_mode: 'none',
      });
    });

    it('updates with the loaded version and increments it', async () => {
      const loaded = await firstValueFrom(api.get<Product>(CATALOG_ENDPOINTS.product('PRO0001')));
      const body = { ...valid, sku: loaded.sku, name: 'Renamed', version: loaded.version };
      const updated = await firstValueFrom(
        api.put<Product>(CATALOG_ENDPOINTS.product('PRO0001'), body),
      );
      expect(updated).toMatchObject({
        name: 'Renamed',
        version: loaded.version + 1,
        stock_unit_id: 'UNI0001',
      });
    });

    it('answers 409 stale_version for an old version', async () => {
      const err = await refused(
        api.put(CATALOG_ENDPOINTS.product('PRO0001'), { ...valid, sku: 'WATER-500', version: 99 }),
      );
      expect(err.status).toBe(409);
      expect(onlyItem(err).code).toBe('catalog.stale_version');
      expect(onlyItem(err).field).toBeUndefined();
    });

    it('refuses a changed stock unit as immutable on stock_unit_id', async () => {
      const err = await refused(
        api.put(CATALOG_ENDPOINTS.product('PRO0001'), {
          ...valid,
          sku: 'WATER-500',
          stock_unit_id: 'UNI0003',
          version: 1,
        }),
      );
      expect(onlyItem(err)).toMatchObject({ code: 'catalog.immutable', field: 'stock_unit_id' });
    });

    it('clears a PLU only when plu_code is sent as null, and keeps it when the key is absent', async () => {
      const kept = await firstValueFrom(
        api.put<Product>(CATALOG_ENDPOINTS.product('PRO0005'), {
          ...valid,
          sku: 'APPLES-KG',
          stock_unit_id: 'UNI0003',
          version: 1,
        }),
      );
      expect(kept.plu_code).toBe('4011');
      const cleared = await firstValueFrom(
        api.put<Product>(CATALOG_ENDPOINTS.product('PRO0005'), {
          ...valid,
          sku: 'APPLES-KG',
          stock_unit_id: 'UNI0003',
          plu_code: null,
          version: kept.version,
        }),
      );
      expect(cleared.plu_code).toBeNull();
    });

    it('refuses blank required fields and an over-long SKU and PLU by field', async () => {
      const blank = await refused(api.post(CATALOG_ENDPOINTS.products, { type: 'simple' }));
      for (const f of ['sku', 'name', 'category_id', 'stock_unit_id']) {
        expect(blank.fieldDetail(f), f).toBeDefined();
      }
      const longSku = await refused(
        api.post(CATALOG_ENDPOINTS.products, { ...valid, sku: 'x'.repeat(51) }),
      );
      expect(onlyItem(longSku).field).toBe('sku');
      const longPlu = await refused(
        api.post(CATALOG_ENDPOINTS.products, { ...valid, plu_code: '12345678901' }),
      );
      expect(onlyItem(longPlu).field).toBe('plu_code');
    });

    it('refuses a duplicate SKU on sku, naming the owner', async () => {
      const err = await refused(
        api.post(CATALOG_ENDPOINTS.products, { ...valid, sku: 'water-500' }),
      );
      expect(onlyItem(err)).toMatchObject({ code: 'catalog.duplicate', field: 'sku' });
      expect(err.fieldDetail('sku')).toContain('Spring water');
    });

    it('refuses a duplicate PLU on plu_code, naming the owner', async () => {
      const err = await refused(
        api.post(CATALOG_ENDPOINTS.products, { ...valid, plu_code: '4011' }),
      );
      expect(onlyItem(err)).toMatchObject({ code: 'catalog.duplicate', field: 'plu_code' });
      expect(err.fieldDetail('plu_code')).toContain('APPLES-KG');
    });

    it('requires a variant parent of type variant_parent, on parent_id', async () => {
      const none = await refused(
        api.post(CATALOG_ENDPOINTS.products, { ...valid, type: 'variant' }),
      );
      expect(onlyItem(none).field).toBe('parent_id');
      const wrong = await refused(
        api.post(CATALOG_ENDPOINTS.products, { ...valid, type: 'variant', parent_id: 'PRO0001' }),
      );
      expect(onlyItem(wrong)).toMatchObject({
        code: 'kernel.validation_failed',
        field: 'parent_id',
      });
      const right = await firstValueFrom(
        api.post<Product>(CATALOG_ENDPOINTS.products, {
          ...valid,
          type: 'variant',
          parent_id: 'PRO0002',
        }),
      );
      expect(right.parent_id).toBe('PRO0002');
    });

    it('drops parent_id for a type other than variant', async () => {
      const made = await firstValueFrom(
        api.post<Product>(CATALOG_ENDPOINTS.products, { ...valid, parent_id: 'PRO0002' }),
      );
      expect(made.parent_id).toBeNull();
    });

    it('refuses a purchase or sales unit of another dimension on that field', async () => {
      const purchase = await refused(
        api.post(CATALOG_ENDPOINTS.products, { ...valid, purchase_unit_id: 'UNI0003' }),
      );
      expect(onlyItem(purchase)).toMatchObject({
        code: 'catalog.dimension_mismatch',
        field: 'purchase_unit_id',
      });
      const sales = await refused(
        api.post(CATALOG_ENDPOINTS.products, { ...valid, sales_unit_id: 'UNI0005' }),
      );
      expect(onlyItem(sales).field).toBe('sales_unit_id');
    });

    it('accepts any combination of weighable, price mode, track stock and tracking mode', async () => {
      let n = 0;
      for (const is_weighable of [true, false]) {
        for (const price_mode of ['per_unit', 'per_weight', 'open_price']) {
          for (const track_stock of [true, false]) {
            for (const tracking_mode of ['none', 'quantity', 'batch', 'serial']) {
              n += 1;
              const made = await firstValueFrom(
                api.post<Product>(CATALOG_ENDPOINTS.products, {
                  ...valid,
                  sku: `C-${n}`,
                  is_weighable,
                  price_mode,
                  track_stock,
                  tracking_mode,
                }),
              );
              expect(made).toMatchObject({ is_weighable, price_mode, track_stock, tracking_mode });
            }
          }
        }
      }
      expect(n).toBe(48);
    });

    it('blocks deleting a variant parent while variants exist, naming one', async () => {
      const err = await refused(api.delete(CATALOG_ENDPOINTS.product('PRO0002')));
      expect(err.status).toBe(409);
      expect(onlyItem(err).code).toBe('catalog.in_use');
      expect(err.message).toContain('TEE-S');
    });

    it('blocks deleting a product a modifier links to, naming the modifier', async () => {
      const err = await refused(api.delete(CATALOG_ENDPOINTS.product('PRO0005')));
      expect(err.status).toBe(409);
      expect(err.message).toContain('Add apple');
    });

    it('soft-deletes a free product and then refuses to update it', async () => {
      const made = await firstValueFrom(api.post<Product>(CATALOG_ENDPOINTS.products, valid));
      await firstValueFrom(api.delete(CATALOG_ENDPOINTS.product(made.id)));
      const gone = await firstValueFrom(api.get<Product>(CATALOG_ENDPOINTS.product(made.id)));
      expect(gone.deleted_at).not.toBeNull();
      expect(
        (await refused(api.put(CATALOG_ENDPOINTS.product(made.id), { ...valid, version: 1 })))
          .status,
      ).toBe(404);
    });
  });

  describe('barcodes', () => {
    it('lists, creates, updates and deletes under a product', async () => {
      const list = await firstValueFrom(
        api.get<ProductBarcode[]>(CATALOG_ENDPOINTS.barcodes('PRO0001')),
      );
      expect(list).toEqual([
        expect.objectContaining({ barcode: '5012345678900', is_primary: true }),
      ]);
      const made = await firstValueFrom(
        api.post<ProductBarcode>(CATALOG_ENDPOINTS.barcodes('PRO0001'), {
          barcode: '111',
          unit_id: 'UNI0002',
          quantity: '24',
          is_primary: false,
        }),
      );
      expect(made).toMatchObject({ unit_code: 'CASE', quantity: '24.0000', is_primary: false });
      const updated = await firstValueFrom(
        api.put<ProductBarcode>(CATALOG_ENDPOINTS.barcode('PRO0001', made.id), {
          unit_id: null,
          quantity: '2',
          is_primary: false,
        }),
      );
      expect(updated).toMatchObject({
        unit_id: null,
        unit_code: null,
        quantity: '2.0000',
        barcode: '111',
      });
      await firstValueFrom(api.delete(CATALOG_ENDPOINTS.barcode('PRO0001', made.id)));
      expect(
        await firstValueFrom(api.get<ProductBarcode[]>(CATALOG_ENDPOINTS.barcodes('PRO0001'))),
      ).toHaveLength(1);
    });

    it('defaults the quantity to 1', async () => {
      const made = await firstValueFrom(
        api.post<ProductBarcode>(CATALOG_ENDPOINTS.barcodes('PRO0001'), {
          barcode: '222',
          is_primary: false,
        }),
      );
      expect(made.quantity).toBe('1.0000');
    });

    it('makes the new primary the only primary', async () => {
      await firstValueFrom(
        api.post(CATALOG_ENDPOINTS.barcodes('PRO0001'), { barcode: '333', is_primary: true }),
      );
      const list = await firstValueFrom(
        api.get<ProductBarcode[]>(CATALOG_ENDPOINTS.barcodes('PRO0001')),
      );
      expect(list.filter((b) => b.is_primary).map((b) => b.barcode)).toEqual(['333']);
    });

    it('refuses a duplicate barcode on barcode, naming the owning product', async () => {
      const err = await refused(
        api.post(CATALOG_ENDPOINTS.barcodes('PRO0005'), {
          barcode: '5012345678900',
          is_primary: false,
        }),
      );
      expect(onlyItem(err)).toMatchObject({ code: 'catalog.duplicate', field: 'barcode' });
      expect(err.fieldDetail('barcode')).toContain('WATER-500');
    });

    it('refuses blank, over-long and non-positive input by field', async () => {
      expect(
        (
          await refused(api.post(CATALOG_ENDPOINTS.barcodes('PRO0001'), { barcode: ' ' }))
        ).fieldDetail('barcode'),
      ).toBeDefined();
      expect(
        (
          await refused(
            api.post(CATALOG_ENDPOINTS.barcodes('PRO0001'), { barcode: 'x'.repeat(65) }),
          )
        ).fieldDetail('barcode'),
      ).toBeDefined();
      expect(
        (
          await refused(
            api.post(CATALOG_ENDPOINTS.barcodes('PRO0001'), { barcode: '9', quantity: 0 }),
          )
        ).fieldDetail('quantity'),
      ).toBeDefined();
    });

    it('refuses a unit of another dimension and a changed barcode value', async () => {
      const dim = await refused(
        api.post(CATALOG_ENDPOINTS.barcodes('PRO0001'), { barcode: '444', unit_id: 'UNI0003' }),
      );
      expect(onlyItem(dim)).toMatchObject({ code: 'catalog.dimension_mismatch', field: 'unit_id' });
      const immutable = await refused(
        api.put(CATALOG_ENDPOINTS.barcode('PRO0001', 'BCD0001'), {
          barcode: 'changed',
          quantity: 1,
        }),
      );
      expect(onlyItem(immutable)).toMatchObject({ code: 'catalog.immutable', field: 'barcode' });
    });

    it('answers 404 for a barcode that belongs to another product', async () => {
      expect((await refused(api.get(CATALOG_ENDPOINTS.barcode('PRO0005', 'BCD0001')))).status).toBe(
        404,
      );
    });
  });

  describe('modifier groups and modifiers', () => {
    it('lists, creates, updates and deletes a group; max_select empty means unlimited', async () => {
      const made = await firstValueFrom(
        api.post<ModifierGroup>(CATALOG_ENDPOINTS.modifierGroups, {
          name: 'Sauces',
          min_select: 0,
          max_select: null,
        }),
      );
      expect(made).toMatchObject({ min_select: 0, max_select: null });
      const updated = await firstValueFrom(
        api.put<ModifierGroup>(CATALOG_ENDPOINTS.modifierGroup(made.id), {
          name: 'Sauces',
          min_select: 1,
          max_select: 2,
        }),
      );
      expect(updated).toMatchObject({ min_select: 1, max_select: 2 });
      await firstValueFrom(api.delete(CATALOG_ENDPOINTS.modifierGroup(made.id)));
      const rows = await firstValueFrom(api.get<ModifierGroup[]>(CATALOG_ENDPOINTS.modifierGroups));
      expect(rows.map((g) => g.name)).toEqual(['Extras']);
    });

    it('defaults min_select to 0', async () => {
      const made = await firstValueFrom(
        api.post<ModifierGroup>(CATALOG_ENDPOINTS.modifierGroups, { name: 'G' }),
      );
      expect(made).toMatchObject({ min_select: 0, max_select: null });
    });

    it('refuses min_select greater than max_select on max_select', async () => {
      const err = await refused(
        api.post(CATALOG_ENDPOINTS.modifierGroups, { name: 'G', min_select: 3, max_select: 2 }),
      );
      expect(onlyItem(err)).toMatchObject({
        code: 'kernel.validation_failed',
        field: 'max_select',
      });
    });

    it('refuses a blank group name and a negative or fractional minimum', async () => {
      expect(
        (await refused(api.post(CATALOG_ENDPOINTS.modifierGroups, { name: '' }))).fieldDetail(
          'name',
        ),
      ).toBeDefined();
      expect(
        (
          await refused(api.post(CATALOG_ENDPOINTS.modifierGroups, { name: 'G', min_select: -1 }))
        ).fieldDetail('min_select'),
      ).toBeDefined();
      expect(
        (
          await refused(api.post(CATALOG_ENDPOINTS.modifierGroups, { name: 'G', min_select: 1.5 }))
        ).fieldDetail('min_select'),
      ).toBeDefined();
    });

    it('blocks deleting a group attached to a live product, naming it', async () => {
      const err = await refused(api.delete(CATALOG_ENDPOINTS.modifierGroup('GRP0001')));
      expect(err.status).toBe(409);
      expect(err.message).toContain('WATER-500');
    });

    it('lists a group modifiers with the linked product name, quantity and unit code', async () => {
      const rows = await firstValueFrom(
        api.get<Modifier[]>(CATALOG_ENDPOINTS.modifiers('GRP0001')),
      );
      expect(rows.map((m) => m.name)).toEqual(['Extra shot', 'Add apple']);
      expect(rows[1]).toMatchObject({
        linked_product_name: 'Apples',
        linked_quantity: '0.1500',
        linked_unit_code: 'KG',
      });
      expect(rows[0].linked_product_id).toBeNull();
    });

    it('creates a modifier with default deltas of 0, then updates and deletes it', async () => {
      const made = await firstValueFrom(
        api.post<Modifier>(CATALOG_ENDPOINTS.modifiers('GRP0001'), { name: 'Plain' }),
      );
      expect(made).toMatchObject({
        price_delta: '0.0000',
        cost_delta: '0.0000',
        linked_product_id: null,
      });
      const linked = await firstValueFrom(
        api.put<Modifier>(CATALOG_ENDPOINTS.modifier('GRP0001', made.id), {
          name: 'Plain',
          price_delta: '1',
          cost_delta: '0',
          linked_product_id: 'PRO0005',
          linked_quantity: '2',
          linked_unit_id: 'UNI0004',
        }),
      );
      expect(linked).toMatchObject({ linked_unit_code: 'G', linked_quantity: '2.0000' });
      const cleared = await firstValueFrom(
        api.put<Modifier>(CATALOG_ENDPOINTS.modifier('GRP0001', made.id), {
          name: 'Plain',
          price_delta: '1',
          cost_delta: '0',
        }),
      );
      expect(cleared).toMatchObject({
        linked_product_id: null,
        linked_quantity: null,
        linked_unit_id: null,
      });
      await firstValueFrom(api.delete(CATALOG_ENDPOINTS.modifier('GRP0001', made.id)));
    });

    it('refuses a blank name, and a partial linked trio', async () => {
      expect(
        (await refused(api.post(CATALOG_ENDPOINTS.modifiers('GRP0001'), { name: '' }))).fieldDetail(
          'name',
        ),
      ).toBeDefined();
      const partial = await refused(
        api.post(CATALOG_ENDPOINTS.modifiers('GRP0001'), {
          name: 'X',
          linked_product_id: 'PRO0005',
        }),
      );
      expect(partial.fieldDetail('linked_product_id')).toContain('together');
    });

    it('refuses a linked unit of another dimension on linked_unit_id', async () => {
      const err = await refused(
        api.post(CATALOG_ENDPOINTS.modifiers('GRP0001'), {
          name: 'X',
          linked_product_id: 'PRO0005',
          linked_quantity: '1',
          linked_unit_id: 'UNI0001',
        }),
      );
      expect(onlyItem(err)).toMatchObject({
        code: 'catalog.dimension_mismatch',
        field: 'linked_unit_id',
      });
    });
  });

  describe('routing', () => {
    it('answers 404 for an unknown catalog path, and for a wrong verb', async () => {
      expect((await refused(api.get('catalog/nonsense'))).status).toBe(404);
      expect((await refused(api.put(CATALOG_ENDPOINTS.unitConversion('CNV0001'), {}))).status).toBe(
        404,
      );
    });

    it('passes a non-catalog path on to the auth mock', async () => {
      const err = await refused(api.get('other/thing'));
      expect(err.status).toBe(404);
      expect(err.requestId === null || !err.requestId.includes(MOCK_CATALOG_SENTINEL)).toBe(true);
    });

    it('is reset to the seed by resetMockCatalog()', async () => {
      await firstValueFrom(api.post(CATALOG_ENDPOINTS.brands, { code: 'TMP', name: 'Temp' }));
      resetMockCatalog();
      const rows = await firstValueFrom(api.get<Brand[]>(CATALOG_ENDPOINTS.brands));
      expect(rows.map((b) => b.code)).toEqual(['ACME', 'GLOBEX']);
    });
  });
});

describe('the catalog mock permissions (T-036 AC-26, second half)', () => {
  beforeEach(() => resetMockCatalog());

  it('answers 401 kernel.unauthenticated with no bearer', async () => {
    const api = configure(null);
    const err = await refused(api.get(CATALOG_ENDPOINTS.products));
    expect(err.status).toBe(401);
    expect(onlyItem(err).code).toBe('kernel.unauthenticated');
  });

  it('answers 403 kernel.forbidden to a platform token, which holds no company', async () => {
    const api = configure('mock-platform-access');
    const err = await refused(api.get(CATALOG_ENDPOINTS.products));
    expect(err.status).toBe(403);
  });

  it('lets the limited user read products but refuses brands, barcodes and every write', async () => {
    const api = configure(LIMITED_BEARER);
    const rows = await firstValueFrom(api.get<Product[]>(CATALOG_ENDPOINTS.products));
    expect(rows.length).toBeGreaterThan(0);
    const brands = await refused(api.get(CATALOG_ENDPOINTS.brands));
    expect(brands.status).toBe(403);
    expect(onlyItem(brands).code).toBe('kernel.forbidden');
    expect((await refused(api.get(CATALOG_ENDPOINTS.barcodes('PRO0001')))).status).toBe(403);
    expect((await refused(api.post(CATALOG_ENDPOINTS.products, {}))).status).toBe(403);
    expect((await refused(api.delete(CATALOG_ENDPOINTS.product('PRO0001')))).status).toBe(403);
    expect((await refused(api.get(CATALOG_ENDPOINTS.unitConversions))).status).toBe(403);
    expect((await refused(api.get(CATALOG_ENDPOINTS.modifierGroups))).status).toBe(403);
  });

  it('gives the demo user every area', async () => {
    const api = configure(DEMO_BEARER);
    for (const path of [
      CATALOG_ENDPOINTS.categories,
      CATALOG_ENDPOINTS.brands,
      CATALOG_ENDPOINTS.units,
      CATALOG_ENDPOINTS.unitConversions,
      CATALOG_ENDPOINTS.products,
      CATALOG_ENDPOINTS.barcodes('PRO0001'),
      CATALOG_ENDPOINTS.modifierGroups,
    ]) {
      expect(await firstValueFrom(api.get<unknown[]>(path)), path).toBeInstanceOf(Array);
    }
  });

  it('works end to end after a real sign-in, for both users', async () => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(
          withInterceptors([authInterceptor, mockCatalogInterceptor, mockBackendInterceptor]),
        ),
      ],
    });
    const auth = TestBed.inject(AuthService);
    const api = TestBed.inject(ApiClient);

    await firstValueFrom(auth.login(MOCK_LIMITED_USER.email, MOCK_PASSWORD));
    await firstValueFrom(auth.selectTenant('abc-pharmacy'));
    expect((await refused(api.get(CATALOG_ENDPOINTS.brands))).status).toBe(403);
    expect(await firstValueFrom(api.get<Product[]>(CATALOG_ENDPOINTS.products))).toBeInstanceOf(
      Array,
    );
    expect(auth.isAuthenticated()).toBe(true);

    await firstValueFrom(auth.login(MOCK_USER.email, MOCK_PASSWORD));
    await firstValueFrom(auth.selectTenant('abc-pharmacy'));
    expect(await firstValueFrom(api.get<Brand[]>(CATALOG_ENDPOINTS.brands))).toHaveLength(2);
  });
});
