import {
  ApiError,
  RefusalFact,
  SalesRefusalCode,
  formatMoney,
  formatQuantity,
  refusalFact,
  refusalOf,
} from '@flexipos/shared';

/**
 * The one place that turns a refusal into words (T-037 D-21, AC-72, AC-73, AC-76). The record is
 * keyed by the closed `SalesRefusalCode` union, so a code added to the contract without a
 * message here is a compile error, and the spec checks every entry has a non-empty message and a
 * next step. Nothing here shows a server `detail` as a heading: details are facts, quoted inside
 * a sentence of our own.
 */

export type NextActionKind =
  | 'none'
  | 'open-shift'
  | 'start-refund'
  | 'stay-in-payment'
  | 'edit-amount'
  | 'open-customer'
  | 'reload-sale'
  | 'supervisor'
  | 'hand-over'
  | 'retry'
  | 'edit-quantity'
  | 'choose-unit'
  | 'remove-discount'
  | 'change-method'
  | 'fix-field';

/** What the cashier is offered to do about it. `none` still carries a label for the message bar. */
export interface NextAction {
  kind: NextActionKind;
  label: string;
}

export interface TillMessage {
  kind: 'info' | 'success' | 'refusal';
  /** The sentence the cashier reads and the screen reader announces. */
  text: string;
  /** Secondary information (a status and a request id to quote to support). */
  detail?: string;
  next?: NextAction;
  /** The refusal this describes, when it is one of ours. */
  code?: SalesRefusalCode | null;
}

/** The fact a refusal carried (P-4), already read from the error. */
export interface RefusalFacts {
  fact: RefusalFact | null;
}

export interface CatalogueEntry {
  message: (facts: RefusalFacts) => string;
  next: NextAction;
}

const NONE: NextAction = { kind: 'none', label: 'Dismiss' };

function entry(
  message: string | ((facts: RefusalFacts) => string),
  next: NextAction,
): CatalogueEntry {
  return { message: typeof message === 'string' ? () => message : message, next };
}

const APPROVAL_STALE = 'a new approval is needed';
const SUPERVISOR: NextAction = { kind: 'supervisor', label: 'Ask a supervisor' };
const RELOAD: NextAction = { kind: 'reload-sale', label: 'Reload the sale' };

export const REFUSAL_CATALOGUE: Record<SalesRefusalCode, CatalogueEntry> = {
  no_open_shift: entry('Open a shift first', { kind: 'open-shift', label: 'Open a shift' }),
  not_found: entry('That record was not found', NONE),
  unit_unresolvable: entry('This unit does not apply to the product', {
    kind: 'choose-unit',
    label: 'Choose another unit',
  }),
  unresolvable_price: entry('No price for this product', NONE),
  price_override_not_allowed: entry('A price override is not allowed for this product', NONE),
  discount_with_override: entry('A line with a price override cannot take a discount', NONE),
  approval_required: entry('A supervisor must approve this', SUPERVISOR),
  approval_not_pending: entry(`That approval was already decided: ${APPROVAL_STALE}`, SUPERVISOR),
  approval_already_used: entry(`That approval was already used: ${APPROVAL_STALE}`, SUPERVISOR),
  approval_not_approved: entry(`That approval was not granted: ${APPROVAL_STALE}`, SUPERVISOR),
  approval_mismatch: entry(
    `That approval is for a different action: ${APPROVAL_STALE}`,
    SUPERVISOR,
  ),
  self_approval_not_allowed: entry(
    'This person cannot approve this action: nobody may approve their own work',
    { kind: 'hand-over', label: 'Hand over to another supervisor' },
  ),
  pin_invalid: entry('Code or PIN not recognised', { kind: 'retry', label: 'Try again' }),
  not_authorised: entry('This person cannot approve this action: not authorised to approve', {
    kind: 'hand-over',
    label: 'Hand over to another supervisor',
  }),
  sale_not_editable: entry('Payment has started: remove the tenders to edit lines', RELOAD),
  customer_required: entry('Attach a customer first', {
    kind: 'open-customer',
    label: 'Choose a customer',
  }),
  unsupported_method_type: entry('That payment method cannot be used', {
    kind: 'change-method',
    label: 'Choose another method',
  }),
  balance_due: entry(
    (facts) =>
      facts.fact
        ? `Balance remaining: ${formatMoney(facts.fact.value)}`
        : 'Balance remaining on the sale',
    { kind: 'stay-in-payment', label: 'Take the rest of the payment' },
  ),
  exceeds_balance: entry('More than the balance', {
    kind: 'edit-amount',
    label: 'Change the amount',
  }),
  credit_refused: entry(
    (facts) => (facts.fact ? `Store credit refused (${facts.fact.value})` : 'Store credit refused'),
    { kind: 'change-method', label: 'Choose another method' },
  ),
  usage_limit_reached: entry('That discount has been used up', {
    kind: 'remove-discount',
    label: 'Remove the discount and retry',
  }),
  numbering_refused: entry(
    (facts) =>
      facts.fact
        ? `The sale number could not be allocated (${facts.fact.value})`
        : 'The sale number could not be allocated',
    { kind: 'retry', label: 'Try again' },
  ),
  shift_closed: entry("That sale's shift is closed", {
    kind: 'start-refund',
    label: 'Start a refund',
  }),
  shift_already_open: entry('A shift is already open', NONE),
  shift_not_open: entry('The shift is not open', { kind: 'open-shift', label: 'Open a shift' }),
  shift_has_open_sales: entry('Finish or void the open sales before closing the shift', NONE),
  invalid_transition: entry(
    (facts) =>
      facts.fact
        ? `That sale cannot be changed that way now (status: ${facts.fact.value})`
        : 'That sale cannot be changed that way now',
    RELOAD,
  ),
  over_refund: entry(
    (facts) => {
      if (!facts.fact) {
        return 'More than is left to refund';
      }
      const left =
        facts.fact.name === 'refundable_amount'
          ? formatMoney(facts.fact.value)
          : formatQuantity(facts.fact.value);
      return `More than is left to refund: ${left} remaining`;
    },
    { kind: 'edit-quantity', label: 'Change the quantity' },
  ),
  fully_refunded: entry('Already fully refunded', NONE),
  inherited_unit_mismatch: entry('Refund in the unit the line was sold in', {
    kind: 'choose-unit',
    label: 'Use the original unit',
  }),
  not_refundable: entry('Sale not found at this branch', NONE),
  product_not_sellable: entry(
    (facts) =>
      facts.fact
        ? `This product cannot be sold (${facts.fact.value})`
        : 'This product cannot be sold',
    NONE,
  ),
  replay_conflict: entry('This entry conflicts with an earlier one', RELOAD),
  in_use: entry(
    (facts) =>
      facts.fact ? `Payment method in use (${facts.fact.value})` : 'Payment method in use',
    NONE,
  ),
  out_of_range: entry('That amount is too large', {
    kind: 'edit-amount',
    label: 'Change the amount',
  }),
  validation: entry(
    (facts) =>
      facts.fact ? `${facts.fact.name}: ${facts.fact.value}` : 'The request was not valid',
    { kind: 'fix-field', label: 'Fix the field' },
  ),
  unknown_product: entry('That product is not known', NONE),
  no_effective_rate: entry('No tax rate applies to this product', NONE),
  not_applicable: entry('That discount does not apply to this line', NONE),
  inactive: entry('That discount is no longer active', NONE),
  unknown_discount: entry('That discount does not exist', NONE),
  discount_not_allowed: entry('Discounts are not allowed on this product', NONE),
};

/** The text of the banner and of the message a status-0 error produces (AC-74). */
export const NETWORK_MESSAGE = 'Cannot reach the server: sale actions are paused';

function genericDetail(err: ApiError): string {
  const status = err.status > 0 ? `Status ${err.status}` : '';
  const request = err.requestId ? `Request ${err.requestId}` : '';
  return [status, request].filter((part) => part !== '').join(', ');
}

/**
 * Turns anything a failed call threw into a message (AC-72, AC-73, AC-76). An error with no
 * code we know, or no `errors` at all, becomes "Something went wrong" with the status and the
 * request id so a cashier can quote them; it never shows an empty message or a raw detail.
 */
export function describeError(err: unknown): TillMessage {
  if (!(err instanceof ApiError)) {
    return { kind: 'refusal', text: 'Something went wrong', code: null };
  }
  if (err.status === 0) {
    return {
      kind: 'refusal',
      text: NETWORK_MESSAGE,
      next: { kind: 'retry', label: 'Retry' },
      code: null,
    };
  }
  const code = refusalOf(err);
  if (code === null) {
    return {
      kind: 'refusal',
      text: 'Something went wrong',
      detail: genericDetail(err),
      code: null,
    };
  }
  const item = REFUSAL_CATALOGUE[code];
  return {
    kind: 'refusal',
    text: item.message({ fact: refusalFact(err, code) }),
    next: item.next,
    code,
  };
}
