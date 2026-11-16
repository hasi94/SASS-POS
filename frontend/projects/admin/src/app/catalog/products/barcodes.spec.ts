import { CATALOG_PERMISSIONS } from '@flexipos/shared';
import { envelope, pageEnvelope, refusal } from '../../crud/testing/crud-testing';
import { BARCODE_1, BARCODE_2, Screen, UNIT_EA, WATER } from './products-testing';

const P = CATALOG_PERMISSIONS.products;
const B = CATALOG_PERMISSIONS.barcodes;
const VIEW = [P.view, B.view];
const MANAGE = [P.view, B.view, B.manage];

describe('product barcodes (T-036 AC-49 to AC-51)', () => {
  let s: Screen;

  beforeEach(() => {
    s = new Screen();
  });
  afterEach(() => s.http.verify());

  async function openSection(permissions: readonly string[], rows = [BARCODE_1, BARCODE_2]) {
    await s.open('/catalog/products/PRO1', permissions);
    s.one('GET', 'products/PRO1').flush(envelope(WATER));
    await s.settle();
    s.one('GET', 'products/PRO1/barcodes').flush(pageEnvelope(rows));
    await s.settle();
  }

  const cells = (row: number): string[] =>
    Array.from(s.el().querySelectorAll('section tbody tr')[row].querySelectorAll('td')).map(
      (td) => td.textContent?.trim() ?? '',
    );
  const primaryMarkers = (): number =>
    Array.from(s.el().querySelectorAll('section tbody tr')).filter((tr) =>
      Array.from(tr.querySelectorAll('td')).some((td) => td.textContent?.trim() === 'Primary'),
    ).length;

  describe('section on the detail page', () => {
    it('lists barcode, unit or "Stock unit", quantity and the primary marker (AC-49)', async () => {
      await openSection(VIEW);
      const headers = Array.from(s.el().querySelectorAll('section thead th')).map((h) =>
        h.textContent?.trim(),
      );
      expect(headers).toEqual(['Barcode', 'Unit', 'Quantity', 'Primary', 'Actions']);
      expect(cells(0).slice(0, 4)).toEqual(['5012345678900', 'Stock unit', '1.0000', 'Primary']);
      expect(cells(1).slice(0, 4)).toEqual(['5012345678917', 'CASE', '24.0000', '']);
    });

    it('is absent, with no barcode request, without the barcodes view key', async () => {
      await s.open('/catalog/products/PRO1', [P.view]);
      s.one('GET', 'products/PRO1').flush(envelope(WATER));
      await s.settle();
      expect(s.text()).not.toContain('Barcodes');
      s.http.expectNone(() => true);
    });

    it('has no add, edit, delete or primary control without the manage key, and forbids the form routes', async () => {
      await openSection(VIEW);
      expect(s.text()).not.toContain('Add barcode');
      expect(s.field('a[aria-label^="Edit barcode"]')).toBeNull();
      expect(s.field('button[aria-label^="Delete barcode"]')).toBeNull();
      expect(s.field('button[aria-label$="primary"]')).toBeNull();
      await s.harness.navigateByUrl('/catalog/products/PRO1/barcodes/new');
      expect(s.router.url).toBe('/forbidden');
      await s.harness.navigateByUrl('/catalog/products/PRO1/barcodes/BCD1/edit');
      expect(s.router.url).toBe('/forbidden');
      s.http.expectNone(() => true);
    });

    it('forbids the form route for a person with manage but not view', async () => {
      await s.open('/catalog/products/PRO1/barcodes/new', [P.view, B.manage]);
      expect(s.router.url).toBe('/forbidden');
      s.http.expectNone(() => true);
    });

    it('shows the add, edit and delete controls with the manage key', async () => {
      await openSection(MANAGE);
      expect(s.text()).toContain('Add barcode');
      expect(s.field('a[aria-label="Edit barcode 5012345678917"]')).not.toBeNull();
      expect(s.field('button[aria-label="Delete barcode 5012345678917"]')).not.toBeNull();
      // The primary row cannot be made primary again.
      expect(s.field('button[aria-label="Make 5012345678900 primary"]')).toBeNull();
      expect(s.field('button[aria-label="Make 5012345678917 primary"]')).not.toBeNull();
    });

    it('marks another barcode primary, then reloads: the client never clears the old flag (AC-51)', async () => {
      await openSection(MANAGE);
      expect(primaryMarkers()).toBe(1);
      s.field<HTMLButtonElement>('button[aria-label="Make 5012345678917 primary"]').click();
      const put = s.one('PUT', 'products/PRO1/barcodes/BCD2');
      expect(put.request.body).toEqual({ unit_id: 'UNI2', quantity: '24.0000', is_primary: true });
      put.flush(envelope({ ...BARCODE_2, is_primary: true }));
      await s.settle();
      // Until the server's list arrives, the page still shows what it was given.
      expect(primaryMarkers()).toBe(1);
      expect(cells(0)[3]).toBe('Primary');

      s.one('GET', 'products/PRO1/barcodes').flush(
        pageEnvelope([
          { ...BARCODE_1, is_primary: false },
          { ...BARCODE_2, is_primary: true },
        ]),
      );
      await s.settle();
      expect(primaryMarkers()).toBe(1);
      expect(cells(1)[3]).toBe('Primary');
      expect(cells(0)[3]).toBe('');
    });

    it('deletes a barcode after confirmation, and keeps the row when the server refuses', async () => {
      await openSection(MANAGE);
      s.field<HTMLButtonElement>('button[aria-label="Delete barcode 5012345678917"]').click();
      s.harness.detectChanges();
      expect(s.text()).toContain('Delete 5012345678917?');
      s.dialogButton('Delete').click();
      s.one('DELETE', 'products/PRO1/barcodes/BCD2').flush(
        ...refusal(409, 'catalog.in_use', undefined, undefined, 'The barcode is in use.'),
      );
      s.harness.detectChanges();
      expect(s.text()).toContain('The barcode is in use.');
      expect(s.el().querySelectorAll('section tbody tr')).toHaveLength(2);

      s.field<HTMLButtonElement>('button[aria-label="Delete barcode 5012345678917"]').click();
      s.harness.detectChanges();
      s.dialogButton('Delete').click();
      s.one('DELETE', 'products/PRO1/barcodes/BCD2').flush(envelope(null));
      await s.settle();
      s.one('GET', 'products/PRO1/barcodes').flush(pageEnvelope([BARCODE_1]));
      await s.settle();
      expect(s.el().querySelectorAll('section tbody tr')).toHaveLength(1);
    });

    it('shows barcodes of a deleted product without any control', async () => {
      await s.open('/catalog/products/PRO1', [...MANAGE, P.manage]);
      s.one('GET', 'products/PRO1').flush(
        envelope({ ...WATER, deleted_at: '2026-01-01T00:00:00Z' }),
      );
      await s.settle();
      s.one('GET', 'products/PRO1/barcodes').flush(pageEnvelope([BARCODE_1]));
      await s.settle();
      expect(s.el().querySelectorAll('section tbody tr')).toHaveLength(1);
      expect(s.text()).not.toContain('Add barcode');
      expect(s.field('button[aria-label^="Delete barcode"]')).toBeNull();
    });
  });

  describe('barcode form', () => {
    async function openAdd() {
      await s.open('/catalog/products/PRO1/barcodes/new', MANAGE);
      s.one('GET', 'products/PRO1').flush(envelope(WATER));
      await s.settle();
      s.one('GET', 'units/UNI1').flush(envelope(UNIT_EA));
      s.one('GET', 'units').flush(
        pageEnvelope([UNIT_EA, { ...UNIT_EA, id: 'UNI2', code: 'CASE', name: 'Case' }]),
      );
      await s.settle();
    }

    it('blocks a blank barcode and one over 64 characters, sending nothing (AC-50)', async () => {
      await openAdd();
      s.submit();
      expect(s.text()).toContain('Barcode is required.');
      s.type('input[formcontrolname="barcode"]', 'x'.repeat(65));
      s.submit();
      expect(s.text()).toContain('Barcode can be at most 64 characters.');
      s.http.expectNone(() => true);
    });

    it('defaults the quantity to 1 and blocks 0, a negative number and text (AC-50)', async () => {
      await openAdd();
      expect(s.field<HTMLInputElement>('input[formcontrolname="quantity"]').value).toBe('1');
      s.type('input[formcontrolname="barcode"]', '4006381333931');
      for (const bad of ['0', '-2', 'abc', '']) {
        s.type('input[formcontrolname="quantity"]', bad);
        s.submit();
        expect(s.describedBy('input[formcontrolname="quantity"]')).toBe(
          'Quantity must be a number greater than 0.',
        );
      }
      s.http.expectNone(() => true);
    });

    it('offers only units of the stock unit dimension (AC-43)', async () => {
      await openAdd();
      expect(s.optionTexts('Unit')).toEqual(['Stock unit', 'EA - Each', 'CASE - Case']);
      // A mass unit would be hidden by the same filter.
      const picker = s.pickerSelect('Unit') as HTMLSelectElement;
      expect(Array.from(picker.options).some((o) => o.textContent?.includes('KG'))).toBe(false);
    });

    it('sends the new barcode and returns to the product (AC-50)', async () => {
      await openAdd();
      s.type('input[formcontrolname="barcode"]', ' 4006381333931 ');
      s.pick('Unit', 'UNI2');
      s.type('input[formcontrolname="quantity"]', '12');
      s.check('is_primary', true);
      s.submit();
      const req = s.one('POST', 'products/PRO1/barcodes');
      expect(req.request.body).toEqual({
        barcode: '4006381333931',
        unit_id: 'UNI2',
        quantity: '12',
        is_primary: true,
      });
      req.flush(envelope({ ...BARCODE_2, id: 'BCD9' }));
      await s.settle();
      expect(s.router.url).toBe('/catalog/products/PRO1');
      s.one('GET', 'products/PRO1').flush(envelope(WATER));
      await s.settle();
      s.one('GET', 'products/PRO1/barcodes').flush(pageEnvelope([BARCODE_1]));
    });

    it('sends the stock unit as null and the default quantity', async () => {
      await openAdd();
      s.type('input[formcontrolname="barcode"]', '77');
      s.submit();
      expect(s.one('POST', 'products/PRO1/barcodes').request.body).toEqual({
        barcode: '77',
        unit_id: null,
        quantity: '1',
        is_primary: false,
      });
    });

    it('shows a duplicate refusal naming the owner on the barcode field, keeping the typed values (AC-50)', async () => {
      await openAdd();
      s.type('input[formcontrolname="barcode"]', '5012345678900');
      s.submit();
      const detail = 'Barcode 5012345678900 already belongs to product Spring water (WATER-500).';
      s.one('POST', 'products/PRO1/barcodes').flush(
        ...refusal(422, 'catalog.duplicate', 'barcode', detail),
      );
      s.harness.detectChanges();
      expect(s.describedBy('input[formcontrolname="barcode"]')).toBe(detail);
      expect(s.field<HTMLInputElement>('input[formcontrolname="barcode"]').value).toBe(
        '5012345678900',
      );
    });

    it('shows the barcode read-only on edit and never sends it (AC-49)', async () => {
      await s.open('/catalog/products/PRO1/barcodes/BCD2/edit', MANAGE);
      s.one('GET', 'products/PRO1').flush(envelope(WATER));
      await s.settle();
      s.one('GET', 'units/UNI1').flush(envelope(UNIT_EA));
      s.one('GET', 'products/PRO1/barcodes/BCD2').flush(envelope(BARCODE_2));
      await s.settle();
      s.one('GET', 'units').flush(pageEnvelope([UNIT_EA]));
      await s.settle();
      const input = s.field<HTMLInputElement>('input[formcontrolname="barcode"]');
      expect(input.readOnly).toBe(true);
      expect(input.value).toBe('5012345678917');
      expect(s.text()).toContain('cannot be changed');
      s.type('input[formcontrolname="quantity"]', '6');
      s.submit();
      const req = s.one('PUT', 'products/PRO1/barcodes/BCD2');
      expect(req.request.body).toEqual({ unit_id: 'UNI2', quantity: '6', is_primary: false });
      expect(Object.keys(req.request.body)).not.toContain('barcode');
    });
  });
});
