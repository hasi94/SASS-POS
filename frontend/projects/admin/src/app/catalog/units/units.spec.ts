import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  TestRequest,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, Routes, provideRouter, withComponentInputBinding } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { CATALOG_PERMISSIONS, Unit } from '@flexipos/shared';
import { envelope, pageEnvelope, refusal, seedSession } from '../../crud/testing/crud-testing';

const API = '/api/v1/catalog/units';
const CONVERSIONS = '/api/v1/catalog/unit-conversions';
const P = CATALOG_PERMISSIONS.units;

const EA: Unit = {
  id: 'UNI1',
  code: 'EA',
  name: 'Each',
  dimension: 'count',
  precision: 0,
  base_unit_id: null,
  base_unit_code: null,
  factor: null,
  is_active: true,
};
const CASE: Unit = {
  ...EA,
  id: 'UNI2',
  code: 'CASE',
  name: 'Case',
  base_unit_id: 'UNI1',
  base_unit_code: 'EA',
  factor: '24.0000',
};
const KG: Unit = {
  ...EA,
  id: 'UNI3',
  code: 'KG',
  name: 'Kilogram',
  dimension: 'mass',
  precision: 3,
};
const UNITS = [EA, CASE, KG];

const testRoutes: Routes = [
  { path: 'catalog', loadChildren: () => import('../catalog.routes') },
  {
    path: 'forbidden',
    loadComponent: () => import('../../shell/forbidden-page').then((m) => m.ForbiddenPage),
  },
];

describe('units screens (T-036 AC-7, AC-20, AC-34 to AC-36)', () => {
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
  const unitRequests = () => http.match((r) => r.method === 'GET' && r.url === API);
  const one = (r: TestRequest[]): TestRequest => {
    expect(r).toHaveLength(1);
    return r[0];
  };
  async function settle(): Promise<void> {
    await harness.fixture.whenStable();
    harness.detectChanges();
  }
  /** Opens the list and answers both of its requests (units, then conversions). */
  async function openList(permissions: readonly string[]) {
    await open('/catalog/units', permissions);
    one(unitRequests()).flush(pageEnvelope(UNITS));
    http.expectOne((r) => r.url === CONVERSIONS).flush(pageEnvelope([]));
    await settle();
  }
  async function openCreate() {
    await open('/catalog/units/new', [P.view, P.manage]);
    one(unitRequests()).flush(pageEnvelope(UNITS));
    await settle();
  }
  function type(selector: string, value: string): void {
    const input = el().querySelector(selector) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }
  function choose(selector: string, value: string): void {
    const select = el().querySelector(selector) as HTMLSelectElement;
    select.value = value;
    select.dispatchEvent(new Event('change'));
    harness.detectChanges();
  }
  function submit(): void {
    (el().querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit'));
    harness.detectChanges();
  }
  const baseOptions = () =>
    Array.from((el().querySelector('adm-entity-picker select') as HTMLSelectElement).options).map(
      (o) => o.textContent?.trim() ?? '',
    );
  const dialogButton = (label: string) =>
    Array.from(el().querySelectorAll('[role="dialog"] button')).find(
      (b) => b.textContent?.trim() === label,
    ) as HTMLButtonElement;

  afterEach(() => http.verify());

  describe('list', () => {
    it('shows code, name, dimension, precision, base unit and factor', async () => {
      await openList([P.view]);
      const caseRow = Array.from(el().querySelectorAll('tbody tr')).find((r) =>
        r.textContent?.includes('CASE'),
      );
      expect(caseRow?.textContent).toContain('Case');
      expect(caseRow?.textContent).toContain('count');
      expect(caseRow?.textContent).toContain('EA');
      expect(caseRow?.textContent).toContain('24.0000');
    });

    it('has no Create, Edit or Delete with view only, and forbids the form routes', async () => {
      await openList([P.view]);
      expect(text()).not.toContain('New unit');
      expect(text()).not.toContain('New conversion');
      expect(text()).not.toContain('Edit');
      expect(text()).not.toContain('Delete');
      await harness.navigateByUrl('/catalog/units/new');
      expect(router.url).toBe('/forbidden');
      await harness.navigateByUrl('/catalog/units/UNI1/edit');
      expect(router.url).toBe('/forbidden');
      http.expectNone(() => true);
    });

    it('deletes after confirmation: one DELETE, then a fresh list', async () => {
      await openList([P.view, P.manage]);
      (el().querySelector('button[aria-label="Delete KG"]') as HTMLButtonElement).click();
      harness.detectChanges();
      expect(text()).toContain('Delete KG?');
      dialogButton('Delete').click();
      dialogButton('Delete')?.click();
      const del = http.expectOne(`${API}/UNI3`);
      expect(del.request.method).toBe('DELETE');
      del.flush(envelope(null));
      one(unitRequests()).flush(pageEnvelope([EA, CASE]));
      harness.detectChanges();
      expect(el().querySelectorAll('tbody tr')).toHaveLength(2);
    });

    it('keeps the row and shows the server message when a delete is refused', async () => {
      await openList([P.view, P.manage]);
      (el().querySelector('button[aria-label="Delete EA"]') as HTMLButtonElement).click();
      harness.detectChanges();
      dialogButton('Delete').click();
      http
        .expectOne(`${API}/UNI1`)
        .flush(
          ...refusal(
            409,
            'catalog.in_use',
            undefined,
            undefined,
            'Unit EA cannot be deleted: the unit CASE is based on it.',
          ),
        );
      harness.detectChanges();
      expect(text()).toContain('the unit CASE is based on it');
      expect(el().querySelectorAll('tbody tr')).toHaveLength(3);
      http.expectNone(() => true);
    });

    it('shows the permission message on a 403 (AC-8)', async () => {
      await open('/catalog/units', [P.view]);
      one(unitRequests()).flush(...refusal(403, 'kernel.forbidden'));
      http.expectOne((r) => r.url === CONVERSIONS).flush(pageEnvelope([]));
      await settle();
      expect(text()).toContain('You do not have permission to see or change this.');
    });
  });

  describe('form', () => {
    it('defaults precision to 4 and sends a plain unit without a base unit', async () => {
      await openCreate();
      expect((el().querySelector('input[type="number"]') as HTMLInputElement).value).toBe('4');
      type('input[formcontrolname="code"]', 'BOX');
      type('input[formcontrolname="name"]', 'Box');
      submit();
      const req = http.expectOne(API);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({
        code: 'BOX',
        name: 'Box',
        dimension: 'count',
        precision: 4,
        base_unit_id: null,
        factor: null,
      });
      req.flush(envelope(EA));
      await settle();
      expect(router.url).toBe('/catalog/units');
      one(unitRequests()).flush(pageEnvelope(UNITS));
      http.expectOne((r) => r.url === CONVERSIONS).flush(pageEnvelope([]));
    });

    it('blocks a blank code and name, naming each, with nothing sent', async () => {
      await openCreate();
      type('input[type="number"]', '4');
      submit();
      expect(text()).toContain('Code is required.');
      expect(text()).toContain('Name is required.');
      http.expectNone(() => true);
    });

    it.each(['5', '-1', '1.5', ''])(
      'blocks a precision of %j on the precision field',
      async (value) => {
        await openCreate();
        type('input[formcontrolname="code"]', 'BOX');
        type('input[formcontrolname="name"]', 'Box');
        type('input[type="number"]', value);
        submit();
        const field = el().querySelector('input[type="number"]');
        expect(field?.getAttribute('aria-invalid')).toBe('true');
        const id = field?.getAttribute('aria-describedby');
        expect(el().querySelector(`#${id}`)?.textContent).toContain(
          'Precision must be a whole number from 0 to 4.',
        );
        http.expectNone(() => true);
      },
    );

    it.each([0, 4])('accepts the precision limit %d', async (value) => {
      await openCreate();
      type('input[formcontrolname="code"]', 'BOX');
      type('input[formcontrolname="name"]', 'Box');
      type('input[type="number"]', String(value));
      submit();
      const req = http.expectOne(API);
      expect((req.request.body as { precision: number }).precision).toBe(value);
      req.flush(envelope(EA));
      await settle();
      one(unitRequests()).flush(pageEnvelope(UNITS));
      http.expectOne((r) => r.url === CONVERSIONS).flush(pageEnvelope([]));
    });

    it('blocks a base unit without a factor with the "go together" message', async () => {
      await openCreate();
      type('input[formcontrolname="code"]', 'BOX');
      type('input[formcontrolname="name"]', 'Box');
      choose('adm-entity-picker select', 'UNI1');
      submit();
      expect(text()).toContain('Base unit and factor go together.');
      http.expectNone(() => true);
    });

    it('blocks a factor without a base unit with the "go together" message', async () => {
      await openCreate();
      type('input[formcontrolname="code"]', 'BOX');
      type('input[formcontrolname="name"]', 'Box');
      type('input[formcontrolname="factor"]', '12');
      submit();
      expect(text()).toContain('Base unit and factor go together.');
      http.expectNone(() => true);
    });

    it.each(['0', '-2', 'abc', '0.0'])('blocks a factor of %j', async (factor) => {
      await openCreate();
      type('input[formcontrolname="code"]', 'BOX');
      type('input[formcontrolname="name"]', 'Box');
      choose('adm-entity-picker select', 'UNI1');
      type('input[formcontrolname="factor"]', factor);
      submit();
      expect(text()).toContain('Factor must be greater than 0.');
      http.expectNone(() => true);
    });

    it('sends a base unit with its factor as a decimal string', async () => {
      await openCreate();
      type('input[formcontrolname="code"]', 'PACK');
      type('input[formcontrolname="name"]', 'Pack');
      choose('adm-entity-picker select', 'UNI1');
      type('input[formcontrolname="factor"]', ' 6 ');
      submit();
      const req = http.expectOne(API);
      expect(req.request.body).toMatchObject({ base_unit_id: 'UNI1', factor: '6' });
      req.flush(envelope(EA));
      await settle();
      one(unitRequests()).flush(pageEnvelope(UNITS));
      http.expectOne((r) => r.url === CONVERSIONS).flush(pageEnvelope([]));
    });

    it('offers only base units of the chosen dimension', async () => {
      await openCreate();
      expect(baseOptions()).toContain('EA (Each)');
      expect(baseOptions()).not.toContain('KG (Kilogram)');
      choose('select[formcontrolname="dimension"]', 'mass');
      expect(baseOptions()).toContain('KG (Kilogram)');
      expect(baseOptions()).not.toContain('EA (Each)');
    });

    it('sends a cross-dimension base unit and shows the server refusal on the base unit field', async () => {
      await openCreate();
      type('input[formcontrolname="code"]', 'MIX');
      type('input[formcontrolname="name"]', 'Mix');
      choose('adm-entity-picker select', 'UNI1');
      type('input[formcontrolname="factor"]', '2');
      // The picker hides EA once the dimension is mass, but the chosen value stays and is sent.
      choose('select[formcontrolname="dimension"]', 'mass');
      submit();
      const req = http.expectOne(API);
      expect(req.request.body).toMatchObject({ dimension: 'mass', base_unit_id: 'UNI1' });
      req.flush(
        ...refusal(422, 'catalog.dimension_mismatch', 'base_unit_id', 'EA is count, not mass.'),
      );
      harness.detectChanges();
      const select = el().querySelector('adm-entity-picker select');
      const id = select?.getAttribute('aria-describedby');
      expect(id).toBeTruthy();
      expect(el().querySelector(`#${id}`)?.textContent).toContain('EA is count, not mass.');
      expect((el().querySelector('input[formcontrolname="code"]') as HTMLInputElement).value).toBe(
        'MIX',
      );
    });

    it('shows a duplicate code refusal on the code field', async () => {
      await openCreate();
      type('input[formcontrolname="code"]', 'EA');
      type('input[formcontrolname="name"]', 'Each again');
      submit();
      http
        .expectOne(API)
        .flush(
          ...refusal(422, 'catalog.duplicate', 'code', 'Code EA is already used by unit Each.'),
        );
      harness.detectChanges();
      const id = el()
        .querySelector('input[formcontrolname="code"]')
        ?.getAttribute('aria-describedby');
      expect(el().querySelector(`#${id}`)?.textContent).toContain('already used by unit Each');
    });

    it('on edit shows the dimension read-only and never sends it', async () => {
      await open('/catalog/units/UNI2/edit', [P.view, P.manage]);
      http.expectOne(`${API}/UNI2`).flush(envelope(CASE));
      await settle();
      one(unitRequests()).flush(pageEnvelope(UNITS));
      await settle();
      expect(el().querySelector('select[formcontrolname="dimension"]')).toBeNull();
      expect(text()).toContain('cannot be changed');
      expect(
        (el().querySelector('input[formcontrolname="factor"]') as HTMLInputElement).value,
      ).toBe('24.0000');
      type('input[formcontrolname="name"]', 'Big case');
      submit();
      const put = http.expectOne(`${API}/UNI2`);
      expect(put.request.method).toBe('PUT');
      expect(Object.keys(put.request.body as object)).not.toContain('dimension');
      expect(put.request.body).toEqual({
        code: 'CASE',
        name: 'Big case',
        precision: 0,
        base_unit_id: 'UNI1',
        factor: '24.0000',
      });
      put.flush(envelope(CASE));
      await settle();
      one(unitRequests()).flush(pageEnvelope(UNITS));
      http.expectOne((r) => r.url === CONVERSIONS).flush(pageEnvelope([]));
    });

    it('does not offer the unit itself as its own base unit', async () => {
      await open('/catalog/units/UNI2/edit', [P.view, P.manage]);
      http.expectOne(`${API}/UNI2`).flush(envelope(CASE));
      await settle();
      one(unitRequests()).flush(pageEnvelope(UNITS));
      await settle();
      expect(baseOptions()).toContain('EA (Each)');
      expect(baseOptions().some((l) => l.startsWith('CASE'))).toBe(false);
    });

    it('shows the permission message when saving is refused with 403 (AC-8)', async () => {
      await openCreate();
      type('input[formcontrolname="code"]', 'BOX');
      type('input[formcontrolname="name"]', 'Box');
      submit();
      http.expectOne(API).flush(...refusal(403, 'kernel.forbidden'));
      harness.detectChanges();
      expect(text()).toContain('You do not have permission to see or change this.');
    });
  });
});
