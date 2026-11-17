/**
 * Every call the till makes, in one table (T-037 P-6). `SalesApi` builds its requests from it and
 * the mock matches incoming requests against it, so a path cannot drift between the two.
 *
 * `fixtures` is the coverage table (P-11): the case names that must exist as
 * `fixtures/<call-id>/<case>.json`. A call with no `fixtures` entry yet is not covered; the track
 * that implements it appends its row. Case names are `success`, `success.<variant>` and
 * `refused.<code>[.<variant>]`.
 */

export type SalesCallId =
  | 'shifts.current'
  | 'shifts.open'
  | 'shifts.movement'
  | 'shifts.close'
  | 'sales.create'
  | 'sales.get'
  | 'sales.list'
  | 'sales.add-line'
  | 'sales.update-line'
  | 'sales.remove-line'
  | 'sales.attach-customer'
  | 'sales.add-tender'
  | 'sales.remove-tender'
  | 'sales.complete'
  | 'sales.hold'
  | 'sales.recall'
  | 'sales.void'
  | 'approvals.request'
  | 'approvals.decide'
  | 'refunds.quote'
  | 'refunds.create'
  | 'payment-methods.list'
  | 'till.categories'
  | 'till.products'
  | 'till.scan'
  | 'discounts.applicable'
  | 'customers.lookup';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'DELETE';

export interface SalesCall {
  readonly id: SalesCallId;
  readonly method: HttpMethod;
  /** Relative to `/api/v1`; `{name}` is a path parameter. */
  readonly pattern: string;
  /** Not in T-030 yet: the route layer follow-up row owns it (P-7). */
  readonly proposed?: boolean;
  /** Fixture case names that must exist for this call (the coverage table). */
  readonly fixtures?: readonly string[];
}

export const SALES_CALLS: readonly SalesCall[] = [
  {
    id: 'shifts.current',
    method: 'GET',
    pattern: 'shifts/current',
    fixtures: ['success', 'success.none'],
  },
  {
    id: 'shifts.open',
    method: 'POST',
    pattern: 'shifts',
    fixtures: ['success', 'refused.shift_already_open', 'refused.validation'],
  },
  {
    id: 'shifts.movement',
    method: 'POST',
    pattern: 'shifts/{id}/movements',
    fixtures: [
      'success',
      'success.replay',
      'refused.shift_not_open',
      'refused.not_found',
      'refused.replay_conflict',
      'refused.validation',
    ],
  },
  {
    id: 'shifts.close',
    method: 'POST',
    pattern: 'shifts/{id}/close',
    fixtures: [
      'success',
      'success.replay',
      'refused.shift_has_open_sales',
      'refused.not_found',
      'refused.replay_conflict',
      'refused.validation',
    ],
  },
  {
    id: 'sales.create',
    method: 'POST',
    pattern: 'sales',
    fixtures: ['success', 'success.with-customer', 'refused.no_open_shift', 'refused.not_found'],
  },
  {
    id: 'sales.get',
    method: 'GET',
    pattern: 'sales/{id}',
    fixtures: ['success', 'refused.not_found'],
  },
  {
    id: 'sales.list',
    method: 'GET',
    pattern: 'sales',
    fixtures: ['success', 'success.filtered', 'refused.validation'],
  },
  {
    id: 'sales.add-line',
    method: 'POST',
    pattern: 'sales/{id}/lines',
    fixtures: [
      'success',
      'success.pack-unit',
      'success.weighed',
      'success.discount',
      'success.override',
      'success.zero-price',
      'refused.sale_not_editable',
      'refused.not_found',
      'refused.unit_unresolvable',
      'refused.unresolvable_price',
      'refused.product_not_sellable.inactive',
      'refused.product_not_sellable.not_sellable',
      'refused.price_override_not_allowed',
      'refused.discount_with_override',
      'refused.approval_required.override',
      'refused.approval_required.discount',
      'refused.approval_mismatch',
      'refused.unknown_discount',
      'refused.not_applicable',
      'refused.inactive',
      'refused.discount_not_allowed',
      'refused.validation',
    ],
  },
  {
    id: 'sales.update-line',
    method: 'PUT',
    pattern: 'sales/{id}/lines/{lineId}',
    fixtures: [
      'success',
      'success.discount',
      'success.remove-discount',
      'refused.sale_not_editable',
      'refused.not_found.line',
      'refused.not_found.sale',
      'refused.unit_unresolvable',
      'refused.price_override_not_allowed',
      'refused.discount_with_override',
      'refused.approval_required',
      'refused.validation',
    ],
  },
  {
    id: 'sales.remove-line',
    method: 'DELETE',
    pattern: 'sales/{id}/lines/{lineId}',
    fixtures: ['success', 'refused.sale_not_editable', 'refused.not_found'],
  },
  {
    id: 'sales.attach-customer',
    method: 'PUT',
    pattern: 'sales/{id}/customer',
    proposed: true,
    fixtures: [
      'success',
      'refused.not_found.customer',
      'refused.not_found.sale',
      'refused.sale_not_editable',
      'refused.validation',
    ],
  },
  {
    id: 'sales.add-tender',
    method: 'POST',
    pattern: 'sales/{id}/tenders',
    fixtures: [
      'success',
      'success.cash-over-tender',
      'success.part-payment',
      'success.card',
      'success.credit',
      'success.replay',
      'success.held-from-pending-payment',
      'refused.customer_required',
      'refused.unsupported_method_type',
      'refused.exceeds_balance.card-over-tender',
      'refused.replay_conflict',
      'refused.not_found.sale',
      'refused.not_found.method',
      'refused.invalid_transition.held-from-draft',
      'refused.validation',
    ],
  },
  {
    id: 'sales.remove-tender',
    method: 'DELETE',
    pattern: 'sales/{id}/tenders/{tenderId}',
    fixtures: [
      'success',
      'refused.not_found.tender',
      'refused.not_found.sale',
      'refused.invalid_transition',
    ],
  },
  {
    id: 'sales.complete',
    method: 'POST',
    pattern: 'sales/{id}/complete',
    fixtures: [
      'success',
      'success.change',
      'success.zero-total',
      'success.credit',
      'refused.balance_due',
      'refused.credit_refused',
      'refused.usage_limit_reached',
      'refused.invalid_transition',
      'refused.not_found',
      'refused.validation',
    ],
  },
  {
    id: 'sales.hold',
    method: 'POST',
    pattern: 'sales/{id}/hold',
    fixtures: [
      'success',
      'success.pending-payment',
      'refused.invalid_transition',
      'refused.not_found',
    ],
  },
  {
    id: 'sales.recall',
    method: 'POST',
    pattern: 'sales/{id}/recall',
    fixtures: [
      'success',
      'success.pending-payment',
      'success.other-terminal',
      'refused.invalid_transition',
      'refused.no_open_shift',
      'refused.not_found',
    ],
  },
  {
    id: 'sales.void',
    method: 'POST',
    pattern: 'sales/{id}/void',
    fixtures: [
      'success',
      'success.held',
      'success.pending-payment',
      'success.completed',
      'refused.approval_required',
      'refused.approval_already_used',
      'refused.approval_not_approved',
      'refused.approval_mismatch',
      'refused.shift_closed',
      'refused.invalid_transition',
      'refused.not_found',
      'refused.validation',
    ],
  },
  {
    id: 'approvals.request',
    method: 'POST',
    pattern: 'approvals',
    fixtures: [
      'success',
      'success.refund',
      'success.goodwill-refund',
      'success.price-override',
      'success.discount',
      'refused.not_found',
      'refused.validation',
    ],
  },
  {
    id: 'approvals.decide',
    method: 'POST',
    pattern: 'approvals/{id}/decide',
    fixtures: [
      'success',
      'success.reject',
      'refused.pin_invalid',
      'refused.self_approval_not_allowed',
      'refused.not_authorised',
      'refused.approval_not_pending',
      'refused.not_found',
      'refused.validation',
    ],
  },
  {
    id: 'refunds.quote',
    method: 'POST',
    pattern: 'refunds/quote',
    proposed: true,
    fixtures: [
      'success',
      'success.partial',
      'success.goodwill',
      'refused.not_found',
      'refused.not_refundable.not-completed',
      'refused.not_refundable.other-branch',
      'refused.fully_refunded',
      'refused.over_refund.quantity',
      'refused.inherited_unit_mismatch',
      'refused.validation',
    ],
  },
  {
    id: 'refunds.create',
    method: 'POST',
    pattern: 'refunds',
    proposed: true,
    fixtures: [
      'success',
      'success.partial',
      'success.goodwill',
      'success.zero-total',
      'success.credit',
      'success.replay',
      'refused.approval_required',
      'refused.approval_mismatch',
      'refused.exceeds_balance',
      'refused.balance_due',
      'refused.unsupported_method_type',
      'refused.customer_required',
      'refused.over_refund',
      'refused.fully_refunded',
      'refused.inherited_unit_mismatch',
      'refused.not_found',
      'refused.not_refundable',
      'refused.replay_conflict',
      'refused.no_open_shift',
      'refused.validation',
    ],
  },
  {
    id: 'payment-methods.list',
    method: 'GET',
    pattern: 'payment-methods',
    fixtures: ['success'],
  },
  {
    id: 'till.categories',
    method: 'GET',
    pattern: 'till/categories',
    fixtures: ['success'],
  },
  {
    id: 'till.products',
    method: 'GET',
    pattern: 'till/products',
    fixtures: ['success', 'success.search', 'success.page-2', 'refused.validation'],
  },
  {
    id: 'till.scan',
    method: 'POST',
    pattern: 'till/scan',
    fixtures: [
      'success',
      'success.plu',
      'success.pack',
      'success.weighable',
      'success.embedded-weight',
      'success.embedded-price',
      'refused.not_found',
      'refused.validation',
    ],
  },
  {
    id: 'discounts.applicable',
    method: 'GET',
    pattern: 'sales/{id}/lines/{lineId}/discounts',
    fixtures: ['success', 'refused.not_found'],
  },
  {
    id: 'customers.lookup',
    method: 'GET',
    pattern: 'customers/lookup',
    fixtures: ['success', 'success.empty', 'refused.validation'],
  },
];

export type PathParams = Readonly<Record<string, string>>;

/** The call with this id. Throws for an id that is not in the table (a programming error). */
export function callById(id: SalesCallId): SalesCall {
  const call = SALES_CALLS.find((c) => c.id === id);
  if (!call) {
    throw new Error(`Unknown sales call: ${id}`);
  }
  return call;
}

/** Fills the `{name}` segments of a pattern, percent-encoding each value. */
export function buildPath(pattern: string, params: PathParams = {}): string {
  return pattern.replace(/\{(\w+)\}/g, (_whole, name: string) => {
    const value = params[name];
    if (value === undefined) {
      throw new Error(`Missing path parameter "${name}" for ${pattern}`);
    }
    return encodeURIComponent(value);
  });
}

/** The relative path of a call with its parameters filled in. */
export function callPath(id: SalesCallId, params: PathParams = {}): string {
  return buildPath(callById(id).pattern, params);
}

export interface MatchedCall {
  readonly call: SalesCall;
  readonly params: Record<string, string>;
}

function matchPattern(pattern: string, path: string): Record<string, string> | null {
  const want = pattern.split('/');
  const got = path.split('/');
  if (want.length !== got.length) {
    return null;
  }
  const params: Record<string, string> = {};
  for (let i = 0; i < want.length; i++) {
    const w = want[i] ?? '';
    const g = got[i] ?? '';
    const named = /^\{(\w+)\}$/.exec(w);
    if (named) {
      params[named[1] ?? ''] = decodeURIComponent(g);
    } else if (w !== g) {
      return null;
    }
  }
  return params;
}

/** Finds the call a request belongs to, or null (the mock then passes the request on). */
export function matchCall(method: string, path: string): MatchedCall | null {
  const clean = path.replace(/^\/+/, '').replace(/\/+$/, '');
  for (const call of SALES_CALLS) {
    if (call.method !== method) {
      continue;
    }
    const params = matchPattern(call.pattern, clean);
    if (params) {
      return { call, params };
    }
  }
  return null;
}
