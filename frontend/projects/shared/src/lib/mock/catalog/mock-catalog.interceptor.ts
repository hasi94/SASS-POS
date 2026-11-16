import { HttpErrorResponse, HttpInterceptorFn, HttpResponse } from '@angular/common/http';
import { Observable, delay, of, throwError, timer } from 'rxjs';
import { mergeMap } from 'rxjs/operators';
import { CATALOG_PERMISSIONS } from '../../contracts/catalog/catalog-permissions';
import { mockPermissionsForBearer } from '../mock-backend';
import { handleCategoriesAndBrands } from './mock-catalog.categories-brands';
import { handleModifierGroups } from './mock-catalog.modifiers';
import { handleProductsAndBarcodes } from './mock-catalog.products';
import {
  MockRefusal,
  MockReply,
  MockRequest,
  catalogState,
  notFound,
  okEnvelope,
  refusal,
  refuseEnvelope,
} from './mock-catalog.store';
import { handleUnitsAndConversions } from './mock-catalog.units';

const PREFIX = /^.*\/api\/v1\/catalog\//;

type Resource = keyof typeof CATALOG_PERMISSIONS;

/** The permission resource a path belongs to (plan, "Key per call"). */
function resourceOf(segments: string[]): Resource | null {
  switch (segments[0]) {
    case 'categories':
      return 'categories';
    case 'brands':
      return 'brands';
    case 'units':
    case 'unit-conversions':
      return 'units';
    case 'products':
      return segments[2] === 'barcodes' ? 'barcodes' : 'products';
    case 'modifier-groups':
      return 'modifiers';
    default:
      return null;
  }
}

function reply(url: string, status: number, body: unknown): Observable<HttpResponse<unknown>> {
  const latency = catalogState().latencyMs;
  if (status >= 400) {
    return timer(latency).pipe(
      mergeMap(() => throwError(() => new HttpErrorResponse({ url, status, error: body }))),
    );
  }
  return of(new HttpResponse({ url, status, body })).pipe(delay(latency));
}

function refuse(url: string, refused: MockRefusal): Observable<HttpResponse<unknown>> {
  return reply(url, refused.status, refuseEnvelope(refused.message, refused.errors));
}

/**
 * Stand-in for the catalog HTTP surface until B-1 exists (T-036 D-2, AC-24). Development
 * only: install it from an app's `environment.development.ts`, before `mockBackendInterceptor`
 * and after `authInterceptor`. Anything that is not under `/api/v1/catalog/` passes through.
 * PROVISIONAL pending B-1; reconciliation is F-4.
 */
export const mockCatalogInterceptor: HttpInterceptorFn = (req, next) => {
  if (!PREFIX.test(req.url)) {
    return next(req);
  }
  const segments = req.url
    .replace(PREFIX, '')
    .split('/')
    .filter((s) => s !== '');
  const bearer = req.headers.get('Authorization');

  if (!bearer) {
    return refuse(req.url, refusal(401, 'kernel.unauthenticated', 'Sign in to continue.'));
  }
  const resource = resourceOf(segments);
  if (resource === null) {
    return refuse(req.url, notFound('That catalog resource'));
  }
  const key = CATALOG_PERMISSIONS[resource][req.method === 'GET' ? 'view' : 'manage'];
  if (!mockPermissionsForBearer(bearer)?.includes(key)) {
    return refuse(
      req.url,
      refusal(403, 'kernel.forbidden', 'You do not have permission to do that.'),
    );
  }

  const request: MockRequest = {
    method: req.method,
    segments,
    query: req.params,
    body: (req.body ?? {}) as Record<string, unknown>,
  };
  try {
    const result: MockReply | null =
      handleCategoriesAndBrands(request) ??
      handleUnitsAndConversions(request) ??
      handleProductsAndBarcodes(request) ??
      handleModifierGroups(request);
    if (result === null) {
      return refuse(req.url, notFound('That catalog resource'));
    }
    return reply(req.url, result.status, okEnvelope(result.data, result.pagination));
  } catch (err) {
    if (err instanceof MockRefusal) {
      return refuse(req.url, err);
    }
    throw err;
  }
};
