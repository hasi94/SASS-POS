import { TestBed } from '@angular/core/testing';
import { ApiError, SalesApi } from '@flexipos/shared';
import { throwError } from 'rxjs';
import { SaleStore } from '../../state/sale-store';
import {
  allById,
  byId,
  clickId,
  scan,
  serverSales,
  settleFully,
  storeOf,
} from '../../testing/sale-screen-helpers';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from '../../testing/till-harness';

function tileNames(h: TillHarness): string[] {
  return allById(h, 'tile').map((t) => t.querySelector('.name')?.textContent?.trim() ?? '');
}

function tileFor(h: TillHarness, name: string): HTMLElement {
  const tile = allById(h, 'tile').find(
    (t) => t.querySelector('.name')?.textContent?.trim() === name,
  );
  if (!tile) {
    throw new Error(`No tile named ${name}`);
  }
  return tile;
}

describe('ProductGrid (AC-16, AC-17, AC-19, AC-20, AC-85)', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('shows the first page of 50 sellable products with the list price labelled as such (AC-16, AC-17)', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    expect(allById(h, 'tile')).toHaveLength(50);
    const rice = tileFor(h, 'Rice 1kg');
    expect(rice.textContent).toContain('List price');
    expect(rice.textContent).toContain('250.00 LKR');
  });

  it('shows the categories as tabs, All first (AC-16)', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const tabs = Array.from(
      h.host.querySelectorAll('[role="group"][aria-label="Categories"] button'),
    );
    expect(tabs.map((t) => t.textContent?.trim())).toEqual([
      'All',
      'Grocery',
      'Drinks',
      'Household',
    ]);
    expect(byId(h, 'category-all').getAttribute('aria-pressed')).toBe('true');
  });

  it('filters by category through the server', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const drinks = Array.from(h.host.querySelectorAll('[role="group"] button')).find(
      (b) => b.textContent?.trim() === 'Drinks',
    ) as HTMLElement;
    drinks.click();
    await h.settle();
    expect(tileNames(h)).toEqual(['Mineral Water 500ml']);
    expect(drinks.getAttribute('aria-pressed')).toBe('true');
    expect(byId(h, 'category-all').getAttribute('aria-pressed')).toBe('false');
  });

  it('loads the next page on Load more and never asks for the whole catalogue (AC-17)', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const products = vi.spyOn(TestBed.inject(SalesApi), 'products');
    await clickId(h, 'load-more');
    expect(allById(h, 'tile')).toHaveLength(69);
    expect(h.query('[data-testid="load-more"]')).toBeNull();
    expect(products).toHaveBeenCalledTimes(1);
    expect(products.mock.calls[0]?.[0]).toMatchObject({ limit: 50, cursor: 'c50' });
  });

  it('loads the next page when the scroll sentinel comes into view (AC-17)', async () => {
    let notify: (entries: { isIntersecting: boolean }[]) => void = () => undefined;
    vi.stubGlobal(
      'IntersectionObserver',
      class {
        constructor(callback: (entries: { isIntersecting: boolean }[]) => void) {
          notify = callback;
        }
        observe(): void {}
        disconnect(): void {}
      },
    );
    const h = await setupTill({ given: ['shift_open'] });
    expect(allById(h, 'tile')).toHaveLength(50);
    notify([{ isIntersecting: false }]);
    await h.settle();
    expect(allById(h, 'tile')).toHaveLength(50);
    notify([{ isIntersecting: true }]);
    await h.settle();
    expect(allById(h, 'tile')).toHaveLength(69);
  });

  it('lists only what the server marks sellable, and a variant parent as its variants (AC-85)', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const household = Array.from(h.host.querySelectorAll('[role="group"] button')).find(
      (b) => b.textContent?.trim() === 'Household',
    ) as HTMLElement;
    household.click();
    await h.settle();
    const names = tileNames(h);
    expect(names).toContain('Tee Shirt S');
    expect(names).toContain('Tee Shirt M');
    expect(names).not.toContain('Tee Shirt');
    expect(names).not.toContain('Retired Item');
    expect(names).not.toContain('Display Model');
  });

  it('asks for nothing until a shift is open, and says so', async () => {
    const h = await setupTill();
    expect(allById(h, 'tile')).toHaveLength(0);
    expect(h.text('grid-closed')).toBe('Open a shift to see products');
  });

  describe('keyboard (AC-19)', () => {
    it('keeps one tile in the tab order and moves it with the arrow keys', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const tiles = allById(h, 'tile');
      expect(tiles.filter((t) => t.getAttribute('tabindex') === '0')).toHaveLength(1);
      expect(tiles[0]?.getAttribute('tabindex')).toBe('0');
      tiles[0]?.focus();
      const press = (key: string) => h.press(key);
      expect(press('ArrowRight').defaultPrevented).toBe(true);
      expect(document.activeElement).toBe(tiles[1]);
      press('ArrowDown');
      expect(document.activeElement).toBe(tiles[2]);
      press('ArrowLeft');
      press('ArrowUp');
      expect(document.activeElement).toBe(tiles[0]);
      press('End');
      expect(document.activeElement).toBe(tiles[49]);
      press('Home');
      expect(document.activeElement).toBe(tiles[0]);
      await h.settle();
      expect(allById(h, 'tile').filter((t) => t.getAttribute('tabindex') === '0')).toEqual([
        tiles[0],
      ]);
    });

    it('does not let the arrows on a tile move the cart selection', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      const store = storeOf(h, SaleStore);
      store.load(serverSales(h, HARNESS_TENANT_ID, 'BR001')[0]!);
      await h.settle();
      allById(h, 'tile')[0]?.focus();
      h.press('ArrowDown');
      expect(store.selectedLineId()).toBeNull();
    });

    it('adds the focused product with quantity 1 on Enter', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const rice = tileFor(h, 'Rice 1kg');
      rice.focus();
      const event = h.press('Enter');
      expect(event.defaultPrevented).toBe(true);
      await settleFully(h);
      const lines = storeOf(h, SaleStore).lines();
      expect(lines).toHaveLength(1);
      expect(lines[0]?.product_name).toBe('Rice 1kg');
      expect(lines[0]?.quantity).toBe('1.0000');
      expect(document.activeElement).toBe(byId(h, 'scan-field'));
    });

    it('adds on a click too', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      tileFor(h, 'Laundry Soap').click();
      await h.settle();
      expect(storeOf(h, SaleStore).lines()[0]?.product_name).toBe('Laundry Soap');
    });

    it('opens the weight prompt for a weighable product instead of adding it (AC-19, AC-26)', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const household = Array.from(h.host.querySelectorAll('[role="group"] button')).find(
        (b) => b.textContent?.trim() === 'Grocery',
      ) as HTMLElement;
      household.click();
      await h.settle();
      tileFor(h, 'Loose Tomatoes').focus();
      h.press('Enter');
      await h.settle();
      expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Weight');
      expect(storeOf(h, SaleStore).lineCount()).toBe(0);
    });
  });

  describe('when the catalogue cannot be read (AC-20)', () => {
    it('shows an error with Retry while the scan field and the cart still work', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const api = TestBed.inject(SalesApi);
      const real = api.products.bind(api);
      const products = vi
        .spyOn(api, 'products')
        .mockReturnValueOnce(throwError(() => new ApiError(500, 'Server error', [], 'req-1')));
      // A category tab asks the server for a fresh first page.
      const grocery = Array.from(h.host.querySelectorAll('[role="group"] button')).find(
        (b) => b.textContent?.trim() === 'Grocery',
      ) as HTMLElement;
      grocery.click();
      await h.settle();
      expect(products).toHaveBeenCalled();
      expect(h.text('grid-error')).toContain('Something went wrong');
      expect(allById(h, 'tile')).toHaveLength(0);

      // Scanning still works with the grid down.
      products.mockImplementation(real);
      await scan(h, '4790000000011');
      expect(storeOf(h, SaleStore).lineCount()).toBe(1);

      await clickId(h, 'grid-retry');
      expect(h.query('[data-testid="grid-error"]')).toBeNull();
      expect(allById(h, 'tile').length).toBeGreaterThan(0);
    });
  });
});
