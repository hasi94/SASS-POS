import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  TestRequest,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, Routes, provideRouter, withComponentInputBinding } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { CATALOG_PERMISSIONS, Modifier, ModifierGroup, Product, Unit } from '@flexipos/shared';
import { envelope, pageEnvelope, refusal, seedSession } from '../../crud/testing/crud-testing';

const API = '/api/v1/catalog/modifier-groups';
const PRODUCTS = '/api/v1/catalog/products';
const UNITS = '/api/v1/catalog/units';
const P = CATALOG_PERMISSIONS.modifiers;

const GROUP: ModifierGroup = {
  id: 'GRP1',
  name: 'Extras',
  min_select: 0,
  max_select: 3,
  deleted_at: null,
};
const OPEN_GROUP: ModifierGroup = { ...GROUP, id: 'GRP2', name: 'Sides', max_select: null };
const PLAIN: Modifier = {
  id: 'MOD1',
  group_id: 'GRP1',
  name: 'Extra cheese',
  price_delta: '1.5000',
  cost_delta: '0.4000',
  linked_product_id: null,
  linked_product_name: null,
  linked_quantity: null,
  linked_unit_id: null,
  linked_unit_code: null,
};
const LINKED: Modifier = {
  ...PLAIN,
  id: 'MOD2',
  name: 'Extra shot',
  linked_product_id: 'PRD1',
  linked_product_name: 'Cola',
  linked_quantity: '2.0000',
  linked_unit_id: 'UNI1',
  linked_unit_code: 'EA',
};
const unit = (id: string, code: string, dimension: Unit['dimension']): Unit => ({
  id,
  code,
  name: code === 'EA' ? 'Each' : 'Kilogram',
  dimension,
  precision: 0,
  base_unit_id: null,
  base_unit_code: null,
  factor: null,
  is_active: true,
});
const EA = unit('UNI1', 'EA', 'count');
const KG = unit('UNI3', 'KG', 'mass');
const COLA = { id: 'PRD1', sku: 'COLA', name: 'Cola', stock_unit_id: 'UNI1' } as Product;

const testRoutes: Routes = [
  { path: 'catalog', loadChildren: () => import('../catalog.routes') },
  {
    path: 'forbidden',
    loadComponent: () => import('../../shell/forbidden-page').then((m) => m.ForbiddenPage),
  },
];

describe('modifier screens (T-036 AC-7, AC-20, AC-52 to AC-55)', () => {
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
  /** Answers every read the form pickers send, until nothing is pending. */
  async function drain(): Promise<void> {
    await settle();
    for (let round = 0; round < 6; round += 1) {
      const pending = http.match(() => true);
      if (pending.length === 0) {
        return;
      }
      for (const req of pending) {
        expect(req.request.method).toBe('GET');
        const reply: Record<string, unknown> = {
          [PRODUCTS]: pageEnvelope([COLA]),
          [`${PRODUCTS}/PRD1`]: envelope(COLA),
          [UNITS]: pageEnvelope([EA, KG]),
          [`${UNITS}/UNI1`]: envelope(EA),
          [`${API}/GRP1/modifiers/MOD2`]: envelope(LINKED),
          [`${API}/GRP1/modifiers/MOD1`]: envelope(PLAIN),
          [`${API}/GRP1`]: envelope(GROUP),
        };
        const body = reply[req.request.url];
        if (body === undefined) {
          throw new Error(`Unexpected request ${req.request.url}`);
        }
        req.flush(body);
      }
      await settle();
    }
  }
  async function openList(permissions: readonly string[]) {
    await open('/catalog/modifiers', permissions);
    one(listRequests()).flush(pageEnvelope([GROUP, OPEN_GROUP]));
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
  const dialogButton = (label: string) =>
    Array.from(el().querySelectorAll('[role="dialog"] button')).find(
      (b) => b.textContent?.trim() === label,
    ) as HTMLButtonElement;
  const describedText = (selector: string): string => {
    const id = el().querySelector(selector)?.getAttribute('aria-describedby');
    return id ? (el().querySelector(`#${id}`)?.textContent ?? '') : '';
  };
  const productSelect = 'adm-entity-picker:nth-of-type(1) select';
  const unitSelect = 'adm-entity-picker:nth-of-type(2) select';

  afterEach(() => http.verify());

  describe('groups', () => {
    it('lists name, minimum and maximum, with "No limit" for an empty maximum', async () => {
      await openList([P.view]);
      const rows = Array.from(el().querySelectorAll('tbody tr'));
      expect(rows).toHaveLength(2);
      expect(rows[0].textContent).toContain('Extras');
      expect(rows[0].textContent).toContain('3');
      expect(rows[1].textContent).toContain('No limit');
    });

    it('has no Create, Edit or Delete with view only, and forbids every form route', async () => {
      await openList([P.view]);
      expect(text()).not.toContain('New modifier group');
      expect(text()).not.toContain('Edit');
      expect(text()).not.toContain('Delete');
      for (const url of [
        '/catalog/modifiers/new',
        '/catalog/modifiers/GRP1/edit',
        '/catalog/modifiers/GRP1/modifiers/new',
        '/catalog/modifiers/GRP1/modifiers/MOD1/edit',
      ]) {
        await harness.navigateByUrl(url);
        expect(router.url, url).toBe('/forbidden');
      }
      http.expectNone(() => true);
    });

    it('shows the permission message on a 403 and keeps the route (AC-8)', async () => {
      await open('/catalog/modifiers', [P.view]);
      one(listRequests()).flush(...refusal(403, 'kernel.forbidden'));
      await settle();
      expect(text()).toContain('You do not have permission to see or change this.');
      expect(router.url).toBe('/catalog/modifiers');
    });

    it('keeps the row and shows the server message when a group delete is refused (AC-55)', async () => {
      await openList([P.view, P.manage]);
      (el().querySelector('button[aria-label="Delete Extras"]') as HTMLButtonElement).click();
      harness.detectChanges();
      expect(text()).toContain('Delete Extras?');
      dialogButton('Delete').click();
      http
        .expectOne(`${API}/GRP1`)
        .flush(
          ...refusal(
            409,
            'catalog.in_use',
            undefined,
            undefined,
            'Modifier group Extras cannot be deleted: it is attached to the product COLA.',
          ),
        );
      harness.detectChanges();
      expect(text()).toContain('it is attached to the product COLA');
      expect(el().querySelectorAll('tbody tr')).toHaveLength(2);
      http.expectNone(() => true);
    });

    describe('form', () => {
      async function openCreate() {
        await open('/catalog/modifiers/new', [P.view, P.manage]);
        await settle();
      }
      const minInput = 'input[formcontrolname="min_select"]';
      const maxInput = 'input[formcontrolname="max_select"]';

      it('defaults the minimum to 0 and leaves the maximum empty', async () => {
        await openCreate();
        expect((el().querySelector(minInput) as HTMLInputElement).value).toBe('0');
        expect((el().querySelector(maxInput) as HTMLInputElement).value).toBe('');
      });

      it('blocks a blank name, naming it, with nothing sent', async () => {
        await openCreate();
        submit();
        expect(text()).toContain('Name is required.');
        http.expectNone(() => true);
      });

      it('blocks a minimum above the maximum on the maximum field', async () => {
        await openCreate();
        type('input[formcontrolname="name"]', 'Extras');
        type(minInput, '4');
        type(maxInput, '2');
        submit();
        expect(describedText(maxInput)).toContain('Maximum cannot be smaller than the minimum.');
        expect(el().querySelector(maxInput)?.getAttribute('aria-invalid')).toBe('true');
        http.expectNone(() => true);
      });

      it.each(['-1', '1.5', ''])('blocks a minimum of %j', async (value) => {
        await openCreate();
        type('input[formcontrolname="name"]', 'Extras');
        type(minInput, value);
        submit();
        expect(describedText(minInput)).toContain('Minimum must be a whole number, 0 or more.');
        http.expectNone(() => true);
      });

      it('blocks a maximum that is not a whole number', async () => {
        await openCreate();
        type('input[formcontrolname="name"]', 'Extras');
        type(maxInput, '2.5');
        submit();
        expect(describedText(maxInput)).toContain('Maximum must be a whole number');
        http.expectNone(() => true);
      });

      it('sends an empty maximum as null', async () => {
        await openCreate();
        type('input[formcontrolname="name"]', ' Sides ');
        submit();
        const req = http.expectOne(API);
        expect(req.request.method).toBe('POST');
        expect(req.request.body).toEqual({ name: 'Sides', min_select: 0, max_select: null });
        expect('max_select' in (req.request.body as object)).toBe(true);
        req.flush(envelope(OPEN_GROUP));
        await settle();
        expect(router.url).toBe('/catalog/modifiers');
        one(listRequests()).flush(pageEnvelope([OPEN_GROUP]));
      });

      it('sends a filled maximum as a number, and equal minimum and maximum pass', async () => {
        await openCreate();
        type('input[formcontrolname="name"]', 'Pick two');
        type(minInput, '2');
        type(maxInput, '2');
        submit();
        const req = http.expectOne(API);
        expect(req.request.body).toEqual({ name: 'Pick two', min_select: 2, max_select: 2 });
        req.flush(envelope(GROUP));
        await settle();
        one(listRequests()).flush(pageEnvelope([GROUP]));
      });

      it('shows a server refusal on the field it names and keeps the typed values', async () => {
        await openCreate();
        type('input[formcontrolname="name"]', 'Extras');
        submit();
        http
          .expectOne(API)
          .flush(...refusal(422, 'kernel.validation_failed', 'name', 'That name is taken.'));
        harness.detectChanges();
        expect(describedText('input[formcontrolname="name"]')).toContain('That name is taken.');
        expect(
          (el().querySelector('input[formcontrolname="name"]') as HTMLInputElement).value,
        ).toBe('Extras');
      });

      it('edits through PUT, with the loaded values', async () => {
        await open('/catalog/modifiers/GRP1/edit', [P.view, P.manage]);
        http.expectOne(`${API}/GRP1`).flush(envelope(GROUP));
        await settle();
        expect((el().querySelector(maxInput) as HTMLInputElement).value).toBe('3');
        type(maxInput, '');
        submit();
        const put = http.expectOne(`${API}/GRP1`);
        expect(put.request.method).toBe('PUT');
        expect(put.request.body).toEqual({ name: 'Extras', min_select: 0, max_select: null });
        put.flush(envelope(GROUP));
        await settle();
        one(listRequests()).flush(pageEnvelope([GROUP]));
      });

      it('opens a soft-deleted group read-only, with no Save', async () => {
        await open('/catalog/modifiers/GRP1/edit', [P.view, P.manage]);
        http
          .expectOne(`${API}/GRP1`)
          .flush(envelope({ ...GROUP, deleted_at: '2026-01-01T00:00:00Z' }));
        await settle();
        expect(el().querySelector('.adm-badge')?.textContent).toContain('Deleted');
        expect(el().querySelector('button[type="submit"]')).toBeNull();
      });
    });
  });

  describe('group detail', () => {
    async function openDetail(permissions: readonly string[], group = GROUP) {
      await open('/catalog/modifiers/GRP1', permissions);
      http.expectOne(`${API}/GRP1`).flush(envelope(group));
      http.expectOne((r) => r.url === `${API}/GRP1/modifiers`).flush(pageEnvelope([PLAIN, LINKED]));
      await settle();
    }

    it('lists name, price change, cost change and the linked product, quantity and unit', async () => {
      await openDetail([P.view]);
      expect(el().querySelector('h1')?.textContent).toContain('Extras');
      const headers = Array.from(el().querySelectorAll('th')).map((h) => h.textContent?.trim());
      expect(headers).toEqual(
        expect.arrayContaining(['Name', 'Price change', 'Cost change', 'Linked product']),
      );
      const rows = Array.from(el().querySelectorAll('tbody tr'));
      expect(rows[0].textContent).toContain('Extra cheese');
      expect(rows[0].textContent).toContain('1.5000');
      expect(rows[0].textContent).toContain('0.4000');
      expect(rows[1].textContent).toContain('Cola');
      expect(rows[1].textContent).toContain('2.0000');
      expect(rows[1].textContent).toContain('EA');
    });

    it('has no change controls with view only', async () => {
      await openDetail([P.view]);
      expect(text()).not.toContain('New modifier');
      expect(text()).not.toContain('Edit');
      expect(text()).not.toContain('Delete');
    });

    it('offers the controls with manage, and none for a deleted group', async () => {
      await openDetail([P.view, P.manage]);
      expect(el().querySelector('a[href="/catalog/modifiers/GRP1/modifiers/new"]')).not.toBeNull();
      expect(
        el().querySelector('a[href="/catalog/modifiers/GRP1/modifiers/MOD1/edit"]'),
      ).not.toBeNull();
      TestBed.resetTestingModule();
      await openDetail([P.view, P.manage], { ...GROUP, deleted_at: '2026-01-01T00:00:00Z' });
      expect(el().querySelector('.adm-badge')?.textContent).toContain('Deleted');
      expect(text()).not.toContain('New modifier');
      expect(el().querySelector('button[aria-label^="Delete"]')).toBeNull();
      expect(el().querySelector('a[aria-label^="Edit"]')).toBeNull();
    });

    it('deletes a modifier after confirmation and reloads the list', async () => {
      await openDetail([P.view, P.manage]);
      (el().querySelector('button[aria-label="Delete Extra cheese"]') as HTMLButtonElement).click();
      harness.detectChanges();
      dialogButton('Delete').click();
      const del = http.expectOne(`${API}/GRP1/modifiers/MOD1`);
      expect(del.request.method).toBe('DELETE');
      del.flush(envelope(null));
      http.expectOne((r) => r.url === `${API}/GRP1/modifiers`).flush(pageEnvelope([LINKED]));
      harness.detectChanges();
      expect(el().querySelectorAll('tbody tr')).toHaveLength(1);
    });

    it('shows an error panel with Retry when the group cannot be loaded', async () => {
      await open('/catalog/modifiers/GRP1', [P.view]);
      http.expectOne(`${API}/GRP1`).flush(...refusal(404, 'kernel.not_found'));
      http.expectOne((r) => r.url === `${API}/GRP1/modifiers`).flush(pageEnvelope([]));
      await settle();
      expect(el().querySelector('[role="alert"]')).not.toBeNull();
      expect(
        Array.from(el().querySelectorAll('button')).some((b) => b.textContent?.trim() === 'Retry'),
      ).toBe(true);
    });
  });

  describe('modifier form', () => {
    const MODS = `${API}/GRP1/modifiers`;

    async function openCreate() {
      await open('/catalog/modifiers/GRP1/modifiers/new', [P.view, P.manage]);
      await drain();
    }
    async function linkProduct() {
      choose(productSelect, 'PRD1');
      await drain();
    }

    it('defaults price change and cost change to 0', async () => {
      await openCreate();
      expect(
        (el().querySelector('input[formcontrolname="price_delta"]') as HTMLInputElement).value,
      ).toBe('0');
      expect(
        (el().querySelector('input[formcontrolname="cost_delta"]') as HTMLInputElement).value,
      ).toBe('0');
    });

    it('shows no quantity or unit until a product is chosen', async () => {
      await openCreate();
      expect(el().querySelector('input[formcontrolname="linked_quantity"]')).toBeNull();
      await linkProduct();
      expect(el().querySelector('input[formcontrolname="linked_quantity"]')).not.toBeNull();
      expect(el().querySelector(unitSelect)).not.toBeNull();
    });

    it('requires a name, and a numeric price and cost change, with nothing sent', async () => {
      await openCreate();
      type('input[formcontrolname="price_delta"]', 'abc');
      type('input[formcontrolname="cost_delta"]', '');
      submit();
      expect(text()).toContain('Name is required.');
      expect(text()).toContain('Price change must be a number.');
      expect(text()).toContain('Cost change is required.');
      http.expectNone(() => true);
    });

    it('sends a plain modifier with no linked keys at all', async () => {
      await openCreate();
      type('input[formcontrolname="name"]', 'Extra cheese');
      type('input[formcontrolname="price_delta"]', '1.5');
      submit();
      const req = http.expectOne(MODS);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({
        name: 'Extra cheese',
        price_delta: '1.5',
        cost_delta: '0',
      });
      const keys = Object.keys(req.request.body as object);
      expect(keys).not.toContain('linked_product_id');
      expect(keys).not.toContain('linked_quantity');
      expect(keys).not.toContain('linked_unit_id');
      req.flush(envelope(PLAIN));
      await settle();
      expect(router.url).toBe('/catalog/modifiers/GRP1');
      await drainDetail();
    });

    /** After a save the page returns to the group, which asks for it and its modifiers. */
    async function drainDetail() {
      http.expectOne(`${API}/GRP1`).flush(envelope(GROUP));
      http.expectOne((r) => r.url === MODS).flush(pageEnvelope([PLAIN]));
      await settle();
    }

    it('requires the quantity and the unit once a product is chosen', async () => {
      await openCreate();
      type('input[formcontrolname="name"]', 'Extra shot');
      await linkProduct();
      submit();
      expect(text()).toContain('Linked quantity is required.');
      expect(text()).toContain('Linked unit is required.');
      http.expectNone(() => true);
    });

    it('blocks a linked quantity of 0', async () => {
      await openCreate();
      type('input[formcontrolname="name"]', 'Extra shot');
      await linkProduct();
      type('input[formcontrolname="linked_quantity"]', '0');
      choose(unitSelect, 'UNI1');
      submit();
      expect(text()).toContain('Linked quantity must be greater than 0.');
      http.expectNone(() => true);
    });

    it('offers only units of the linked product stock unit dimension', async () => {
      await openCreate();
      await linkProduct();
      const labels = Array.from((el().querySelector(unitSelect) as HTMLSelectElement).options).map(
        (o) => o.textContent?.trim(),
      );
      expect(labels).toContain('EA (Each)');
      expect(labels).not.toContain('KG (Kilogram)');
    });

    it('sends the three linked fields together', async () => {
      await openCreate();
      type('input[formcontrolname="name"]', 'Extra shot');
      await linkProduct();
      type('input[formcontrolname="linked_quantity"]', '2');
      choose(unitSelect, 'UNI1');
      submit();
      const req = http.expectOne(MODS);
      expect(req.request.body).toEqual({
        name: 'Extra shot',
        price_delta: '0',
        cost_delta: '0',
        linked_product_id: 'PRD1',
        linked_quantity: '2',
        linked_unit_id: 'UNI1',
      });
      req.flush(envelope(LINKED));
      await settle();
      await drainDetail();
    });

    it('removes the quantity and unit from the request when the product is cleared', async () => {
      await openCreate();
      type('input[formcontrolname="name"]', 'Extra shot');
      await linkProduct();
      type('input[formcontrolname="linked_quantity"]', '2');
      choose(unitSelect, 'UNI1');
      choose(productSelect, '');
      await drain();
      expect(el().querySelector('input[formcontrolname="linked_quantity"]')).toBeNull();
      submit();
      const req = http.expectOne(MODS);
      const keys = Object.keys(req.request.body as object);
      expect(keys).toEqual(['name', 'price_delta', 'cost_delta']);
      req.flush(envelope(PLAIN));
      await settle();
      await drainDetail();
    });

    it('shows a server dimension refusal on the unit field', async () => {
      await openCreate();
      type('input[formcontrolname="name"]', 'Extra shot');
      await linkProduct();
      type('input[formcontrolname="linked_quantity"]', '2');
      choose(unitSelect, 'UNI1');
      submit();
      http
        .expectOne(MODS)
        .flush(
          ...refusal(
            422,
            'catalog.dimension_mismatch',
            'linked_unit_id',
            'EA is count but COLA is counted in KG (mass).',
          ),
        );
      harness.detectChanges();
      expect(describedText(unitSelect)).toContain('EA is count but COLA is counted in KG (mass).');
      expect(el().querySelector(unitSelect)?.getAttribute('aria-invalid')).toBe('true');
      expect(
        (el().querySelector('input[formcontrolname="linked_quantity"]') as HTMLInputElement).value,
      ).toBe('2');
    });

    it('does not offer manage-less people the form (route guard)', async () => {
      await open('/catalog/modifiers/GRP1/modifiers/new', [P.view]);
      expect(router.url).toBe('/forbidden');
      http.expectNone(() => true);
    });

    it('edits a linked modifier through PUT with the linked trio kept', async () => {
      await open('/catalog/modifiers/GRP1/modifiers/MOD2/edit', [P.view, P.manage]);
      await drain();
      expect((el().querySelector('input[formcontrolname="name"]') as HTMLInputElement).value).toBe(
        'Extra shot',
      );
      expect(
        (el().querySelector('input[formcontrolname="linked_quantity"]') as HTMLInputElement).value,
      ).toBe('2.0000');
      type('input[formcontrolname="price_delta"]', '2');
      submit();
      const put = http.expectOne(`${MODS}/MOD2`);
      expect(put.request.method).toBe('PUT');
      expect(put.request.body).toEqual({
        name: 'Extra shot',
        price_delta: '2',
        cost_delta: '0.4000',
        linked_product_id: 'PRD1',
        linked_quantity: '2.0000',
        linked_unit_id: 'UNI1',
      });
      put.flush(envelope(LINKED));
      await settle();
      await drainDetail();
    });

    it('unlinks on edit by sending the three linked keys as explicit null (W-2)', async () => {
      await open('/catalog/modifiers/GRP1/modifiers/MOD2/edit', [P.view, P.manage]);
      await drain();
      choose(productSelect, '');
      await drain();
      submit();
      const put = http.expectOne(`${MODS}/MOD2`);
      expect(put.request.method).toBe('PUT');
      expect(put.request.body).toEqual({
        name: 'Extra shot',
        price_delta: '1.5000',
        cost_delta: '0.4000',
        linked_product_id: null,
        linked_quantity: null,
        linked_unit_id: null,
      });
      put.flush(envelope(PLAIN));
      await settle();
      await drainDetail();
    });

    it('edits a plain modifier and sends the linked keys as null, never omitted', async () => {
      await open('/catalog/modifiers/GRP1/modifiers/MOD1/edit', [P.view, P.manage]);
      await drain();
      submit();
      const put = http.expectOne(`${MODS}/MOD1`);
      expect(Object.keys(put.request.body as object)).toEqual([
        'name',
        'price_delta',
        'cost_delta',
        'linked_product_id',
        'linked_quantity',
        'linked_unit_id',
      ]);
      put.flush(envelope(PLAIN));
      await settle();
      await drainDetail();
    });
  });
});
