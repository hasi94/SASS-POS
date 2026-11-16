/**
 * List conventions (T-036 D-7), PROVISIONAL pending B-1: offset pages with
 * `?page=&per_page=&search=`, and `meta.pagination = {page, per_page, total}` next to
 * `meta.request_id`.
 */

export interface ApiPagination {
  page: number;
  per_page: number;
  total: number;
}

export interface ApiMeta {
  request_id: string;
  /** Present on list replies only. */
  pagination?: ApiPagination;
}

/** What `ApiClient.getWithMeta()` returns: the envelope's `data` and `meta` together. */
export interface ApiResult<T> {
  data: T;
  meta: ApiMeta;
}

/** Query parameters of a list request; any further key is a per-resource filter. */
export interface ListParams {
  page: number;
  per_page: number;
  search?: string;
  [filter: string]: string | number | boolean | undefined;
}

/** One page size for every list in T-036. */
export const DEFAULT_PER_PAGE = 25;
