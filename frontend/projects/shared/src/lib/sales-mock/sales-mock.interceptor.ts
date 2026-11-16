import {
  HttpErrorResponse,
  HttpEvent,
  HttpInterceptorFn,
  HttpRequest,
  HttpResponse,
} from '@angular/common/http';
import { inject } from '@angular/core';
import { Observable, mergeMap, of, throwError, timer } from 'rxjs';
import { AuthService } from '../auth/auth.service';
import { SalesCallId, matchCall } from '../sales/contract/calls';
import { approvalHandlers } from './handlers/approvals';
import { catalogHandlers } from './handlers/catalog';
import { lifecycleHandlers } from './handlers/lifecycle';
import { refundHandlers } from './handlers/refunds';
import { saleHandlers } from './handlers/sales';
import { shiftHandlers } from './handlers/shifts';
import { tenderHandlers } from './handlers/tenders';
import { MockContext, MockHandler } from './mock-context';
import { MockRefusal, MockResult, envelopeOf, refuse, unauthenticated } from './mock-envelope';
import { asRecord } from './mock-input';
import { MockState, SALES_MOCK_LATENCY_MS, SALES_MOCK_STATE, branchState } from './mock-state';

/**
 * The development-only sales backend (T-037 AC-5): a second interceptor that answers every
 * call in `SALES_CALLS` from in-memory state and passes anything else on, so it sits in front
 * of the untouched auth mock in `pos`'s development environment and nowhere else. It reads the
 * tenant and branch from the signed-in session inside its injection context and derives one
 * terminal per branch (P-8), so the wire stays exactly as the real server will see it.
 *
 * Order matters: put it after `authInterceptor` so it sees the Authorization header.
 */

function handlerFor(id: SalesCallId): MockHandler | undefined {
  const tables = [
    shiftHandlers(),
    catalogHandlers(),
    saleHandlers(),
    tenderHandlers(),
    lifecycleHandlers(),
    approvalHandlers(),
    refundHandlers(),
  ];
  for (const table of tables) {
    const handler = table[id];
    if (handler) {
      return handler;
    }
  }
  return undefined;
}

function notImplemented(id: SalesCallId): MockRefusal {
  return {
    ok: false,
    status: 501,
    code: 'sales.mock_not_implemented',
    message: `The sales mock does not implement ${id} yet.`,
    field: null,
    detail: id,
  };
}

/** Answers are copied so nothing the till holds can change the mock's state, or the reverse. */
function deliver(
  url: string,
  state: MockState,
  result: MockResult,
  latencyMs: number,
): Observable<HttpEvent<unknown>> {
  const body: unknown = JSON.parse(JSON.stringify(envelopeOf(state, result)));
  const answer: Observable<HttpEvent<unknown>> = result.ok
    ? of(new HttpResponse({ url, status: result.status, body }))
    : throwError(() => new HttpErrorResponse({ url, status: result.status, error: body }));
  return latencyMs > 0 ? timer(latencyMs).pipe(mergeMap(() => answer)) : answer;
}

function unreachable(url: string, latencyMs: number): Observable<HttpEvent<unknown>> {
  const failure = throwError(
    () => new HttpErrorResponse({ url, status: 0, error: new ProgressEvent('error') }),
  );
  return latencyMs > 0 ? timer(latencyMs).pipe(mergeMap(() => failure)) : failure;
}

function contextOf(
  req: HttpRequest<unknown>,
  state: MockState,
  auth: AuthService,
  params: Record<string, string>,
): MockContext | MockRefusal {
  const session = auth.session();
  if (!session?.tenantId || !session.branchId) {
    return refuse('not_authorised', 'No company and branch are selected.');
  }
  return {
    state,
    branch: branchState(state, session.tenantId, session.branchId),
    params,
    body: asRecord(req.body),
    query: (name) => req.params.get(name),
    actorName: auth.user()?.name ?? 'Cashier',
  };
}

export const salesMockInterceptor: HttpInterceptorFn = (req, next) => {
  if (!req.url.includes('/api/v1/')) {
    return next(req);
  }
  const matched = matchCall(req.method, req.url.replace(/^.*\/api\/v1\//, ''));
  if (!matched) {
    return next(req);
  }
  const state = inject(SALES_MOCK_STATE);
  const latencyMs = inject(SALES_MOCK_LATENCY_MS);
  const auth = inject(AuthService);
  const id = matched.call.id;

  if (!req.headers.has('Authorization')) {
    return deliver(req.url, state, unauthenticated(), latencyMs);
  }
  if (state.faults.unreachable) {
    return unreachable(req.url, latencyMs);
  }
  const ctx = contextOf(req, state, auth, matched.params);
  if ('ok' in ctx) {
    return deliver(req.url, state, ctx, latencyMs);
  }
  const handler = handlerFor(id);
  const result = handler ? handler(ctx) : notImplemented(id);
  const dropAt = state.faults.dropAnswerOf.indexOf(id);
  if (dropAt !== -1) {
    // The write was applied; the answer never arrives.
    state.faults.dropAnswerOf.splice(dropAt, 1);
    return unreachable(req.url, latencyMs);
  }
  return deliver(req.url, state, result, latencyMs);
};
