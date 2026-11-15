import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, InjectionToken, inject } from '@angular/core';
import { Observable, catchError, map, throwError } from 'rxjs';
import { ApiEnvelope, ApiError, isEnvelope } from './envelope';
import { isLegacyRefusal, legacyRefusalToApiError } from './legacy-refusal';

/** Base of the versioned REST API. Apps provide it; the default is same-origin. */
export const API_BASE_URL = new InjectionToken<string>('API_BASE_URL', {
  providedIn: 'root',
  factory: () => '/api/v1',
});

/**
 * Thin client over `/api/v1`. It unwraps the response envelope so callers receive `data`
 * and anything else becomes an {@link ApiError}.
 */
@Injectable({ providedIn: 'root' })
export class ApiClient {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  get<T>(path: string, params?: Record<string, string | number | boolean>): Observable<T> {
    return this.unwrap(this.http.get<ApiEnvelope<T>>(this.url(path), { params }));
  }

  post<T>(path: string, body?: unknown): Observable<T> {
    return this.unwrap(this.http.post<ApiEnvelope<T>>(this.url(path), body ?? {}));
  }

  put<T>(path: string, body?: unknown): Observable<T> {
    return this.unwrap(this.http.put<ApiEnvelope<T>>(this.url(path), body ?? {}));
  }

  delete<T>(path: string): Observable<T> {
    return this.unwrap(this.http.delete<ApiEnvelope<T>>(this.url(path)));
  }

  /**
   * For the pre-envelope endpoints only (T-021 D-1: auth/access/organization/
   * platform/terminal, until FP-369 moves them onto the envelope). Skips
   * `unwrap()` entirely — the real 2xx body is returned as-is — but still
   * maps a failure through {@link toApiError}, so a real auth refusal's
   * token surfaces the same way a `post()` failure's would.
   */
  postRaw<T>(path: string, body?: unknown): Observable<T> {
    return this.http
      .post<T>(this.url(path), body ?? {})
      .pipe(catchError((err: unknown) => throwError(() => toApiError(err))));
  }

  private url(path: string): string {
    return `${this.base}/${path.replace(/^\/+/, '')}`;
  }

  private unwrap<T>(source: Observable<ApiEnvelope<T>>): Observable<T> {
    return source.pipe(
      map((body) => {
        if (!isEnvelope(body)) {
          throw new ApiError(200, 'The server sent a reply that is not an API envelope.');
        }
        if (!body.success) {
          throw new ApiError(200, body.message, body.errors, body.meta.request_id);
        }
        return body.data as T;
      }),
      catchError((err: unknown) => throwError(() => toApiError(err))),
    );
  }
}

export function toApiError(err: unknown): ApiError {
  if (err instanceof ApiError) {
    return err;
  }
  if (err instanceof HttpErrorResponse) {
    if (isEnvelope(err.error)) {
      return new ApiError(
        err.status,
        err.error.message,
        err.error.errors,
        err.error.meta.request_id,
      );
    }
    if (isLegacyRefusal(err.error)) {
      return legacyRefusalToApiError(err.status, err.error, err.headers?.get('X-Request-Id') ?? null);
    }
    if (err.status === 0) {
      return new ApiError(0, 'Cannot reach the server.');
    }
    return new ApiError(err.status, err.statusText || 'The request failed.');
  }
  return new ApiError(0, err instanceof Error ? err.message : 'Unexpected error.');
}
