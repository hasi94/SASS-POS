import { Signal, computed, signal } from '@angular/core';
import { ApiError, ApiResult, DEFAULT_PER_PAGE, ListParams } from '@flexipos/shared';
import { Observable, Subscription } from 'rxjs';

/** State and actions of one paged, searchable list (T-036 AC-14 to AC-16). */
export interface ListController<T> {
  readonly rows: Signal<T[]>;
  readonly total: Signal<number>;
  readonly page: Signal<number>;
  readonly pageCount: Signal<number>;
  readonly search: Signal<string>;
  readonly loading: Signal<boolean>;
  readonly error: Signal<ApiError | null>;
  /** Sends the current page, search and filters. */
  load(): void;
  /** Re-sends the last request with identical params. */
  retry(): void;
  /** Trims, ignores an unchanged value, goes back to page 1 and loads. */
  setSearch(text: string): void;
  /** Loads a page, clamped to the known range; the current page is not re-sent. */
  goTo(page: number): void;
}

/**
 * Builds a list controller over `fetch`. `filters` adds per-resource query params (for
 * example `type`) and is read on every request. Only the newest request may answer: a slow
 * earlier reply never overwrites a later one. Holds signals only, so it needs no injector.
 */
export function createListController<T>(
  fetch: (params: ListParams) => Observable<ApiResult<T[]>>,
  filters: () => Record<string, string | number | boolean> = () => ({}),
): ListController<T> {
  const rows = signal<T[]>([]);
  const total = signal(0);
  const page = signal(1);
  const perPage = signal(DEFAULT_PER_PAGE);
  const search = signal('');
  const loading = signal(false);
  const error = signal<ApiError | null>(null);
  const pageCount = computed(() => Math.max(1, Math.ceil(total() / perPage())));

  let last: ListParams | null = null;
  let inflight: Subscription | null = null;

  function send(params: ListParams): void {
    last = params;
    inflight?.unsubscribe();
    loading.set(true);
    error.set(null);
    inflight = fetch(params).subscribe({
      next: (result) => {
        rows.set(result.data);
        const p = result.meta.pagination;
        total.set(p?.total ?? result.data.length);
        perPage.set(p?.per_page ?? DEFAULT_PER_PAGE);
        loading.set(false);
      },
      error: (err: ApiError) => {
        rows.set([]);
        error.set(err);
        loading.set(false);
      },
    });
  }

  function load(): void {
    const params: ListParams = { ...filters(), page: page(), per_page: DEFAULT_PER_PAGE };
    if (search() !== '') {
      params.search = search();
    }
    send(params);
  }

  return {
    rows: rows.asReadonly(),
    total: total.asReadonly(),
    page: page.asReadonly(),
    pageCount,
    search: search.asReadonly(),
    loading: loading.asReadonly(),
    error: error.asReadonly(),
    load,
    retry: () => (last ? send({ ...last }) : load()),
    setSearch(text: string): void {
      const next = text.trim();
      if (next === search()) {
        return;
      }
      search.set(next);
      page.set(1);
      load();
    },
    goTo(target: number): void {
      const clamped = Math.min(Math.max(1, Math.floor(target)), pageCount());
      if (clamped === page()) {
        return;
      }
      page.set(clamped);
      load();
    },
  };
}
