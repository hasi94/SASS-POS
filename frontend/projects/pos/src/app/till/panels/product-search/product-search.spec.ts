import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Page, SalesApi, TillProduct } from '@flexipos/shared';
import { Observable } from 'rxjs';
import { SaleStore } from '../../state/sale-store';
import { provideTill } from '../../till.providers';
import {
  allById,
  byId,
  serverSales,
  settleFully,
  storeOf,
  typeInto,
} from '../../testing/sale-screen-helpers';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from '../../testing/till-harness';
import { ProductSearch, SEARCH_DEBOUNCE_MS } from './product-search';

function box(h: TillHarness): HTMLInputElement {
  return byId<HTMLInputElement>(h, 'search-field');
}

async function typeAndWait(h: TillHarness, text: string, wait = SEARCH_DEBOUNCE_MS): Promise<void> {
  typeInto(box(h), text);
  await vi.advanceTimersByTimeAsync(wait);
  await settleFully(h);
}

function resultNames(h: TillHarness): string[] {
  return allById(h, 'result').map((r) => r.querySelector('.name')?.textContent?.trim() ?? '');
}

describe('ProductSearch (AC-18)', () => {
  afterEach(() => vi.useRealTimers());

  it('sends one request after a burst of typing, 250 ms after the last key, with limit 50', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const products = vi.spyOn(TestBed.inject(SalesApi), 'products');
    vi.useFakeTimers();
    for (const text of ['r', 'ri', 'ric', 'rice']) {
      typeInto(box(h), text);
      await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS - 50);
    }
    expect(products).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(50);
    await settleFully(h);
    expect(products).toHaveBeenCalledTimes(1);
    expect(products).toHaveBeenCalledWith({ q: 'rice', limit: 50 });
    expect(resultNames(h)).toEqual(['Rice 1kg']);
  });

  it('never sends a request without limit=50, whatever is typed', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const products = vi.spyOn(TestBed.inject(SalesApi), 'products');
    vi.useFakeTimers();
    await typeAndWait(h, 'water');
    await typeAndWait(h, 'soap');
    await typeAndWait(h, '  tee ');
    expect(products.mock.calls.length).toBeGreaterThanOrEqual(3);
    for (const [query] of products.mock.calls) {
      expect(query.limit).toBe(50);
    }
    expect(products.mock.calls[2]?.[0].q).toBe('tee');
  });

  it('cancels the request in flight when a newer search supersedes it', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const asked: string[] = [];
    const cancelled: string[] = [];
    vi.spyOn(TestBed.inject(SalesApi), 'products').mockImplementation(
      (query): Observable<Page<TillProduct>> =>
        new Observable<Page<TillProduct>>(() => {
          asked.push(query.q ?? '');
          return () => cancelled.push(query.q ?? '');
        }),
    );
    vi.useFakeTimers();
    await typeAndWait(h, 'ri');
    expect(asked).toEqual(['ri']);
    expect(cancelled).toEqual([]);
    await typeAndWait(h, 'ric');
    expect(asked).toEqual(['ri', 'ric']);
    expect(cancelled).toEqual(['ri']);
  });

  it('searches by name, SKU, PLU and barcode on the server', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    vi.useFakeTimers();
    await typeAndWait(h, 'mineral');
    expect(resultNames(h)).toEqual(['Mineral Water 500ml']);
    await typeAndWait(h, 'SOAP-250');
    expect(resultNames(h)).toEqual(['Laundry Soap']);
    await typeAndWait(h, '201');
    expect(resultNames(h)).toContain('Loose Tomatoes');
    await typeAndWait(h, '4790000000011');
    expect(resultNames(h)).toEqual(['Rice 1kg']);
  });

  it('says "No products found" for an empty result', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    vi.useFakeTimers();
    await typeAndWait(h, 'zzzz');
    expect(h.text('search-empty')).toBe('No products found');
    expect(allById(h, 'result')).toHaveLength(0);
  });

  it('clears the results and sends nothing for an emptied box', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const products = vi.spyOn(TestBed.inject(SalesApi), 'products');
    vi.useFakeTimers();
    await typeAndWait(h, 'rice');
    expect(resultNames(h)).toHaveLength(1);
    await typeAndWait(h, '   ');
    expect(allById(h, 'result')).toHaveLength(0);
    expect(h.query('[data-testid="search-empty"]')).toBeNull();
    expect(products).toHaveBeenCalledTimes(1);
  });

  it('highlights with Up and Down and adds the highlighted result on Enter', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    vi.useFakeTimers();
    await typeAndWait(h, 'tee');
    box(h).focus();
    expect(resultNames(h)).toEqual(['Tee Shirt S', 'Tee Shirt M']);
    const highlighted = () =>
      allById(h, 'result').findIndex((r) => r.classList.contains('highlighted'));
    expect(highlighted()).toBe(0);
    expect(h.press('ArrowDown').defaultPrevented).toBe(true);
    await h.settle();
    expect(highlighted()).toBe(1);
    h.press('ArrowDown');
    await h.settle();
    expect(highlighted()).toBe(1);
    h.press('ArrowUp');
    h.press('ArrowDown');
    await h.settle();
    const event = h.press('Enter');
    expect(event.defaultPrevented).toBe(true);
    await settleFully(h);
    const lines = storeOf(h, SaleStore).lines();
    expect(lines.map((l) => l.product_name)).toEqual(['Tee Shirt M']);
    expect(box(h).value).toBe('');
    expect(allById(h, 'result')).toHaveLength(0);
    expect(document.activeElement).toBe(byId(h, 'scan-field'));
  });

  it('does not move the cart selection when Up and Down are used in the search', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    const store = storeOf(h, SaleStore);
    store.load(serverSales(h, HARNESS_TENANT_ID, 'BR001')[0]!);
    vi.useFakeTimers();
    await typeAndWait(h, 'tee');
    box(h).focus();
    h.press('ArrowDown');
    expect(store.selectedLineId()).toBeNull();
  });

  it('adds a result on a click', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    vi.useFakeTimers();
    await typeAndWait(h, 'soap');
    allById(h, 'result')[0]?.click();
    await settleFully(h);
    expect(storeOf(h, SaleStore).lines()[0]?.product_name).toBe('Laundry Soap');
  });

  it('empties the box and the results on Esc', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    vi.useFakeTimers();
    await typeAndWait(h, 'soap');
    box(h).focus();
    h.press('Escape');
    await h.settle();
    expect(box(h).value).toBe('');
    expect(allById(h, 'result')).toHaveLength(0);
  });

  it('shows a failed search and keeps the till usable', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    vi.spyOn(TestBed.inject(SalesApi), 'products').mockImplementation(
      () => new Observable<Page<TillProduct>>((subscriber) => subscriber.error(new Error('boom'))),
    );
    vi.useFakeTimers();
    await typeAndWait(h, 'rice');
    expect(h.text('search-error')).toContain('Search failed: Something went wrong');
    expect(h.query('[data-testid="search-empty"]')).toBeNull();
  });
});

@Component({
  selector: 'pos-pick-host',
  imports: [ProductSearch],
  providers: [provideTill()],
  template: `<pos-product-search mode="pick" (picked)="picks.push($event)" />`,
})
class PickHost {
  readonly picks: TillProduct[] = [];
}

describe('ProductSearch in pick mode (the goodwill refund reuses it, step A4)', () => {
  afterEach(() => vi.useRealTimers());

  async function searchInHost(text: string) {
    await setupTill({ given: ['shift_open'] });
    const host = TestBed.createComponent(PickHost);
    host.detectChanges();
    await host.whenStable();
    vi.useFakeTimers();
    const input = host.nativeElement.querySelector('input') as HTMLInputElement;
    typeInto(input, text);
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS);
    host.detectChanges();
    await host.whenStable();
    host.detectChanges();
    return { host, input };
  }

  it('reports a click through (picked) and puts nothing in a cart', async () => {
    const { host, input } = await searchInHost('water');
    const first = host.nativeElement.querySelector('[data-testid="result"]') as HTMLElement;
    expect(first.textContent).toContain('Mineral Water 500ml');
    first.click();
    host.detectChanges();
    expect(host.componentInstance.picks.map((p) => p.name)).toEqual(['Mineral Water 500ml']);
    expect(host.debugElement.injector.get(SaleStore).current()).toBeNull();
    expect(input.value).toBe('water');
  });

  it('reports Enter on the highlighted result the same way', async () => {
    const { host, input } = await searchInHost('tee');
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    host.detectChanges();
    input.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
    );
    host.detectChanges();
    expect(host.componentInstance.picks.map((p) => p.name)).toEqual(['Tee Shirt M']);
    expect(host.debugElement.injector.get(SaleStore).current()).toBeNull();
  });
});
