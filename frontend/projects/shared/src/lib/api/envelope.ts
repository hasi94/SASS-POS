/** One item of the `errors` array in the response envelope (docs/architecture.md §8). */
export interface ApiErrorItem {
  /** Namespaced by module, e.g. `auth.invalid_credentials`. */
  code: string;
  field?: string | null;
  detail?: string;
}

/** The single response envelope the backend wraps every `/api/v1` reply in. */
export interface ApiEnvelope<T> {
  success: boolean;
  data: T | null;
  message: string;
  errors: ApiErrorItem[];
  meta: { request_id: string };
}

/** Thrown by the API client for a `success: false` envelope or a transport failure. */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    readonly errors: ApiErrorItem[] = [],
    readonly requestId: string | null = null,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  hasCode(code: string): boolean {
    return this.errors.some((e) => e.code === code);
  }

  /** Error detail for one form field, if the server named it. */
  fieldDetail(field: string): string | undefined {
    return this.errors.find((e) => e.field === field)?.detail;
  }
}

export function isEnvelope(body: unknown): body is ApiEnvelope<unknown> {
  return typeof body === 'object' && body !== null && 'success' in body && 'meta' in body;
}
