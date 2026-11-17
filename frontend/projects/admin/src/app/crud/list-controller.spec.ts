import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ApiClient, ApiError, ApiResult, ListParams } from '@flexipos/shared';
import { Observable, Subject, of, throwError } from 'rxjs';
import { createListController } from './list-controller';
import { ListView, SEARCH_DEBOUNCE_MS } from './list-view';
import { pageEnvelope } from './testing/crud-testing';

function result(rows: string[], total = rows.length, page = 1): ApiResult<string[]> {
  return { data: rows, meta: { request_id: 'r', pagination: { page, per_page: 25, total } } };
}

describe('createListController (T-036 AC-15, AC-16)', () => {
  it('loads page 1 with per_page 25 and no search key', () => {
    const fetch = vi.fn((_p: ListParams) => of(result(['a', 'b'], 60)));
    const c = createListController(fetch);
    c.load();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch.mock.calls[0][0]).toEqual({ page: 1, per_page: 25 });
    expect(c.rows()).toEqual(['a', 'b']);
    expect(c.total()).toBe(60);
    expect(c.pageCount()).toBe(3);
    expect(c.loading()).toBe(false);
  });

  it('a search goes back to page 1 and sends page=1 with the text', () => {
    const fetch = vi.fn((_p: ListParams) => of(result(['a'], 60)));
    const c = createListController(fetch);
    c.load();
    c.goTo(2);
    expect(fetch.mock.calls[1][0]).toEqual({ page: 2, per_page: 25 });
    c.setSearch('abc');
    expect(fetch.mock.calls[2][0]).toEqual({ page: 1, per_page: 25, search: 'abc' });
    expect(c.page()).toBe(1);
  });

  it('treats a whitespace-only search as empty and sends nothing', () => {
    const fetch = vi.fn((_p: ListParams) => of(result([])));
    const c = createListController(fetch);
    c.load();
    c.setSearch('   ');
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it('clearing the search sends a request with no search key', () => {
    const fetch = vi.fn((_p: ListParams) => of(result([])));
    const c = createListController(fetch);
    c.setSearch('abc');
    c.setSearch('  ');
    expect(fetch.mock.calls[1][0]).toEqual({ page: 1, per_page: 25 });
    expect('search' in fetch.mock.calls[1][0]).toBe(false);
  });

  it('does not send the same search again, even with other spaces', () => {
    const fetch = vi.fn((_p: ListParams) => of(result([])));
    const c = createListController(fetch);
    c.setSearch('abc');
    c.setSearch('abc');
    c.setSearch(' abc ');
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it('does not re-send the current page, and clamps to the known range', () => {
    const fetch = vi.fn((_p: ListParams) => of(result(['a'], 60)));
    const c = createListController(fetch);
    c.load();
    c.goTo(1);
    c.goTo(99);
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(fetch.mock.calls[1][0]).toEqual({ page: 3, per_page: 25 });
    c.goTo(-4);
    expect(fetch.mock.calls[2][0]).toEqual({ page: 1, per_page: 25 });
  });

  it('steps back to the last page that exists when a page past the end comes back empty (BUG-5)', () => {
    // Page 2 existed when the pager was drawn; now the server says there are 25 rows in all.
    const tall = createListController(
      vi.fn((p: ListParams) => of(p.page === 2 ? result([], 25, 2) : result(['x'], 26, 1))),
    );
    tall.load();
    tall.goTo(2);
    expect(tall.page()).toBe(1);
    expect(tall.rows()).toEqual(['x']);
    expect(tall.loading()).toBe(false);
  });

  it('does not loop when page 1 itself is empty', () => {
    const fetch = vi.fn((_p: ListParams) => of(result([], 0)));
    const c = createListController(fetch);
    c.load();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(c.rows()).toEqual([]);
    expect(c.loading()).toBe(false);
  });

  it('adds filters to every request', () => {
    const fetch = vi.fn((_p: ListParams) => of(result([])));
    const c = createListController(fetch, () => ({ type: 'variant' }));
    c.load();
    expect(fetch.mock.calls[0][0]).toEqual({ type: 'variant', page: 1, per_page: 25 });
  });

  it('sets error, empties rows and stops loading when a request fails', () => {
    const err = new ApiError(500, 'Server broke.', [], 'req-5');
    const c = createListController<string>(() => throwError(() => err));
    c.load();
    expect(c.error()).toBe(err);
    expect(c.rows()).toEqual([]);
    expect(c.loading()).toBe(false);
  });

  it('retry() sends identical params exactly once more and clears the error', () => {
    let fail = true;
    const fetch = vi.fn((_p: ListParams): Observable<ApiResult<string[]>> =>
      fail ? throwError(() => new ApiError(500, 'x')) : of(result(['ok'])),
    );
    const c = createListController(fetch);
    c.setSearch('abc');
    expect(c.error()).not.toBeNull();
    fail = false;
    c.retry();
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(fetch.mock.calls[1][0]).toEqual(fetch.mock.calls[0][0]);
    expect(c.error()).toBeNull();
    expect(c.rows()).toEqual(['ok']);
  });

  it('retry() before any request just loads', () => {
    const fetch = vi.fn((_p: ListParams) => of(result([])));
    createListController(fetch).retry();
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it('lets only the newest request answer', () => {
    const first = new Subject<ApiResult<string[]>>();
    const second = new Subject<ApiResult<string[]>>();
    const queue = [first, second];
    const c = createListController<string>(() => queue.shift() as Subject<ApiResult<string[]>>);
    c.load();
    c.setSearch('x');
    second.next(result(['new']));
    first.next(result(['stale']));
    expect(c.rows()).toEqual(['new']);
  });
});

interface Thing {
  name: string;
}

@Component({
  selector: 'adm-host',
  imports: [ListView],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<adm-list-view [controller]="list" [columns]="columns" caption="Things" />`,
})
class Host {
  readonly list = createListController<Thing>((params) =>
    TestBed.inject(ApiClient).getWithMeta<Thing[]>('things', params),
  );
  readonly columns = [{ header: 'Name', cell: (r: Thing) => r.name }];
}

describe('search through the list view (T-036 AC-15, debounced)', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    vi.useFakeTimers();
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    vi.useRealTimers();
    http.verify();
  });

  function type(el: HTMLElement, text: string): void {
    const input = el.querySelector('input[type="search"]') as HTMLInputElement;
    input.value = text;
    input.dispatchEvent(new Event('input'));
  }

  it('sends one request, page 1 with search=abc, only after the pause', () => {
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    fixture.componentInstance.list.load();
    http.expectOne((r) => r.url === '/api/v1/things').flush(pageEnvelope([{ name: 'x' }]));

    type(fixture.nativeElement, 'a');
    type(fixture.nativeElement, 'ab');
    type(fixture.nativeElement, 'abc');
    vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS - 1);
    http.expectNone(() => true);
    vi.advanceTimersByTime(1);
    const req = http.expectOne((r) => r.url === '/api/v1/things');
    expect(req.request.params.get('page')).toBe('1');
    expect(req.request.params.get('search')).toBe('abc');
    req.flush(pageEnvelope([]));
  });

  it('does not send again for the same text after another pause', () => {
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    type(fixture.nativeElement, 'abc');
    vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS);
    http.expectOne((r) => r.url === '/api/v1/things').flush(pageEnvelope([]));
    type(fixture.nativeElement, 'abc ');
    vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS);
    http.expectNone(() => true);
  });

  it('treats whitespace-only typing as empty and sends nothing', () => {
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    type(fixture.nativeElement, '    ');
    vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS * 2);
    http.expectNone(() => true);
  });
});
