import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Observable, firstValueFrom } from 'rxjs';
import { CatalogApi } from './catalog-api';
import { envelope, pageEnvelope } from '../crud/testing/crud-testing';

describe('CatalogApi (T-036 plan 1.7)', () => {
  let api: CatalogApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CatalogApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('lists with page, per_page, search and filters, and returns data with pagination', async () => {
    const result = firstValueFrom(
      api.products.list({ page: 2, per_page: 25, search: 'tee', type: 'variant' }),
    );
    const req = http.expectOne((r) => r.url === '/api/v1/catalog/products');
    expect(req.request.method).toBe('GET');
    expect(req.request.params.get('page')).toBe('2');
    expect(req.request.params.get('per_page')).toBe('25');
    expect(req.request.params.get('search')).toBe('tee');
    expect(req.request.params.get('type')).toBe('variant');
    req.flush(pageEnvelope([{ id: 'P1' }], { page: 2, total: 30 }));
    const got = await result;
    expect(got.data).toEqual([{ id: 'P1' }]);
    expect(got.meta.pagination).toEqual({ page: 2, per_page: 25, total: 30 });
  });

  it('gets one row by id, URL-encoding the id', async () => {
    const result = firstValueFrom(api.categories.get('a b'));
    const req = http.expectOne('/api/v1/catalog/categories/a%20b');
    expect(req.request.method).toBe('GET');
    req.flush(envelope({ id: 'a b' }));
    expect(await result).toEqual({ id: 'a b' });
  });

  it('creates with POST and the body unchanged', async () => {
    const body = { code: 'X', name: 'X', parent_id: null };
    const result = firstValueFrom(api.categories.create(body));
    const req = http.expectOne('/api/v1/catalog/categories');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual(body);
    req.flush(envelope({ id: 'C9' }));
    expect(await result).toEqual({ id: 'C9' });
  });

  it('updates with PUT, and removes with DELETE answering null', async () => {
    const updated = firstValueFrom(api.brands.update('B1', { code: 'A', name: 'B' }));
    const put = http.expectOne('/api/v1/catalog/brands/B1');
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual({ code: 'A', name: 'B' });
    put.flush(envelope({ id: 'B1' }));
    await updated;

    const removed = firstValueFrom(api.brands.remove('B1'));
    const del = http.expectOne('/api/v1/catalog/brands/B1');
    expect(del.request.method).toBe('DELETE');
    del.flush(envelope(null));
    expect(await removed).toBeNull();
  });

  it.each<[string, () => Observable<unknown>, string]>([
    ['units', () => api.units.list({ page: 1, per_page: 25 }), 'catalog/units'],
    [
      'unit conversions',
      () => api.unitConversions.list({ page: 1, per_page: 25 }),
      'catalog/unit-conversions',
    ],
    [
      'modifier groups',
      () => api.modifierGroups.list({ page: 1, per_page: 25 }),
      'catalog/modifier-groups',
    ],
  ])('uses the %s path', (_name, call, path) => {
    call().subscribe();
    http.expectOne((r) => r.url === `/api/v1/${path}`).flush(pageEnvelope([]));
  });

  it('filters unit conversions by product', () => {
    api.unitConversions.list({ page: 1, per_page: 25, product_id: 'P1' }).subscribe();
    const req = http.expectOne((r) => r.url === '/api/v1/catalog/unit-conversions');
    expect(req.request.params.get('product_id')).toBe('P1');
    req.flush(pageEnvelope([]));
  });

  it('drops an undefined param instead of sending the word undefined', () => {
    api.products.list({ page: 1, per_page: 25, search: undefined }).subscribe();
    const req = http.expectOne((r) => r.url === '/api/v1/catalog/products');
    expect(req.request.params.has('search')).toBe(false);
    req.flush(pageEnvelope([]));
  });

  it('nests barcodes under their product, for every verb', () => {
    const barcodes = api.barcodes('P1');
    barcodes.list({ page: 1, per_page: 25 }).subscribe();
    http.expectOne((r) => r.url === '/api/v1/catalog/products/P1/barcodes').flush(pageEnvelope([]));

    barcodes
      .create({ barcode: '123', unit_id: null, quantity: '1', is_primary: false })
      .subscribe();
    const post = http.expectOne('/api/v1/catalog/products/P1/barcodes');
    expect(post.request.method).toBe('POST');
    expect(post.request.body).toEqual({
      barcode: '123',
      unit_id: null,
      quantity: '1',
      is_primary: false,
    });
    post.flush(envelope({}));

    barcodes.update('B7', { unit_id: null, quantity: '2', is_primary: true }).subscribe();
    const put = http.expectOne('/api/v1/catalog/products/P1/barcodes/B7');
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual({ unit_id: null, quantity: '2', is_primary: true });
    put.flush(envelope({}));

    barcodes.remove('B7').subscribe();
    const del = http.expectOne('/api/v1/catalog/products/P1/barcodes/B7');
    expect(del.request.method).toBe('DELETE');
    del.flush(envelope(null));
  });

  it('nests modifiers under their group, for every verb', () => {
    const modifiers = api.modifiers('G1');
    modifiers.list({ page: 1, per_page: 25 }).subscribe();
    http
      .expectOne((r) => r.url === '/api/v1/catalog/modifier-groups/G1/modifiers')
      .flush(pageEnvelope([]));

    modifiers.create({ name: 'Cheese', price_delta: '1', cost_delta: '0' }).subscribe();
    const post = http.expectOne('/api/v1/catalog/modifier-groups/G1/modifiers');
    expect(post.request.method).toBe('POST');
    expect(post.request.body).toEqual({ name: 'Cheese', price_delta: '1', cost_delta: '0' });
    post.flush(envelope({}));

    modifiers.update('M2', { name: 'Cheese', price_delta: '1', cost_delta: '0' }).subscribe();
    expect(http.expectOne('/api/v1/catalog/modifier-groups/G1/modifiers/M2').request.method).toBe(
      'PUT',
    );
    http.match(() => true).forEach((r) => r.flush(envelope({})));

    modifiers.remove('M2').subscribe();
    const del = http.expectOne('/api/v1/catalog/modifier-groups/G1/modifiers/M2');
    expect(del.request.method).toBe('DELETE');
    del.flush(envelope(null));
  });

  it('puts a product update under its id with the version in the body', () => {
    api.products
      .update('P1', {
        type: 'simple',
        sku: 'S',
        name: 'N',
        category_id: 'C1',
        brand_id: null,
        purchase_unit_id: null,
        sales_unit_id: null,
        is_weighable: false,
        price_mode: 'per_unit',
        track_stock: true,
        tracking_mode: 'none',
        version: 4,
      })
      .subscribe();
    const req = http.expectOne('/api/v1/catalog/products/P1');
    expect(req.request.method).toBe('PUT');
    expect(req.request.body.version).toBe(4);
    expect('stock_unit_id' in req.request.body).toBe(false);
    req.flush(envelope({}));
  });

  it('gives unit conversions no update call, in the type and at run time', () => {
    expect('update' in api.unitConversions).toBe(false);
    // @ts-expect-error conversions cannot be updated
    void api.unitConversions.update;
  });
});
