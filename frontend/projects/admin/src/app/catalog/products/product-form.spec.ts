import { CATALOG_PERMISSIONS, Product } from '@flexipos/shared';
import { envelope, pageEnvelope, refusal } from '../../crud/testing/crud-testing';
import { API, Screen, UNITS, WATER } from './products-testing';

const P = CATALOG_PERMISSIONS.products;
const MANAGE = [P.view, P.manage];

describe('product form (T-036 AC-39 to AC-46, AC-48)', () => {
  let s: Screen;

  beforeEach(() => {
    s = new Screen();
  });
  afterEach(() => s.http.verify());

  /** The id of the element the refusal text hangs on, for a picker. */
  const pickerMessage = (label: string): string | null => {
    const id = s.pickerSelect(label)?.getAttribute('aria-describedby');
    return id ? (s.el().querySelector(`#${id}`)?.textContent?.trim() ?? null) : null;
  };

  async function openCreate(url = '/catalog/products/new') {
    await s.open(url, MANAGE);
    await s.answerPickers();
  }

  /** A valid simple product: SKU, name, category and stock unit. */
  function fillValid(): void {
    s.type('input[formcontrolname="sku"]', 'NEW-1');
    s.type('input[formcontrolname="name"]', 'New thing');
    s.pick('Category', 'CAT1');
    s.pick('Stock unit', 'UNI1');
  }

  const SIMPLE_BODY = {
    type: 'simple',
    sku: 'NEW-1',
    name: 'New thing',
    category_id: 'CAT1',
    brand_id: null,
    stock_unit_id: 'UNI1',
    purchase_unit_id: null,
    sales_unit_id: null,
    is_weighable: false,
    price_mode: 'per_unit',
    track_stock: true,
    tracking_mode: 'quantity',
  };

  describe('create', () => {
    it('blocks each missing required field by name and sends nothing (AC-39)', async () => {
      await openCreate();
      s.submit();
      for (const message of [
        'SKU is required.',
        'Name is required.',
        'Category is required.',
        'Stock unit is required.',
      ]) {
        expect(s.text()).toContain(message);
      }
      expect(s.describedBy('input[formcontrolname="sku"]')).toBe('SKU is required.');
      s.http.expectNone(() => true);
    });

    it('blocks a 51-character SKU and allows exactly 50', async () => {
      await openCreate();
      fillValid();
      s.type('input[formcontrolname="sku"]', 'x'.repeat(51));
      s.submit();
      expect(s.text()).toContain('SKU can be at most 50 characters.');
      s.http.expectNone(() => true);
      s.type('input[formcontrolname="sku"]', 'x'.repeat(50));
      s.submit();
      expect(s.one('POST', 'products').request.body.sku).toHaveLength(50);
    });

    it('sends the plain simple product, with no parent_id, no plu_code and no JSON keys', async () => {
      await openCreate();
      fillValid();
      s.submit();
      const req = s.one('POST', 'products');
      expect(req.request.body).toEqual(SIMPLE_BODY);
      expect(Object.keys(req.request.body)).not.toContain('parent_id');
      expect(Object.keys(req.request.body)).not.toContain('plu_code');
      req.flush(envelope({ ...WATER, id: 'PRO9' }));
      await s.settle();
      expect(s.router.url).toBe('/catalog/products');
      s.one('GET', 'products').flush(pageEnvelope([WATER]));
    });

    it('shows a parent picker for a variant that asks for variant parents only (AC-40)', async () => {
      await openCreate();
      expect(s.pickerSelect('Parent product')).toBeNull();
      s.selectNative('type', 'variant');
      const asked = s.http.expectOne(
        (r) => r.url === `${API}/products` && r.params.get('type') === 'variant_parent',
      );
      expect(asked.request.params.get('per_page')).toBe('25');
      asked.flush(pageEnvelope([{ ...WATER, id: 'PRO2', sku: 'TEE', name: 'T-shirt' }]));
      await s.settle();
      expect(s.pickerSelect('Parent product')).not.toBeNull();
    });

    it('requires a parent for a variant, and sends it with the variant', async () => {
      await openCreate();
      fillValid();
      s.selectNative('type', 'variant');
      await s.answerPickers();
      s.submit();
      expect(s.text()).toContain('Parent product is required for a variant.');
      s.http.expectNone(() => true);
      s.pick('Parent product', 'PRO2');
      s.submit();
      expect(s.one('POST', 'products').request.body).toMatchObject({
        type: 'variant',
        parent_id: 'PRO2',
      });
    });

    it.each(['simple', 'variant_parent', 'service', 'bundle', 'composite'])(
      'has no parent picker and sends no parent_id for type %s, even after a variant was chosen first',
      async (type) => {
        await openCreate();
        fillValid();
        s.selectNative('type', 'variant');
        await s.answerPickers();
        s.pick('Parent product', 'PRO2');
        s.selectNative('type', type);
        expect(s.pickerSelect('Parent product')).toBeNull();
        s.submit();
        const body = s.one('POST', 'products').request.body;
        expect(body.type).toBe(type);
        expect(Object.keys(body)).not.toContain('parent_id');
      },
    );

    it('shows a wrong-parent refusal on the parent field (AC-40)', async () => {
      await openCreate();
      fillValid();
      s.selectNative('type', 'variant');
      await s.answerPickers();
      s.pick('Parent product', 'PRO2');
      s.submit();
      s.one('POST', 'products').flush(
        ...refusal(
          422,
          'kernel.validation_failed',
          'parent_id',
          'The parent must be a variant parent product.',
        ),
      );
      s.harness.detectChanges();
      expect(pickerMessage('Parent product')).toBe('The parent must be a variant parent product.');
      expect(s.field<HTMLInputElement>('input[formcontrolname="sku"]').value).toBe('NEW-1');
    });

    it('blocks variant JSON that is not JSON, and sends valid JSON parsed (AC-41)', async () => {
      await openCreate();
      fillValid();
      s.selectNative('type', 'variant_parent');
      s.type('textarea[formcontrolname="variant_options"]', '{not json');
      s.submit();
      expect(s.text()).toContain('Variant options must be valid JSON.');
      s.http.expectNone(() => true);
      s.type('textarea[formcontrolname="variant_options"]', '["size", "colour"]');
      s.type('textarea[formcontrolname="variant_values"]', '{"size": "S"}');
      s.submit();
      const body = s.one('POST', 'products').request.body;
      expect(body.variant_options).toEqual(['size', 'colour']);
      expect(body.variant_values).toEqual({ size: 'S' });
    });

    it('offers only units of the stock unit dimension for purchase and sales (AC-43)', async () => {
      await openCreate();
      // Nothing chosen yet: every unit is offered.
      expect(s.optionTexts('Purchase unit')).toContain('KG - Kilogram');
      s.pick('Stock unit', 'UNI1');
      for (const label of ['Purchase unit', 'Sales unit']) {
        const options = s.optionTexts(label);
        expect(options).toEqual(['Same as stock unit', 'EA - Each', 'CASE - Case']);
      }
      s.pick('Stock unit', 'UNI3');
      expect(s.optionTexts('Sales unit')).toEqual(['Same as stock unit', 'KG - Kilogram']);
      expect(UNITS).toHaveLength(3);
    });

    it('sends the chosen units, and null for the empty choice (AC-43)', async () => {
      await openCreate();
      fillValid();
      s.pick('Purchase unit', 'UNI2');
      s.submit();
      const body = s.one('POST', 'products').request.body;
      expect(body.purchase_unit_id).toBe('UNI2');
      expect(body.sales_unit_id).toBeNull();
    });

    it('shows the server dimension refusal on the unit field (AC-43)', async () => {
      await openCreate();
      fillValid();
      s.pick('Sales unit', 'UNI2');
      s.submit();
      s.one('POST', 'products').flush(
        ...refusal(
          422,
          'catalog.dimension_mismatch',
          'sales_unit_id',
          'CASE is count but the stock unit KG is mass.',
        ),
      );
      s.harness.detectChanges();
      expect(pickerMessage('Sales unit')).toContain('CASE is count');
    });

    it('blocks an 11-character PLU and sends no key for a blank one (AC-44)', async () => {
      await openCreate();
      fillValid();
      s.type('input[formcontrolname="plu_code"]', '12345678901');
      s.submit();
      expect(s.text()).toContain('PLU code can be at most 10 characters.');
      s.http.expectNone(() => true);
      s.type('input[formcontrolname="plu_code"]', '   ');
      s.submit();
      expect(Object.keys(s.one('POST', 'products').request.body)).not.toContain('plu_code');
    });

    it('shows a duplicate-PLU refusal on the PLU field and keeps what was typed (AC-44)', async () => {
      await openCreate();
      fillValid();
      s.type('input[formcontrolname="plu_code"]', '4011');
      s.submit();
      const detail = 'PLU 4011 is already used by product Apples (APPLES-KG).';
      s.one('POST', 'products').flush(...refusal(422, 'catalog.duplicate', 'plu_code', detail));
      s.harness.detectChanges();
      expect(s.describedBy('input[formcontrolname="plu_code"]')).toBe(detail);
      expect(s.field<HTMLInputElement>('input[formcontrolname="plu_code"]').value).toBe('4011');
    });

    it.each([
      [false, 'per_unit', true, 'quantity'],
      [true, 'per_weight', true, 'batch'],
      [true, 'per_unit', false, 'none'],
      [false, 'per_weight', false, 'serial'],
      [false, 'open_price', true, 'none'],
      [true, 'open_price', false, 'serial'],
    ])(
      'submits weighable=%s, price mode %s, track stock=%s, tracking %s unchanged (AC-45)',
      async (weighable, priceMode, track, tracking) => {
        await openCreate();
        fillValid();
        s.check('is_weighable', weighable);
        s.selectNative('price_mode', priceMode);
        s.check('track_stock', track);
        s.selectNative('tracking_mode', tracking);
        s.submit();
        expect(s.one('POST', 'products').request.body).toMatchObject({
          is_weighable: weighable,
          price_mode: priceMode,
          track_stock: track,
          tracking_mode: tracking,
        });
      },
    );
  });

  describe('edit', () => {
    async function openEdit(product: Product = WATER) {
      await s.open(`/catalog/products/${product.id}/edit`, MANAGE);
      s.one('GET', `products/${product.id}`).flush(envelope(product));
      await s.settle();
      for (const unit of UNITS) {
        for (const r of s.requests('GET', `units/${unit.id}`)) r.flush(envelope(unit));
      }
      await s.answerPickers();
    }

    it('shows the stock unit read-only with the reason, and never sends it (AC-42)', async () => {
      await openEdit();
      expect(s.text()).toContain('Stock unit: EA');
      expect(s.text()).toContain('cannot be changed');
      expect(s.pickerSelect('Stock unit')).toBeNull();
      s.type('input[formcontrolname="name"]', 'Spring water 2');
      s.submit();
      const req = s.one('PUT', 'products/PRO1');
      expect(Object.keys(req.request.body)).not.toContain('stock_unit_id');
      // The loaded version travels (AC-46); an unchanged PLU is not sent (AC-44).
      expect(req.request.body).toEqual({
        type: 'simple',
        sku: 'WATER-500',
        name: 'Spring water 2',
        category_id: 'CAT1',
        brand_id: 'BRA1',
        purchase_unit_id: 'UNI2',
        sales_unit_id: null,
        is_weighable: false,
        price_mode: 'per_unit',
        track_stock: true,
        tracking_mode: 'quantity',
        version: 3,
      });
      req.flush(envelope({ ...WATER, version: 4 }));
      await s.settle();
      expect(s.router.url).toBe('/catalog/products');
      s.one('GET', 'products').flush(pageEnvelope([WATER]));
    });

    it('shows the loaded purchase unit by its code', async () => {
      await openEdit();
      const select = s.pickerSelect('Purchase unit') as HTMLSelectElement;
      expect(select.value).toBe('UNI2');
      expect(select.selectedOptions[0].textContent).toContain('CASE');
    });

    it('displays a refusal of the stock unit as sent (AC-42)', async () => {
      await openEdit();
      s.submit();
      s.one('PUT', 'products/PRO1').flush(
        ...refusal(
          422,
          'catalog.immutable',
          'stock_unit_id',
          'The stock unit of a product cannot change.',
        ),
      );
      s.harness.detectChanges();
      expect(s.field('#product-stock-unit-readonly')?.getAttribute('aria-describedby')).toBe(
        'product-stock_unit_id-error',
      );
      expect(s.field('#product-stock_unit_id-error')?.textContent).toContain(
        'The stock unit of a product cannot change.',
      );
    });

    it('keeps typed values on a stale refusal until Reload is pressed (AC-46)', async () => {
      await openEdit();
      s.type('input[formcontrolname="name"]', 'My edit');
      s.submit();
      s.one('PUT', 'products/PRO1').flush(
        ...refusal(
          409,
          'catalog.stale_version',
          undefined,
          undefined,
          'This product was changed by someone else.',
        ),
      );
      s.harness.detectChanges();
      expect(s.text()).toContain('changed by someone else');
      expect(s.button('Reload')).toBeDefined();
      expect(s.field<HTMLInputElement>('input[formcontrolname="name"]').value).toBe('My edit');
      s.http.expectNone(() => true);

      (s.button('Reload') as HTMLButtonElement).click();
      s.one('GET', 'products/PRO1').flush(envelope({ ...WATER, name: 'Server name', version: 4 }));
      await s.settle();
      await s.answerPickers();
      expect(s.field<HTMLInputElement>('input[formcontrolname="name"]').value).toBe('Server name');
      expect(s.text()).not.toContain('changed by someone else');

      s.submit();
      expect(s.one('PUT', 'products/PRO1').request.body.version).toBe(4);
    });

    it('sends plu_code null when an existing PLU is cleared, and the new text when changed (AC-44)', async () => {
      await openEdit();
      s.type('input[formcontrolname="plu_code"]', '');
      s.submit();
      const cleared = s.one('PUT', 'products/PRO1');
      expect(cleared.request.body.plu_code).toBeNull();
      expect(Object.keys(cleared.request.body)).toContain('plu_code');
      cleared.flush(...refusal(500, 'kernel.error'));
      s.harness.detectChanges();
      s.type('input[formcontrolname="plu_code"]', '9999');
      s.submit();
      expect(s.one('PUT', 'products/PRO1').request.body.plu_code).toBe('9999');
    });

    it('opens a soft-deleted product read-only: badge, disabled fields, no Save (AC-48)', async () => {
      await openEdit({ ...WATER, deleted_at: '2026-01-01T00:00:00Z' });
      expect(s.field('.adm-badge')?.textContent).toContain('Deleted');
      expect(s.field('button[type="submit"]')).toBeNull();
      expect(s.field<HTMLInputElement>('input[formcontrolname="sku"]').disabled).toBe(true);
      s.submit();
      s.http.expectNone(() => true);
    });
  });
});
