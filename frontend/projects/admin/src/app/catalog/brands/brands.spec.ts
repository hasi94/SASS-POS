import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  TestRequest,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, Routes, provideRouter, withComponentInputBinding } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { Brand, CATALOG_PERMISSIONS } from '@flexipos/shared';
import { envelope, pageEnvelope, refusal, seedSession } from '../../crud/testing/crud-testing';

const API = '/api/v1/catalog/brands';
const P = CATALOG_PERMISSIONS.brands;

const ACME: Brand = { id: 'BRD1', code: 'ACME', name: 'Acme', is_active: true, deleted_at: null };
const ZED: Brand = { id: 'BRD2', code: 'ZED', name: 'Zed', is_active: true, deleted_at: null };

const testRoutes: Routes = [
  { path: 'catalog', loadChildren: () => import('../catalog.routes') },
  {
    path: 'forbidden',
    loadComponent: () => import('../../shell/forbidden-page').then((m) => m.ForbiddenPage),
  },
];

describe('brands screens (T-036 AC-7, AC-20, AC-33)', () => {
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
  const listRequests = () => http.match((r) => r.method === 'GET' && r.url === API);
  const one = (r: TestRequest[]): TestRequest => {
    expect(r).toHaveLength(1);
    return r[0];
  };
  async function settle(): Promise<void> {
    await harness.fixture.whenStable();
    harness.detectChanges();
  }
  async function openList(permissions: readonly string[]) {
    await open('/catalog/brands', permissions);
    one(listRequests()).flush(pageEnvelope([ACME, ZED]));
    await settle();
  }
  async function openCreate() {
    await open('/catalog/brands/new', [P.view, P.manage]);
    await settle();
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
  const dialogButton = (label: string) =>
    Array.from(el().querySelectorAll('[role="dialog"] button')).find(
      (b) => b.textContent?.trim() === label,
    ) as HTMLButtonElement;

  afterEach(() => http.verify());

  it('lists code and name', async () => {
    await openList([P.view]);
    const rows = Array.from(el().querySelectorAll('tbody tr'));
    expect(rows).toHaveLength(2);
    expect(rows[0].textContent).toContain('ACME');
    expect(rows[0].textContent).toContain('Acme');
  });

  it('has no Create, Edit or Delete in the DOM with view only, and forbids the form routes', async () => {
    await openList([P.view]);
    expect(text()).not.toContain('New brand');
    expect(text()).not.toContain('Edit');
    expect(text()).not.toContain('Delete');
    await harness.navigateByUrl('/catalog/brands/new');
    expect(router.url).toBe('/forbidden');
    await harness.navigateByUrl('/catalog/brands/BRD1/edit');
    expect(router.url).toBe('/forbidden');
    http.expectNone(() => true);
  });

  it('shows the permission message when the list is refused with 403 (AC-8)', async () => {
    await open('/catalog/brands', [P.view]);
    one(listRequests()).flush(...refusal(403, 'kernel.forbidden'));
    await settle();
    expect(text()).toContain('You do not have permission to see or change this.');
    expect(router.url).toBe('/catalog/brands');
  });

  it('blocks a blank code and a blank name, naming each, with nothing sent', async () => {
    await openCreate();
    submit();
    expect(text()).toContain('Code is required.');
    expect(text()).toContain('Name is required.');
    http.expectNone(() => true);
  });

  it('sends code and name, then returns to a fresh list', async () => {
    await openCreate();
    type('input[formcontrolname="code"]', 'NEW');
    type('input[formcontrolname="name"]', ' Newer ');
    submit();
    const req = http.expectOne(API);
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ code: 'NEW', name: 'Newer' });
    req.flush(envelope({ ...ACME, id: 'BRD9' }));
    await settle();
    expect(router.url).toBe('/catalog/brands');
    one(listRequests()).flush(pageEnvelope([ACME]));
  });

  it.each([
    ['code', 'Code ACME is already used by brand Acme.', 'input[formcontrolname="code"]'],
    ['name', 'Name Acme is already used by brand ACME.', 'input[formcontrolname="name"]'],
  ])(
    'shows a duplicate %s refusal on that field and keeps what was typed',
    async (field, detail, selector) => {
      await openCreate();
      type('input[formcontrolname="code"]', 'ACME');
      type('input[formcontrolname="name"]', 'Acme');
      submit();
      http.expectOne(API).flush(...refusal(422, 'catalog.duplicate', field, detail));
      harness.detectChanges();
      const control = el().querySelector(selector) as HTMLInputElement;
      const id = control.getAttribute('aria-describedby');
      expect(id).toBeTruthy();
      expect(el().querySelector(`#${id}`)?.textContent).toContain(detail);
      expect((el().querySelector('input[formcontrolname="code"]') as HTMLInputElement).value).toBe(
        'ACME',
      );
      expect((el().querySelector('input[formcontrolname="name"]') as HTMLInputElement).value).toBe(
        'Acme',
      );
    },
  );

  it('edits through PUT with the loaded values', async () => {
    await open('/catalog/brands/BRD1/edit', [P.view, P.manage]);
    http.expectOne(`${API}/BRD1`).flush(envelope(ACME));
    await settle();
    type('input[formcontrolname="name"]', 'Acme Ltd');
    submit();
    const req = http.expectOne(`${API}/BRD1`);
    expect(req.request.method).toBe('PUT');
    expect(req.request.body).toEqual({ code: 'ACME', name: 'Acme Ltd' });
    req.flush(envelope({ ...ACME, name: 'Acme Ltd' }));
    await settle();
    one(listRequests()).flush(pageEnvelope([ACME]));
  });

  it('opens a soft-deleted brand read-only with no Save', async () => {
    await open('/catalog/brands/BRD1/edit', [P.view, P.manage]);
    http.expectOne(`${API}/BRD1`).flush(envelope({ ...ACME, deleted_at: '2026-01-01T00:00:00Z' }));
    await settle();
    expect(el().querySelector('.adm-badge')?.textContent).toContain('Deleted');
    expect(el().querySelector('button[type="submit"]')).toBeNull();
  });

  it('keeps the row and shows the server message when the delete is refused (AC-20)', async () => {
    await openList([P.view, P.manage]);
    (el().querySelector('button[aria-label="Delete Acme"]') as HTMLButtonElement).click();
    harness.detectChanges();
    expect(text()).toContain('Delete Acme?');
    dialogButton('Delete').click();
    http
      .expectOne(`${API}/BRD1`)
      .flush(
        ...refusal(
          409,
          'catalog.in_use',
          undefined,
          undefined,
          'Brand ACME cannot be deleted: the product SKU-1 uses it.',
        ),
      );
    harness.detectChanges();
    expect(text()).toContain('the product SKU-1 uses it');
    expect(el().querySelectorAll('tbody tr')).toHaveLength(2);
    http.expectNone(() => true);
  });

  it('cancelling the delete question sends nothing', async () => {
    await openList([P.view, P.manage]);
    (el().querySelector('button[aria-label="Delete Acme"]') as HTMLButtonElement).click();
    harness.detectChanges();
    dialogButton('Cancel').click();
    harness.detectChanges();
    expect(el().querySelector('[role="dialog"]')).toBeNull();
    http.expectNone(() => true);
  });
});
