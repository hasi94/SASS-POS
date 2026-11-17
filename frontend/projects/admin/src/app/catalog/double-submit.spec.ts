import { CATALOG_PERMISSIONS } from '@flexipos/shared';
import { envelope, pageEnvelope } from '../crud/testing/crud-testing';
import { BRAND, CATEGORY, Screen, UNITS, UNIT_EA, WATER } from './products/products-testing';

/**
 * QA for T-036 AC-18 on every create form. The developers proved the guard on the category
 * form only; each form wires its own `SubmitState`, so each one is checked here: a second
 * submit while the first request is in flight sends nothing and the Save control is busy.
 */

const P = CATALOG_PERMISSIONS;
const API = '/api/v1/catalog';

const GROUP = { id: 'GRP1', name: 'Sides', min_select: 0, max_select: null, deleted_at: null };

interface FormCase {
  name: string;
  url: string;
  keys: readonly string[];
  /** Fills the form with valid values after the pickers are answered. */
  fill: (s: Screen) => void;
  /** The one write the valid form sends. */
  write: { method: string; path: string };
}

/** Answers every pending read: known single rows by id, every list with a page of seed rows. */
async function answerReads(s: Screen): Promise<void> {
  await s.settle();
  for (let round = 0; round < 6; round += 1) {
    const pending = s.http.match(() => true);
    if (pending.length === 0) {
      return;
    }
    for (const req of pending) {
      const url = req.request.url.replace(API, '');
      if (url === '/products/PRO1') {
        req.flush(envelope(WATER));
      } else if (url === '/units/UNI1') {
        req.flush(envelope(UNIT_EA));
      } else if (url === '/modifier-groups/GRP1') {
        req.flush(envelope(GROUP));
      } else if (url.startsWith('/categories')) {
        req.flush(pageEnvelope([CATEGORY]));
      } else if (url.startsWith('/brands')) {
        req.flush(pageEnvelope([BRAND]));
      } else if (url.startsWith('/units')) {
        req.flush(pageEnvelope(UNITS));
      } else {
        req.flush(pageEnvelope([]));
      }
    }
    await s.settle();
  }
}

const FORMS: FormCase[] = [
  {
    name: 'brand',
    url: '/catalog/brands/new',
    keys: [P.brands.view, P.brands.manage],
    fill: (s) => {
      s.type('input[formcontrolname="code"]', 'NEW');
      s.type('input[formcontrolname="name"]', 'Newer');
    },
    write: { method: 'POST', path: 'brands' },
  },
  {
    name: 'unit',
    url: '/catalog/units/new',
    keys: [P.units.view, P.units.manage],
    fill: (s) => {
      s.type('input[formcontrolname="code"]', 'BOX');
      s.type('input[formcontrolname="name"]', 'Box');
    },
    write: { method: 'POST', path: 'units' },
  },
  {
    name: 'unit conversion',
    url: '/catalog/units/conversions/new',
    keys: [P.units.view, P.units.manage],
    fill: (s) => {
      s.pick('From unit', 'UNI2');
      s.pick('To unit', 'UNI1');
      s.type('input[formcontrolname="factor"]', '24');
    },
    write: { method: 'POST', path: 'unit-conversions' },
  },
  {
    name: 'product',
    url: '/catalog/products/new',
    keys: [P.products.view, P.products.manage],
    fill: (s) => {
      s.type('input[formcontrolname="sku"]', 'NEW-1');
      s.type('input[formcontrolname="name"]', 'New thing');
      s.pick('Category', 'CAT1');
      s.pick('Stock unit', 'UNI1');
    },
    write: { method: 'POST', path: 'products' },
  },
  {
    name: 'barcode',
    url: '/catalog/products/PRO1/barcodes/new',
    keys: [P.products.view, P.barcodes.view, P.barcodes.manage],
    fill: (s) => s.type('input[formcontrolname="barcode"]', '4006381333931'),
    write: { method: 'POST', path: 'products/PRO1/barcodes' },
  },
  {
    name: 'modifier group',
    url: '/catalog/modifiers/new',
    keys: [P.modifiers.view, P.modifiers.manage],
    fill: (s) => s.type('input[formcontrolname="name"]', 'Toppings'),
    write: { method: 'POST', path: 'modifier-groups' },
  },
  {
    name: 'modifier',
    url: '/catalog/modifiers/GRP1/modifiers/new',
    keys: [P.modifiers.view, P.modifiers.manage],
    fill: (s) => s.type('input[formcontrolname="name"]', 'Extra cheese'),
    write: { method: 'POST', path: 'modifier-groups/GRP1/modifiers' },
  },
];

describe('create forms are double-submit safe (T-036 AC-18, QA)', () => {
  let s: Screen;

  beforeEach(() => {
    s = new Screen();
  });
  afterEach(() => s.http.verify());

  it.each(FORMS)('$name form: a second submit while in flight sends nothing', async (form) => {
    await s.open(form.url, form.keys);
    await answerReads(s);
    form.fill(s);
    s.submit();
    const writes = s.requests(form.write.method, form.write.path);
    expect(writes).toHaveLength(1);
    s.submit();
    s.submit();
    expect(s.requests(form.write.method, form.write.path)).toHaveLength(0);
    s.http.expectNone(() => true);
    const save = s.field<HTMLButtonElement>('button[type="submit"]');
    expect(save.disabled).toBe(true);
    expect(save.textContent).toContain('Saving');
    writes[0].flush(envelope({ id: 'NEW9' }));
    await answerReads(s);
  });

  it.each(FORMS)(
    '$name form: the form can be submitted again after a refused attempt',
    async (form) => {
      await s.open(form.url, form.keys);
      await answerReads(s);
      form.fill(s);
      s.submit();
      s.one(form.write.method, form.write.path).flush(
        {
          success: false,
          data: null,
          message: 'Refused.',
          errors: [{ code: 'catalog.duplicate' }],
          meta: { request_id: 'r' },
        },
        { status: 422, statusText: 'Unprocessable' },
      );
      await s.settle();
      const save = s.field<HTMLButtonElement>('button[type="submit"]');
      expect(save.disabled).toBe(false);
      s.submit();
      expect(s.requests(form.write.method, form.write.path)).toHaveLength(1);
    },
  );
});
