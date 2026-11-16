import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  TestRequest,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, Routes, provideRouter, withComponentInputBinding } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { CATALOG_PERMISSIONS, Category } from '@flexipos/shared';
import { envelope, pageEnvelope, refusal, seedSession } from '../../crud/testing/crud-testing';

const API = '/api/v1/catalog/categories';
const P = CATALOG_PERMISSIONS.categories;

const FOOD: Category = {
  id: 'CAT1',
  code: 'FOOD',
  name: 'Food',
  parent_id: null,
  parent_name: null,
  path: '/CAT1/',
  depth: 0,
  is_active: true,
  deleted_at: null,
};
const DRINKS: Category = {
  ...FOOD,
  id: 'CAT2',
  code: 'DRINKS',
  name: 'Drinks',
  parent_id: 'CAT1',
  parent_name: 'Food',
  path: '/CAT1/CAT2/',
  depth: 1,
};
const RETAIL: Category = { ...FOOD, id: 'CAT3', code: 'RETAIL', name: 'Retail', path: '/CAT3/' };

const testRoutes: Routes = [
  { path: 'catalog', loadChildren: () => import('../catalog.routes') },
  {
    path: 'forbidden',
    loadComponent: () => import('../../shell/forbidden-page').then((m) => m.ForbiddenPage),
  },
];

describe('categories screens (T-036 AC-7, AC-20, AC-28 to AC-32)', () => {
  let http: HttpTestingController;
  let router: Router;
  let harness: RouterTestingHarness;

  async function open(url: string, permissions: readonly string[]): Promise<HTMLElement> {
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
    return el();
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
  /** Answers the list GET the page sends on open. */
  async function openList(permissions: readonly string[], rows = [FOOD, DRINKS, RETAIL]) {
    await open('/catalog/categories', permissions);
    one(listRequests()).flush(pageEnvelope(rows));
    await settle();
  }
  /** Opens the create form and answers the parent picker's list GET. */
  async function openCreate(permissions = [P.view, P.manage]) {
    await open('/catalog/categories/new', permissions);
    one(listRequests()).flush(pageEnvelope([FOOD, DRINKS, RETAIL]));
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
  const button = (label: string) =>
    Array.from(el().querySelectorAll('button')).find((b) => b.textContent?.trim() === label);

  const dialogButton = (label: string) =>
    Array.from(el().querySelectorAll('[role="dialog"] button')).find(
      (b) => b.textContent?.trim() === label,
    ) as HTMLButtonElement;

  afterEach(() => http.verify());

  describe('list', () => {
    it('shows code, name, parent name and depth, and indents the name by depth', async () => {
      await openList([P.view]);
      const headers = Array.from(el().querySelectorAll('th')).map((th) => th.textContent?.trim());
      expect(headers).toContain('Code');
      expect(headers).toContain('Name');
      expect(headers).toContain('Parent');
      expect(headers).toContain('Depth');
      const rows = Array.from(el().querySelectorAll('tbody tr'));
      expect(rows).toHaveLength(3);
      expect(rows[1].textContent).toContain('DRINKS');
      expect(rows[1].textContent).toContain('Food');
      const nameCell = (row: Element) => row.querySelectorAll('td')[1] as HTMLElement;
      expect(nameCell(rows[0]).style.paddingLeft).toBe('0.75rem');
      expect(nameCell(rows[1]).style.paddingLeft).toBe('2rem');
    });

    it('with view only has no Create, Edit or Delete in the DOM', async () => {
      await openList([P.view]);
      expect(text()).not.toContain('New category');
      expect(text()).not.toContain('Edit');
      expect(text()).not.toContain('Delete');
      expect(el().querySelector('a[href="/catalog/categories/new"]')).toBeNull();
    });

    it('with manage shows Create, Edit and Delete', async () => {
      await openList([P.view, P.manage]);
      expect(el().querySelector('a[href="/catalog/categories/new"]')).not.toBeNull();
      expect(el().querySelector('a[href="/catalog/categories/CAT2/edit"]')).not.toBeNull();
      expect(button('Delete')).toBeDefined();
    });

    it('sends the form routes of a view-only person to /forbidden, with no request', async () => {
      await open('/catalog/categories/new', [P.view]);
      expect(router.url).toBe('/forbidden');
      http.expectNone(() => true);
      await harness.navigateByUrl('/catalog/categories/CAT1/edit');
      expect(router.url).toBe('/forbidden');
      http.expectNone(() => true);
    });

    it('shows the permission message on a 403 and keeps the person signed in (AC-8)', async () => {
      await open('/catalog/categories', [P.view]);
      one(listRequests()).flush(...refusal(403, 'kernel.forbidden'));
      await settle();
      expect(text()).toContain('You do not have permission to see or change this.');
      expect(router.url).toBe('/catalog/categories');
      expect(sessionStorage.getItem('flexipos.session')).not.toBeNull();
    });

    it('keeps the row and shows the server message when a delete is refused (AC-20, AC-32)', async () => {
      await openList([P.view, P.manage]);
      (el().querySelector('button[aria-label="Delete Food"]') as HTMLButtonElement).click();
      harness.detectChanges();
      expect(text()).toContain('Delete Food?');
      dialogButton('Delete').click();
      const req = http.expectOne(`${API}/CAT1`);
      expect(req.request.method).toBe('DELETE');
      req.flush(
        ...refusal(
          409,
          'catalog.in_use',
          undefined,
          undefined,
          'Category FOOD cannot be deleted: it has the active child category DRINKS.',
        ),
      );
      harness.detectChanges();
      expect(text()).toContain('it has the active child category DRINKS');
      expect(el().querySelectorAll('tbody tr')).toHaveLength(3);
      expect(el().querySelector('[role="dialog"]')).toBeNull();
      http.expectNone(() => true);
    });

    it('reloads the list after a confirmed delete', async () => {
      await openList([P.view, P.manage]);
      (el().querySelector('button[aria-label="Delete Retail"]') as HTMLButtonElement).click();
      harness.detectChanges();
      dialogButton('Delete').click();
      http.expectOne(`${API}/CAT3`).flush(envelope(null));
      one(listRequests()).flush(pageEnvelope([FOOD, DRINKS]));
      harness.detectChanges();
      expect(el().querySelectorAll('tbody tr')).toHaveLength(2);
    });
  });

  describe('create form', () => {
    it('blocks a blank code and name, naming each, and sends nothing (AC-29)', async () => {
      await openCreate();
      submit();
      expect(text()).toContain('Code is required.');
      expect(text()).toContain('Name is required.');
      http.expectNone(() => true);
    });

    it('blocks a name made only of spaces', async () => {
      await openCreate();
      type('input[formcontrolname="code"]', 'X');
      type('input[formcontrolname="name"]', '   ');
      submit();
      expect(text()).toContain('Name is required.');
      http.expectNone(() => true);
    });

    it('sends only code, name and parent_id: never path or depth', async () => {
      await openCreate();
      type('input[formcontrolname="code"]', ' SNACK ');
      type('input[formcontrolname="name"]', 'Snacks');
      submit();
      const req = http.expectOne(API);
      expect(req.request.method).toBe('POST');
      expect(Object.keys(req.request.body as object).sort()).toEqual(['code', 'name', 'parent_id']);
      expect(req.request.body).toEqual({ code: 'SNACK', name: 'Snacks', parent_id: null });
      expect(el().querySelector('input[name="path"], input[name="depth"]')).toBeNull();
      req.flush(envelope({ ...FOOD, id: 'CAT9' }));
      await settle();
      expect(router.url).toBe('/catalog/categories');
      one(listRequests()).flush(pageEnvelope([FOOD]));
    });

    it('sends nothing twice when submitted again while the first is in flight (AC-18)', async () => {
      await openCreate();
      type('input[formcontrolname="code"]', 'A');
      type('input[formcontrolname="name"]', 'B');
      submit();
      submit();
      const req = http.expectOne(API);
      expect(el().querySelector('button[type="submit"]')?.hasAttribute('disabled')).toBe(true);
      req.flush(envelope(FOOD));
      await settle();
      one(listRequests()).flush(pageEnvelope([FOOD]));
    });

    it.each([
      ['catalog.duplicate', 'code', 'Code FOOD is already used by category Food.'],
      ['kernel.validation_failed', 'parent_id', 'The parent category does not exist.'],
      ['catalog.cycle', 'parent_id', 'Drinks is below Food, so it cannot be its parent.'],
      ['catalog.too_deep', 'parent_id', 'A category can be at most 17 levels deep.'],
    ])(
      'shows a %s refusal on the %s field, linked by aria-describedby',
      async (code, field, detail) => {
        await openCreate();
        type('input[formcontrolname="code"]', 'FOOD');
        type('input[formcontrolname="name"]', 'Typed name');
        submit();
        http.expectOne(API).flush(...refusal(422, code, field, detail));
        harness.detectChanges();
        const target =
          field === 'code'
            ? el().querySelector('input[formcontrolname="code"]')
            : el().querySelector('adm-entity-picker select');
        const describedBy = target?.getAttribute('aria-describedby');
        expect(describedBy).toBeTruthy();
        expect(el().querySelector(`#${describedBy}`)?.textContent).toContain(detail);
        expect(target?.getAttribute('aria-invalid') ?? 'true').toBe('true');
        // Typed values are kept.
        expect(
          (el().querySelector('input[formcontrolname="code"]') as HTMLInputElement).value,
        ).toBe('FOOD');
        expect(
          (el().querySelector('input[formcontrolname="name"]') as HTMLInputElement).value,
        ).toBe('Typed name');
      },
    );

    it('shows a refusal that names no known field in the summary', async () => {
      await openCreate();
      type('input[formcontrolname="code"]', 'A');
      type('input[formcontrolname="name"]', 'B');
      submit();
      http.expectOne(API).flush(...refusal(422, 'catalog.duplicate', 'path', 'Path clash.'));
      harness.detectChanges();
      expect(el().querySelector('.adm-form-summary')?.textContent).toContain('Path clash.');
    });

    it('shows the permission message when the save is refused with 403 (AC-8)', async () => {
      await openCreate();
      type('input[formcontrolname="code"]', 'A');
      type('input[formcontrolname="name"]', 'B');
      submit();
      http.expectOne(API).flush(...refusal(403, 'kernel.forbidden'));
      harness.detectChanges();
      expect(text()).toContain('You do not have permission to see or change this.');
      expect(router.url).toBe('/catalog/categories/new');
    });
  });

  describe('edit form', () => {
    async function openEdit(row: Category) {
      await open(`/catalog/categories/${row.id}/edit`, [P.view, P.manage]);
      http.expectOne(`${API}/${row.id}`).flush(envelope(row));
      await settle();
      one(listRequests()).flush(pageEnvelope([FOOD, DRINKS, RETAIL]));
      await settle();
    }

    it('loads the row, sends the new parent_id, and then the list reloads from the server (AC-31)', async () => {
      await openEdit(DRINKS);
      expect((el().querySelector('input[formcontrolname="code"]') as HTMLInputElement).value).toBe(
        'DRINKS',
      );
      const select = el().querySelector('adm-entity-picker select') as HTMLSelectElement;
      select.value = 'CAT3';
      select.dispatchEvent(new Event('change'));
      submit();
      const put = http.expectOne(`${API}/CAT2`);
      expect(put.request.method).toBe('PUT');
      expect(put.request.body).toEqual({ code: 'DRINKS', name: 'Drinks', parent_id: 'CAT3' });
      put.flush(envelope({ ...DRINKS, parent_id: 'CAT3' }));
      await settle();
      expect(router.url).toBe('/catalog/categories');
      const reload = one(listRequests());
      expect(reload.request.params.get('page')).toBe('1');
      reload.flush(pageEnvelope([FOOD]));
    });

    it('never offers the category itself as its parent', async () => {
      await openEdit(DRINKS);
      const labels = Array.from(
        (el().querySelector('adm-entity-picker select') as HTMLSelectElement).options,
      ).map((o) => o.textContent?.trim());
      expect(labels.some((l) => l?.startsWith('Drinks'))).toBe(false);
      expect(labels).toContain('Retail');
    });

    it('opens a soft-deleted category read-only, with a Deleted badge and no Save', async () => {
      await openEdit({ ...DRINKS, deleted_at: '2026-01-01T00:00:00Z' });
      expect(el().querySelector('.adm-badge')?.textContent).toContain('Deleted');
      expect(button('Save')).toBeUndefined();
      expect(
        (el().querySelector('input[formcontrolname="code"]') as HTMLInputElement).disabled,
      ).toBe(true);
    });

    it('shows an error panel with Retry when the row cannot be loaded', async () => {
      await open('/catalog/categories/CAT9/edit', [P.view, P.manage]);
      http.expectOne(`${API}/CAT9`).flush(...refusal(404, 'kernel.not_found'));
      await settle();
      expect(el().querySelector('[role="alert"]')).not.toBeNull();
      expect(button('Retry')).toBeDefined();
    });
  });
});
