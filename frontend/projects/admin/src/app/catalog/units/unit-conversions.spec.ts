import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  TestRequest,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, Routes, provideRouter, withComponentInputBinding } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { CATALOG_PERMISSIONS, Unit, UnitConversion } from '@flexipos/shared';
import { envelope, pageEnvelope, refusal, seedSession } from '../../crud/testing/crud-testing';

const UNITS_URL = '/api/v1/catalog/units';
const API = '/api/v1/catalog/unit-conversions';
const PRODUCTS_URL = '/api/v1/catalog/products';
const P = CATALOG_PERMISSIONS.units;

const unit = (id: string, code: string, name: string): Unit => ({
  id,
  code,
  name,
  dimension: 'count',
  precision: 0,
  base_unit_id: null,
  base_unit_code: null,
  factor: null,
  is_active: true,
});
const UNITS = [unit('UNI1', 'EA', 'Each'), unit('UNI2', 'CASE', 'Case')];

const GLOBAL: UnitConversion = {
  id: 'CNV1',
  product_id: null,
  product_name: null,
  from_unit_id: 'UNI2',
  from_unit_code: 'CASE',
  to_unit_id: 'UNI1',
  to_unit_code: 'EA',
  factor: '24.0000',
};
const SCOPED: UnitConversion = {
  ...GLOBAL,
  id: 'CNV2',
  product_id: 'PRD1',
  product_name: 'Cola 330ml',
  factor: '12.0000',
};

const testRoutes: Routes = [
  { path: 'catalog', loadChildren: () => import('../catalog.routes') },
  {
    path: 'forbidden',
    loadComponent: () => import('../../shell/forbidden-page').then((m) => m.ForbiddenPage),
  },
];

describe('unit conversions (T-036 AC-7, AC-20, AC-37)', () => {
  let http: HttpTestingController;
  let router: Router;
  let harness: RouterTestingHarness;

  async function open(url: string, permissions: readonly string[]): Promise<void> {
    sessionStorage.clear();
    seedSession(permissions);
    TestBed.configureTestingModule({
      providers: [
        provideRouter(testRoutes, withComponentInputBinding()),
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });
    http = TestBed.inject(HttpTestingController);
    router = TestBed.inject(Router);
    harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(url);
  }

  const el = () => harness.routeNativeElement as HTMLElement;
  const text = () => el().textContent ?? '';
  const get = (url: string): TestRequest[] =>
    http.match((r) => r.method === 'GET' && r.url === url);
  async function settle(): Promise<void> {
    await harness.fixture.whenStable();
    harness.detectChanges();
  }
  async function openList(permissions: readonly string[], rows = [GLOBAL, SCOPED]) {
    await open('/catalog/units', permissions);
    get(UNITS_URL).forEach((r) => r.flush(pageEnvelope(UNITS)));
    get(API).forEach((r) => r.flush(pageEnvelope(rows)));
    await settle();
  }
  async function openCreate() {
    await open('/catalog/units/conversions/new', [P.view, P.manage]);
    const unitRequests = get(UNITS_URL);
    expect(unitRequests).toHaveLength(2);
    unitRequests.forEach((r) => r.flush(pageEnvelope(UNITS)));
    const products = get(PRODUCTS_URL);
    expect(products).toHaveLength(1);
    products[0].flush(pageEnvelope([{ id: 'PRD1', sku: 'P-1', name: 'Cola' }]));
    await settle();
  }
  const pickerSelect = (n: number) => `adm-entity-picker:nth-of-type(${n}) select`;
  function choose(selector: string, value: string): void {
    const select = el().querySelector(selector) as HTMLSelectElement;
    select.value = value;
    select.dispatchEvent(new Event('change'));
    harness.detectChanges();
  }
  function type(selector: string, value: string): void {
    const input = el().querySelector(selector) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }
  function submit(): void {
    (el().querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit'));
    harness.detectChanges();
  }
  function fill(from = 'UNI2', to = 'UNI1', factor = '24'): void {
    choose(pickerSelect(1), from);
    choose(pickerSelect(2), to);
    type('input[formcontrolname="factor"]', factor);
  }
  const dialogButton = (label: string) =>
    Array.from(el().querySelectorAll('[role="dialog"] button')).find(
      (b) => b.textContent?.trim() === label,
    ) as HTMLButtonElement;
  const describedText = (selector: string): string => {
    const id = el().querySelector(selector)?.getAttribute('aria-describedby');
    return id ? (el().querySelector(`#${id}`)?.textContent ?? '') : '';
  };

  afterEach(() => http.verify());

  describe('section on the Units screen', () => {
    it('shows from, to, factor and product, with "All products" for a global row', async () => {
      await openList([P.view]);
      const table = Array.from(el().querySelectorAll('table')).find((t) =>
        t.querySelector('caption')?.textContent?.includes('Unit conversions'),
      ) as HTMLTableElement;
      const headers = Array.from(table.querySelectorAll('th')).map((h) => h.textContent?.trim());
      expect(headers).toEqual(expect.arrayContaining(['From', 'To', 'Factor', 'Product']));
      const rows = Array.from(table.querySelectorAll('tbody tr'));
      expect(rows[0].textContent).toContain('CASE');
      expect(rows[0].textContent).toContain('24.0000');
      expect(rows[0].textContent).toContain('All products');
      expect(rows[1].textContent).toContain('Cola 330ml');
      expect(rows[1].textContent).not.toContain('All products');
    });

    it('with view only has no New conversion or Delete, and the form route is forbidden', async () => {
      await openList([P.view]);
      expect(text()).not.toContain('New conversion');
      expect(el().querySelector('button[aria-label^="Delete CASE"]')).toBeNull();
      await harness.navigateByUrl('/catalog/units/conversions/new');
      expect(router.url).toBe('/forbidden');
      http.expectNone(() => true);
    });

    it('with manage offers New conversion and a Delete per row', async () => {
      await openList([P.view, P.manage]);
      expect(el().querySelector('a[href="/catalog/units/conversions/new"]')).not.toBeNull();
      expect(el().querySelectorAll('button[aria-label="Delete CASE to EA"]')).toHaveLength(2);
    });

    it('deletes a conversion after confirmation and reloads the section', async () => {
      await openList([P.view, P.manage]);
      (
        el().querySelectorAll('button[aria-label="Delete CASE to EA"]')[0] as HTMLButtonElement
      ).click();
      harness.detectChanges();
      expect(text()).toContain('Delete CASE to EA?');
      dialogButton('Delete').click();
      const del = http.expectOne(`${API}/CNV1`);
      expect(del.request.method).toBe('DELETE');
      del.flush(envelope(null));
      get(API)[0].flush(pageEnvelope([SCOPED]));
      harness.detectChanges();
      expect(
        Array.from(el().querySelectorAll('table'))[1].querySelectorAll('tbody tr'),
      ).toHaveLength(1);
    });

    it('shows a refused conversion delete and keeps the row', async () => {
      await openList([P.view, P.manage]);
      (
        el().querySelectorAll('button[aria-label="Delete CASE to EA"]')[1] as HTMLButtonElement
      ).click();
      harness.detectChanges();
      dialogButton('Delete').click();
      http
        .expectOne(`${API}/CNV2`)
        .flush(
          ...refusal(409, 'catalog.in_use', undefined, undefined, 'The conversion is in use.'),
        );
      harness.detectChanges();
      expect(text()).toContain('The conversion is in use.');
      expect(
        Array.from(el().querySelectorAll('table'))[1].querySelectorAll('tbody tr'),
      ).toHaveLength(2);
    });
  });

  describe('form', () => {
    it('blocks missing units and a missing factor, naming each, with nothing sent', async () => {
      await openCreate();
      submit();
      expect(text()).toContain('From unit is required.');
      expect(text()).toContain('To unit is required.');
      expect(text()).toContain('Factor is required.');
      http.expectNone(() => true);
    });

    it('blocks the same unit as from and to, on the to unit field', async () => {
      await openCreate();
      fill('UNI1', 'UNI1', '2');
      submit();
      expect(describedText(pickerSelect(2))).toContain('From and to units must be different.');
      http.expectNone(() => true);
    });

    it.each(['0', '-1', 'abc'])('blocks a factor of %j', async (factor) => {
      await openCreate();
      fill('UNI2', 'UNI1', factor);
      submit();
      expect(text()).toContain('Factor must be greater than 0.');
      http.expectNone(() => true);
    });

    it('sends a global conversion with product_id null, then returns to the Units screen', async () => {
      await openCreate();
      fill('UNI2', 'UNI1', '24');
      submit();
      const req = http.expectOne(API);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({
        from_unit_id: 'UNI2',
        to_unit_id: 'UNI1',
        factor: '24',
        product_id: null,
      });
      req.flush(envelope(GLOBAL));
      await settle();
      expect(router.url).toBe('/catalog/units');
      get(UNITS_URL).forEach((r) => r.flush(pageEnvelope(UNITS)));
      get(API).forEach((r) => r.flush(pageEnvelope([GLOBAL])));
    });

    it('sends the product when one is chosen', async () => {
      await openCreate();
      fill();
      choose(pickerSelect(3), 'PRD1');
      submit();
      const req = http.expectOne(API);
      expect((req.request.body as { product_id: string }).product_id).toBe('PRD1');
      req.flush(envelope(SCOPED));
      await settle();
      get(UNITS_URL).forEach((r) => r.flush(pageEnvelope(UNITS)));
      get(API).forEach((r) => r.flush(pageEnvelope([SCOPED])));
    });

    it.each([
      ['catalog.dimension_mismatch', 'CASE is count but KG is mass.'],
      ['catalog.duplicate', 'Conversion CNV1 already converts CASE to EA for all products.'],
      ['kernel.validation_failed', 'For SKU-1 the to unit must be its stock unit, EA.'],
    ])(
      'shows a %s refusal on the to unit field and keeps the typed factor',
      async (code, detail) => {
        await openCreate();
        fill('UNI2', 'UNI1', '24');
        submit();
        http.expectOne(API).flush(...refusal(422, code, 'to_unit_id', detail));
        harness.detectChanges();
        expect(describedText(pickerSelect(2))).toContain(detail);
        expect(el().querySelector(pickerSelect(2))?.getAttribute('aria-invalid')).toBe('true');
        expect(
          (el().querySelector('input[formcontrolname="factor"]') as HTMLInputElement).value,
        ).toBe('24');
      },
    );

    it('puts a refusal that names no known field in the summary', async () => {
      await openCreate();
      fill();
      submit();
      http.expectOne(API).flush(...refusal(422, 'catalog.duplicate', 'elsewhere', 'Clash.'));
      harness.detectChanges();
      expect(el().querySelector('.adm-form-summary')?.textContent).toContain('Clash.');
    });
  });
});
