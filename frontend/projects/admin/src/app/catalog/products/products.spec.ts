import { CATALOG_PERMISSIONS } from '@flexipos/shared';
import { envelope, pageEnvelope, refusal } from '../../crud/testing/crud-testing';
import { API, BARCODE_1, Screen, TEE, TEE_S, WATER } from './products-testing';

const P = CATALOG_PERMISSIONS.products;
const B = CATALOG_PERMISSIONS.barcodes;

describe('products list, detail and variants (T-036 AC-7, AC-20, AC-38, AC-41, AC-47, AC-48)', () => {
  let s: Screen;

  beforeEach(() => {
    s = new Screen();
  });
  afterEach(() => {
    vi.useRealTimers();
    s.http.verify();
  });

  async function openList(permissions: readonly string[]) {
    await s.open('/catalog/products', permissions);
    s.one('GET', 'products').flush(pageEnvelope([WATER, TEE]));
    await s.settle();
  }

  describe('list', () => {
    it('shows SKU, name, type, category, brand, stock unit and active state', async () => {
      await openList([P.view]);
      const headers = Array.from(s.el().querySelectorAll('th')).map((h) => h.textContent?.trim());
      expect(headers).toEqual(
        expect.arrayContaining([
          'SKU',
          'Name',
          'Type',
          'Category',
          'Brand',
          'Stock unit',
          'Active',
        ]),
      );
      const rows = Array.from(s.el().querySelectorAll('tbody tr'));
      expect(rows).toHaveLength(2);
      const first = rows[0].textContent ?? '';
      for (const part of ['WATER-500', 'Spring water', 'simple', 'Drinks', 'Acme', 'EA', 'Yes']) {
        expect(first).toContain(part);
      }
    });

    it('sends the typed text as search=, once the typing pauses (AC-38)', async () => {
      await openList([P.view]);
      vi.useFakeTimers();
      s.type('input[type="search"]', 'tee');
      vi.advanceTimersByTime(300);
      vi.useRealTimers();
      const req = s.http.expectOne((r) => r.url === `${API}/products`);
      expect(req.request.params.get('search')).toBe('tee');
      expect(req.request.params.get('page')).toBe('1');
      req.flush(pageEnvelope([TEE]));
      await s.settle();
      expect(s.el().querySelectorAll('tbody tr')).toHaveLength(1);
    });

    it('has no manage control in the DOM with view only, and forbids the form routes (AC-7)', async () => {
      await openList([P.view]);
      expect(s.text()).not.toContain('New product');
      expect(s.field('button[aria-label^="Delete"]')).toBeNull();
      expect(s.field('a[aria-label^="Edit"]')).toBeNull();
      await s.harness.navigateByUrl('/catalog/products/new');
      expect(s.router.url).toBe('/forbidden');
      await s.harness.navigateByUrl('/catalog/products/PRO1/edit');
      expect(s.router.url).toBe('/forbidden');
      s.http.expectNone(() => true);
    });

    it('shows the manage controls with the manage key', async () => {
      await openList([P.view, P.manage]);
      expect(s.text()).toContain('New product');
      expect(s.field('a[aria-label="Edit Spring water"]')).not.toBeNull();
      expect(s.field('button[aria-label="Delete Spring water"]')).not.toBeNull();
    });

    it('shows the permission message for a 403 and stays signed in (AC-8)', async () => {
      await s.open('/catalog/products', [P.view]);
      s.one('GET', 'products').flush(...refusal(403, 'kernel.forbidden'));
      await s.settle();
      expect(s.text()).toContain('You do not have permission to see or change this.');
      expect(s.router.url).toBe('/catalog/products');
    });

    it('deletes once after confirmation, then reloads the list', async () => {
      await openList([P.view, P.manage]);
      s.field<HTMLButtonElement>('button[aria-label="Delete Spring water"]').click();
      s.harness.detectChanges();
      expect(s.text()).toContain('Delete Spring water?');
      const confirm = s.dialogButton('Delete');
      confirm.click();
      confirm.click();
      s.one('DELETE', 'products/PRO1').flush(envelope(null));
      await s.settle();
      s.one('GET', 'products').flush(pageEnvelope([TEE]));
      await s.settle();
      expect(s.el().querySelectorAll('tbody tr')).toHaveLength(1);
    });

    it('cancelling the delete question sends nothing', async () => {
      await openList([P.view, P.manage]);
      s.field<HTMLButtonElement>('button[aria-label="Delete Spring water"]').click();
      s.harness.detectChanges();
      s.dialogButton('Cancel').click();
      s.harness.detectChanges();
      expect(s.field('[role="dialog"]')).toBeNull();
      s.http.expectNone(() => true);
    });

    it.each([
      [
        'variants',
        'Product TEE cannot be deleted: it has the active variant TEE-S.',
        'Delete T-shirt',
      ],
      [
        'a modifier link',
        'Product WATER-500 cannot be deleted: the modifier Extra shot is linked to it.',
        'Delete Spring water',
      ],
    ])(
      'keeps the row and shows the server message when %s block the delete (AC-47)',
      async (_n, message, label) => {
        await openList([P.view, P.manage]);
        s.field<HTMLButtonElement>(`button[aria-label="${label}"]`).click();
        s.harness.detectChanges();
        s.dialogButton('Delete').click();
        const id = label.includes('T-shirt') ? 'PRO2' : 'PRO1';
        s.one('DELETE', `products/${id}`).flush(
          ...refusal(409, 'catalog.in_use', undefined, undefined, message),
        );
        s.harness.detectChanges();
        expect(s.text()).toContain(message);
        expect(s.el().querySelectorAll('tbody tr')).toHaveLength(2);
        s.http.expectNone(() => true);
      },
    );
  });

  describe('detail and variants', () => {
    async function openDetail(id: string, permissions: readonly string[]) {
      await s.open(`/catalog/products/${id}`, permissions);
    }

    it('shows the product fields and no variants section for a simple product', async () => {
      await openDetail('PRO1', [P.view]);
      s.one('GET', 'products/PRO1').flush(envelope(WATER));
      await s.settle();
      expect(s.text()).toContain('Spring water');
      expect(s.text()).toContain('WATER-500');
      expect(s.text()).toContain('EA');
      expect(s.text()).not.toContain('Variants');
      expect(s.text()).not.toContain('Barcodes');
      s.http.expectNone(() => true);
    });

    it('lists the variants of a variant parent, found by parent_id (AC-41)', async () => {
      await openDetail('PRO2', [P.view, P.manage]);
      s.one('GET', 'products/PRO2').flush(envelope(TEE));
      await s.settle();
      const req = s.http.expectOne(
        (r) => r.url === `${API}/products` && r.params.get('parent_id') === 'PRO2',
      );
      req.flush(pageEnvelope([TEE_S]));
      await s.settle();
      expect(s.text()).toContain('Variants');
      const rows = Array.from(s.el().querySelectorAll('section tbody tr'));
      expect(rows).toHaveLength(1);
      expect(rows[0].textContent).toContain('TEE-S');
    });

    it('links to a new variant with the parent preset, and the form opens with it (AC-41)', async () => {
      await openDetail('PRO2', [P.view, P.manage]);
      s.one('GET', 'products/PRO2').flush(envelope(TEE));
      await s.settle();
      s.http.expectOne((r) => r.params.get('parent_id') === 'PRO2').flush(pageEnvelope([TEE_S]));
      await s.settle();
      const link = Array.from(s.el().querySelectorAll('a')).find(
        (a) => a.textContent?.trim() === 'New variant',
      ) as HTMLAnchorElement;
      const href = link.getAttribute('href') ?? '';
      expect(href).toContain('/catalog/products/new');
      expect(href).toContain('type=variant');
      expect(href).toContain('parent_id=PRO2');

      await s.harness.navigateByUrl(href);
      s.one('GET', 'products/PRO2').flush(envelope(TEE));
      await s.answerPickers();
      expect(s.field<HTMLSelectElement>('select[formcontrolname="type"]').value).toBe('variant');
      expect(s.pickerSelect('Parent product')?.value).toBe('PRO2');
    });

    it('hides the New variant link without the manage key', async () => {
      await openDetail('PRO2', [P.view]);
      s.one('GET', 'products/PRO2').flush(envelope(TEE));
      await s.settle();
      s.http.expectOne((r) => r.params.get('parent_id') === 'PRO2').flush(pageEnvelope([]));
      await s.settle();
      expect(s.text()).not.toContain('New variant');
      expect(s.text()).not.toContain('Edit product');
      expect(s.text()).toContain('Nothing here yet');
    });

    it('shows a deleted product with a Deleted badge and no Edit link (AC-48)', async () => {
      await openDetail('PRO1', [P.view, P.manage, B.view, B.manage]);
      s.one('GET', 'products/PRO1').flush(
        envelope({ ...WATER, deleted_at: '2026-01-01T00:00:00Z' }),
      );
      await s.settle();
      s.one('GET', 'products/PRO1/barcodes').flush(pageEnvelope([]));
      await s.settle();
      expect(s.field('.adm-badge')?.textContent).toContain('Deleted');
      expect(s.text()).not.toContain('Edit product');
      expect(s.text()).not.toContain('Add barcode');
    });

    it('reloads when the id changes: a variant opened from its parent shows the variant (review R-1)', async () => {
      await openDetail('PRO2', [P.view, P.manage, B.view, B.manage]);
      s.one('GET', 'products/PRO2').flush(envelope(TEE));
      await s.settle();
      s.http.expectOne((r) => r.params.get('parent_id') === 'PRO2').flush(pageEnvelope([TEE_S]));
      s.one('GET', 'products/PRO2/barcodes').flush(pageEnvelope([BARCODE_1]));
      await s.settle();
      expect(s.text()).toContain('TEE-S');
      expect(s.text()).toContain(BARCODE_1.barcode);

      const details = s
        .el()
        .querySelector<HTMLAnchorElement>('a[aria-label="Details of T-shirt S"]');
      details?.click();
      await s.settle();
      s.one('GET', 'products/PRO3').flush(envelope(TEE_S));
      await s.settle();
      s.one('GET', 'products/PRO3/barcodes').flush(pageEnvelope([]));
      await s.settle();

      expect(s.router.url).toBe('/catalog/products/PRO3');
      expect(s.field('h1').textContent).toContain('T-shirt S');
      expect(s.text()).toContain('TEE-S');
      expect(s.text()).not.toContain('Variants');
      expect(s.text()).not.toContain(BARCODE_1.barcode);
      const edit = Array.from(s.el().querySelectorAll('a')).find(
        (a) => a.textContent?.trim() === 'Edit product',
      );
      expect(edit?.getAttribute('href')?.endsWith('/PRO3/edit')).toBe(true);
      const add = Array.from(s.el().querySelectorAll('a')).find(
        (a) => a.textContent?.trim() === 'Add barcode',
      );
      expect(add?.getAttribute('href')).toContain('/products/PRO3/barcodes/new');
    });

    it('shows the load failure with Retry, and the permission text for a 403', async () => {
      await openDetail('PRO1', [P.view]);
      s.one('GET', 'products/PRO1').flush(...refusal(403, 'kernel.forbidden'));
      await s.settle();
      expect(s.text()).toContain('You do not have permission to see or change this.');
      expect(s.router.url).toBe('/catalog/products/PRO1');
    });
  });
});
