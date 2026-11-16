import { ApiError } from '../../api/envelope';

/**
 * The closed set of refusal codes the till can meet (T-037 AC-3): the Sales domain's own codes
 * plus the pricing-engine reasons that are passed through. A new code is added here and the
 * compiler then demands a catalogue entry, a status and a fact row for it.
 */
export const SALES_REFUSAL_CODES = [
  'no_open_shift',
  'not_found',
  'unit_unresolvable',
  'unresolvable_price',
  'price_override_not_allowed',
  'discount_with_override',
  'approval_required',
  'approval_not_pending',
  'approval_already_used',
  'approval_not_approved',
  'approval_mismatch',
  'self_approval_not_allowed',
  'pin_invalid',
  'not_authorised',
  'sale_not_editable',
  'customer_required',
  'unsupported_method_type',
  'balance_due',
  'exceeds_balance',
  'credit_refused',
  'usage_limit_reached',
  'numbering_refused',
  'shift_closed',
  'shift_already_open',
  'shift_not_open',
  'shift_has_open_sales',
  'invalid_transition',
  'over_refund',
  'fully_refunded',
  'inherited_unit_mismatch',
  'not_refundable',
  'product_not_sellable',
  'replay_conflict',
  'in_use',
  'out_of_range',
  'validation',
  // Pricing-engine reasons, passed through under their own names.
  'unknown_product',
  'no_effective_rate',
  'not_applicable',
  'inactive',
  'unknown_discount',
  'discount_not_allowed',
] as const;

export type SalesRefusalCode = (typeof SALES_REFUSAL_CODES)[number];

/**
 * The HTTP status each refusal travels under (P-5, the T-014 Ruling 8 precedent): 422 for
 * validation, 404 for not found, 401 for an unauthenticated call (not a refusal code) and 403
 * for every other refusal. The till never branches on a status except 0 (network) and 5xx.
 */
export const REFUSAL_STATUS: Record<SalesRefusalCode, number> = {
  no_open_shift: 403,
  not_found: 404,
  unit_unresolvable: 403,
  unresolvable_price: 403,
  price_override_not_allowed: 403,
  discount_with_override: 403,
  approval_required: 403,
  approval_not_pending: 403,
  approval_already_used: 403,
  approval_not_approved: 403,
  approval_mismatch: 403,
  self_approval_not_allowed: 403,
  pin_invalid: 403,
  not_authorised: 403,
  sale_not_editable: 403,
  customer_required: 403,
  unsupported_method_type: 403,
  balance_due: 403,
  exceeds_balance: 403,
  credit_refused: 403,
  usage_limit_reached: 403,
  numbering_refused: 403,
  shift_closed: 403,
  shift_already_open: 403,
  shift_not_open: 403,
  shift_has_open_sales: 403,
  invalid_transition: 403,
  over_refund: 403,
  fully_refunded: 403,
  inherited_unit_mismatch: 403,
  not_refundable: 403,
  product_not_sellable: 403,
  replay_conflict: 403,
  in_use: 403,
  out_of_range: 403,
  validation: 422,
  unknown_product: 403,
  no_effective_rate: 403,
  not_applicable: 403,
  inactive: 403,
  unknown_discount: 403,
  discount_not_allowed: 403,
};

/**
 * Where a refusal's fact travels (P-4): `errors[0].field` is the fact's name and
 * `errors[0].detail` its machine value, read with `ApiError.fieldDetail()`. An empty list means
 * the refusal carries no fact. `validation` is the T-021 native shape (field = the input name,
 * detail = the message) and is read through {@link refusalFact} specially.
 */
export const REFUSAL_FACT: Record<SalesRefusalCode, readonly string[]> = {
  no_open_shift: [],
  not_found: [],
  unit_unresolvable: ['unit_code'],
  unresolvable_price: [],
  price_override_not_allowed: [],
  discount_with_override: [],
  approval_required: [],
  approval_not_pending: [],
  approval_already_used: [],
  approval_not_approved: [],
  approval_mismatch: [],
  self_approval_not_allowed: [],
  pin_invalid: [],
  not_authorised: [],
  sale_not_editable: [],
  customer_required: [],
  unsupported_method_type: [],
  balance_due: ['balance_due'],
  exceeds_balance: [],
  credit_refused: ['cause'],
  usage_limit_reached: [],
  numbering_refused: ['cause'],
  shift_closed: [],
  shift_already_open: [],
  shift_not_open: [],
  shift_has_open_sales: [],
  invalid_transition: ['status'],
  over_refund: ['refundable_quantity', 'refundable_amount'],
  fully_refunded: [],
  inherited_unit_mismatch: ['unit_code'],
  not_refundable: [],
  product_not_sellable: ['failed_fact'],
  replay_conflict: [],
  in_use: ['count'],
  out_of_range: [],
  validation: [],
  unknown_product: [],
  no_effective_rate: [],
  not_applicable: [],
  inactive: [],
  unknown_discount: [],
  discount_not_allowed: [],
};

const KNOWN = new Set<string>(SALES_REFUSAL_CODES);

/**
 * The refusal an {@link ApiError} carries, or null (P-3). Takes the first `errors[].code`,
 * strips the `<module>.` prefix (T-021 AC-10: `sales.no_open_shift`, `kernel.not_found`), maps
 * `validation_failed` to `validation`, and returns it only when it is a member of
 * {@link SALES_REFUSAL_CODES}. Anything else is the generic case (AC-73).
 */
export function refusalOf(err: ApiError): SalesRefusalCode | null {
  const first = err.errors[0];
  if (!first) {
    return null;
  }
  const dot = first.code.indexOf('.');
  const bare = dot === -1 ? first.code : first.code.slice(dot + 1);
  const name = bare === 'validation_failed' ? 'validation' : bare;
  return KNOWN.has(name) ? (name as SalesRefusalCode) : null;
}

/** A fact a refusal carried: its name and the machine value the server sent. */
export interface RefusalFact {
  name: string;
  value: string;
}

/**
 * The fact the refusal names (P-4), or null. For `validation` it is the failing input's name and
 * the server's message; for every other code it is the first fact listed in {@link REFUSAL_FACT}
 * that the error actually carries.
 */
export function refusalFact(err: ApiError, code: SalesRefusalCode): RefusalFact | null {
  if (code === 'validation') {
    const item = err.errors.find((e) => !!e.field);
    return item?.field ? { name: item.field, value: item.detail ?? '' } : null;
  }
  for (const name of REFUSAL_FACT[code]) {
    const value = err.fieldDetail(name);
    if (value !== undefined) {
      return { name, value };
    }
  }
  return null;
}
