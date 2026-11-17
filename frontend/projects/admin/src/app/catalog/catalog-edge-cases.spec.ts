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
import { pageEnvelope, refusal, seedSession } from '../crud/testing/crud-testing';

/**
 * QA edge cases for T-036 beyond the developers' specs (AC-8, AC-17, AC-19, AC-20). The BUG-3,
 * BUG-4 and BUG-5 cases were written as expected failures (QA round 1); the fixes landed in
 * the fix round and they are ordinary guards now.
 */

const API = '/api/v1/catalog/brands';
const B = CATALOG_PERMISSIONS.brands;
const PR = CATALOG_PERMISSIONS.products;

const ACME: Brand = { id: 'BRD1', code: 'ACME', name: 'Acme', is_active: true, deleted_at: null };
const ZED: Brand = { id: 'BRD2', code: 'ZED', name: 'Zed', is_active: true, deleted_at: null };

const testRoutes: Routes = [
  { path: 'catalog', loadChildren: () => import('./catalog.routes') },
  {
    path: 'forbidden',
    loadComponent: () => import('../shell/forbidden-page').then((m) => m.ForbiddenPage),
  },
];

describe('catalog edge cases (T-036, QA)', () => {
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
  const brandLists = () => http.match((r) => r.method === 'GET' && r.url === API);
  const one = (r: TestRequest[]): TestRequest => {
    expect(r).toHaveLength(1);
    return r[0];
  };
  async function settle(): Promise<void> {
    await harness.fixture.whenStable();
    harness.detectChanges();
  }
  const deleteButton = (name: string) =>
    el().querySelector(`button[aria-label="Delete ${name}"]`) as HTMLButtonElement;
  const dialogButton = (label: string) =>
    Array.from(el().querySelectorAll('[role="dialog"] button')).find(
      (b) => b.textContent?.trim() === label,
    ) as HTMLButtonElement;
  function drainAll(): void {
    for (const r of http.match(() => true)) {
      r.flush(pageEnvelope([]));
    }
  }

  async function openBrandList(rows: Brand[] = [ACME, ZED]): Promise<void> {
    await open('/catalog/brands', [B.view, B.manage]);
    one(brandLists()).flush(pageEnvelope(rows));
    await settle();
  }

  afterEach(() => {
    sessionStorage.clear();
    http.verify();
  });

  describe('delete confirmation, focus (AC-19, AC-20)', () => {
    it('Escape closes the dialog on a real page, sends nothing, and focus returns to the Delete button that opened it', async () => {
      await openBrandList();
      const trigger = deleteButton('Acme');
      trigger.focus();
      trigger.click();
      harness.detectChanges();
      const dialog = el().querySelector('[role="dialog"]') as HTMLElement;
      expect(dialog).not.toBeNull();
      expect(dialog.contains(document.activeElement)).toBe(true);
      dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      harness.detectChanges();
      expect(el().querySelector('[role="dialog"]')).toBeNull();
      expect(document.activeElement).toBe(trigger);
      http.expectNone(() => true);
    });

    it('names the row in the dialog, and only that row is asked about', async () => {
      await openBrandList();
      deleteButton('Zed').click();
      harness.detectChanges();
      const dialog = el().querySelector('[role="dialog"]') as HTMLElement;
      expect(dialog.textContent).toContain('Delete Zed?');
      expect(dialog.textContent).not.toContain('Acme');
      const labelledBy = dialog.getAttribute('aria-labelledby') as string;
      expect(el().querySelector(`#${labelledBy}`)?.textContent).toContain('Delete Zed?');
      expect(dialog.getAttribute('aria-modal')).toBe('true');
    });

    it('a refused delete closes the question, keeps the row, and returns focus to the trigger', async () => {
      await openBrandList();
      const trigger = deleteButton('Acme');
      trigger.focus();
      trigger.click();
      harness.detectChanges();
      dialogButton('Delete').click();
      http
        .expectOne(`${API}/BRD1`)
        .flush(
          ...refusal(409, 'catalog.in_use', undefined, undefined, 'Brand ACME is used by SKU-1.'),
        );
      harness.detectChanges();
      expect(el().querySelector('[role="dialog"]')).toBeNull();
      expect(text()).toContain('Brand ACME is used by SKU-1.');
      expect(el().querySelectorAll('tbody tr')).toHaveLength(2);
      expect(document.activeElement).toBe(deleteButton('Acme'));
    });

    it('the second click on Delete while the request is in flight sends nothing more', async () => {
      await openBrandList();
      deleteButton('Acme').click();
      harness.detectChanges();
      const confirm = dialogButton('Delete');
      confirm.click();
      harness.detectChanges();
      confirm.click();
      harness.detectChanges();
      const req = http.expectOne(`${API}/BRD1`);
      expect(req.request.method).toBe('DELETE');
      http.expectNone(`${API}/BRD1`);
      req.flush({ success: true, data: null, message: '', errors: [], meta: { request_id: 'r' } });
      await settle();
      one(brandLists()).flush(pageEnvelope([ZED]));
    });

    // BUG-4 (minor, AC-19): after a SUCCESSFUL delete the list reloads, the row and its Delete
    // button are replaced, the dialog's saved trigger is no longer in the document, and focus
    // falls to <body>, so a keyboard user loses their place.
    it('BUG-4: keeps focus inside the page after a confirmed delete', async () => {
      await openBrandList();
      const trigger = deleteButton('Acme');
      trigger.focus();
      trigger.click();
      harness.detectChanges();
      dialogButton('Delete').click();
      http
        .expectOne(`${API}/BRD1`)
        .flush({ success: true, data: null, message: '', errors: [], meta: { request_id: 'r' } });
      await settle();
      one(brandLists()).flush(pageEnvelope([ZED]));
      await settle();
      expect(document.activeElement).not.toBe(document.body);
    });
  });

  describe('paging edge after a delete (AC-14)', () => {
    const many = Array.from({ length: 25 }, (_v, i) => ({
      ...ACME,
      id: `BRD${100 + i}`,
      code: `C${i}`,
      name: `Brand ${i}`,
    }));

    // BUG-5 (minor, AC-14): deleting the only row of the last page reloads that same page
    // number; the server answers an empty page and the list says "Nothing here yet" with a
    // pager reading "Page 2 of 1" although 25 rows exist on page 1.
    it('BUG-5: goes back to the last page that exists after deleting the last row of a page', async () => {
      await open('/catalog/brands', [B.view, B.manage]);
      one(brandLists()).flush(pageEnvelope(many, { page: 1, per_page: 25, total: 26 }));
      await settle();
      const next = Array.from(el().querySelectorAll('nav[aria-label="Pages"] button')).find(
        (b) => b.textContent?.trim() === 'Next',
      ) as HTMLButtonElement;
      next.click();
      harness.detectChanges();
      const page2 = one(brandLists());
      expect(page2.request.params.get('page')).toBe('2');
      page2.flush(pageEnvelope([ZED], { page: 2, per_page: 25, total: 26 }));
      await settle();
      deleteButton('Zed').click();
      harness.detectChanges();
      dialogButton('Delete').click();
      http
        .expectOne(`${API}/BRD2`)
        .flush({ success: true, data: null, message: '', errors: [], meta: { request_id: 'r' } });
      await settle();
      // The reload names page 2, which no longer exists, and the server answers it empty.
      one(brandLists()).flush(pageEnvelope([], { page: 2, per_page: 25, total: 25 }));
      await settle();
      // Correct behaviour: the screen asks for page 1 by itself, or never shows the empty state.
      const retry = brandLists();
      const askedPage1 = retry.some((r) => r.request.params.get('page') === '1');
      expect(askedPage1 || !text().includes('Nothing here yet')).toBe(true);
      for (const r of retry) {
        r.flush(pageEnvelope(many, { page: 1, per_page: 25, total: 25 }));
      }
    });
  });

  describe('server text is rendered as text (NFR security, AC-17)', () => {
    it('a field refusal carrying markup shows as literal text, with no element created', async () => {
      await open('/catalog/brands/new', [B.view, B.manage]);
      await settle();
      const set = (sel: string, value: string) => {
        const i = el().querySelector(sel) as HTMLInputElement;
        i.value = value;
        i.dispatchEvent(new Event('input'));
      };
      set('input[formcontrolname="code"]', 'X');
      set('input[formcontrolname="name"]', 'Y');
      (el().querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit'));
      const markup = '<img src=x onerror="alert(1)"><b>bold</b>';
      http.expectOne(API).flush(...refusal(422, 'catalog.duplicate', 'code', markup));
      await settle();
      expect(text()).toContain(markup);
      expect(el().querySelector('img')).toBeNull();
      expect(el().querySelector('b')).toBeNull();
    });

    it('a refused delete whose message carries markup shows as literal text', async () => {
      await openBrandList();
      deleteButton('Acme').click();
      harness.detectChanges();
      dialogButton('Delete').click();
      const markup = '<script>alert(1)</script><i>x</i>';
      http
        .expectOne(`${API}/BRD1`)
        .flush(...refusal(409, 'catalog.in_use', undefined, undefined, markup));
      harness.detectChanges();
      expect(text()).toContain(markup);
      expect(el().querySelector('script')).toBeNull();
      expect(el().querySelector('.adm-error-panel i')).toBeNull();
    });
  });

  describe('pickers when a lookup is refused (AC-8)', () => {
    // BUG-3 (minor, AC-8): the product form fills its Category, Brand and Unit pickers from the
    // categories, brands and units lists. A person who holds the products keys but not the
    // categories keys gets a 403 there; the picker swallows the error and shows an empty
    // select, so the form cannot be completed and nothing says why.
    it('BUG-3: tells the person when a picker lookup is refused with 403', async () => {
      await open('/catalog/products/new', [PR.view, PR.manage]);
      await settle();
      for (const r of http.match(() => true)) {
        if (r.request.url.endsWith('/catalog/categories')) {
          r.flush(...refusal(403, 'kernel.forbidden'));
        } else {
          r.flush(pageEnvelope([]));
        }
      }
      await settle();
      expect(text().toLowerCase()).toContain('permission');
      drainAll();
    });
  });

  it('control: a picker lookup that succeeds sends no refusal text', async () => {
    await open('/catalog/products/new', [PR.view, PR.manage]);
    await settle();
    drainAll();
    await settle();
    expect(text().toLowerCase()).not.toContain('permission');
    drainAll();
  });

  it('control: the router stays on the brands list after a 403 on a delete (never signed out)', async () => {
    await openBrandList();
    deleteButton('Acme').click();
    harness.detectChanges();
    dialogButton('Delete').click();
    http.expectOne(`${API}/BRD1`).flush(...refusal(403, 'kernel.forbidden'));
    harness.detectChanges();
    expect(text()).toContain('You do not have permission to see or change this.');
    expect(router.url).toBe('/catalog/brands');
    expect(el().querySelectorAll('tbody tr')).toHaveLength(2);
  });
});
