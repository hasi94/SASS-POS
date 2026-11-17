import { Sale, SaleTender } from '../../sales/contract/sale';
import { Money } from '../../sales/decimal-string';
import { HandlerTable, MockContext } from '../mock-context';
import { MockRefusal, MockResult, invalid, ok, refuse } from '../mock-envelope';
import { ZERO, add, cmp, isZero, sub, sum } from '../mock-decimal';
import { isRefusal, readString } from '../mock-input';
import { findDiscount, recalc } from '../mock-pricing';
import {
  BranchState,
  MockState,
  StoredApproval,
  StoredSale,
  nextId,
  openShiftOf,
  tick,
} from '../mock-state';
import { ApprovalBinding, checkApproval, consumeApproval } from './approvals';

/**
 * `sales.complete`, `sales.hold`, `sales.recall` and `sales.void` (T-030 AC-14 to AC-22, AC-51).
 * The `*In` functions do the work on stored state and are shared with the scenario steps, so a
 * `given` step walks the same rules as a request.
 */

/** The sale a request names, or `not_found`. */
export function findStoredSale(ctx: MockContext): StoredSale | MockRefusal {
  const stored = ctx.branch.sales.find((s) => s.data.id === ctx.params['id']);
  return stored ?? refuse('not_found', 'Sale not found.');
}

/** `invalid_transition`, naming the status the sale is in (P-4). */
export function invalidTransition(sale: Sale, doing: string): MockRefusal {
  return refuse('invalid_transition', `A ${sale.status} sale cannot be ${doing}.`, {
    field: 'status',
    detail: sale.status,
  });
}

/** Refuses a body with any key (these four calls take none, except void). */
function onlyKeys(ctx: MockContext, allowed: readonly string[]): MockRefusal | null {
  const extra = Object.keys(ctx.body).find((k) => !allowed.includes(k));
  return extra === undefined ? null : invalid(extra, 'This field is not accepted.');
}

/** The next document number of a type at the branch: `INV-000001`, `RFD-000001`. */
export function nextDocumentNumber(branch: BranchState, type: 'sale' | 'refund'): string {
  const used = branch.sales.filter((s) => s.data.type === type && s.data.number !== null).length;
  return `${type === 'sale' ? 'INV' : 'RFD'}-${String(used + 1).padStart(6, '0')}`;
}

/** The negative of a decimal string, as a four-decimal string. */
export function negate(amount: string): Money {
  return sub(ZERO, amount) as Money;
}

/** Completes a sale: the checks of T-030 AC-14, then the writes. */
export function completeSaleIn(
  state: MockState,
  branch: BranchState,
  stored: StoredSale,
): MockResult {
  const sale = stored.data;
  if (sale.type !== 'sale' || (sale.status !== 'draft' && sale.status !== 'pending_payment')) {
    return invalidTransition(sale, 'completed');
  }
  if (sale.lines.length === 0) {
    return invalid('lines', 'A sale needs at least one line to complete.');
  }
  if (!isZero(sale.balance_due)) {
    return refuse('balance_due', `Balance remaining: ${sale.balance_due}`, {
      field: 'balance_due',
      detail: sale.balance_due,
    });
  }
  const creditTotal = sum(
    sale.tenders.filter((t) => t.method_type === 'credit').map((t) => t.amount),
  );
  const customer = state.seed.customers.find((c) => c.id === sale.customer?.id);
  if (cmp(creditTotal, ZERO) > 0) {
    if (!customer) {
      return refuse('customer_required', 'A store credit tender needs a customer on the sale.');
    }
    if (cmp(creditTotal, customer.credit_balance) > 0) {
      return refuse('credit_refused', "The customer's store credit is not enough.", {
        field: 'cause',
        detail: 'insufficient_credit',
      });
    }
  }
  const discountIds = [
    ...new Set(sale.lines.map((l) => l.discount_id).filter((d): d is string => d !== null)),
  ];
  for (const id of discountIds) {
    const discount = findDiscount(state.seed, id);
    if (discount && discount.usage_limit !== null && discount.used_count >= discount.usage_limit) {
      return refuse('usage_limit_reached', 'That discount has been used up.');
    }
  }

  tick(state);
  for (const id of discountIds) {
    const discount = findDiscount(state.seed, id);
    if (discount) {
      discount.used_count = discount.used_count + 1;
    }
  }
  if (customer && cmp(creditTotal, ZERO) > 0) {
    customer.credit_balance = sub(customer.credit_balance, creditTotal);
  }
  sale.number = nextDocumentNumber(branch, 'sale');
  sale.status = 'completed';
  sale.completed_at = tick(state);
  sale.change_due = sum(sale.tenders.map((t) => t.change_given)) as Money;
  return ok(sale);
}

/** Parks a draft or pending-payment sale. */
export function holdSaleIn(state: MockState, stored: StoredSale, actorName: string): MockResult {
  const sale = stored.data;
  if (sale.status !== 'draft' && sale.status !== 'pending_payment') {
    return invalidTransition(sale, 'held');
  }
  sale.held_from_status = sale.status;
  sale.status = 'held';
  sale.held_at = tick(state);
  sale.held_by_name = actorName;
  return ok(sale);
}

/**
 * Brings a held sale back to the status it was held from, onto this terminal and its open shift
 * (T-030 AC-18). Refused `no_open_shift` when this terminal has no open shift.
 */
export function recallSaleIn(
  state: MockState,
  branch: BranchState,
  stored: StoredSale,
): MockResult {
  const sale = stored.data;
  if (sale.status !== 'held' || sale.held_from_status === null) {
    return invalidTransition(sale, 'recalled');
  }
  const shift = openShiftOf(branch);
  if (!shift) {
    return refuse('no_open_shift', 'This terminal has no open shift.');
  }
  tick(state);
  sale.status = sale.held_from_status;
  sale.held_from_status = null;
  sale.held_at = null;
  sale.held_by_name = null;
  sale.shift_id = shift.data.id;
  stored.terminalId = branch.terminalId;
  return ok(sale);
}

/** What voiding a sale needs, and does (T-030 AC-21, AC-22). */
export function voidSaleIn(
  state: MockState,
  branch: BranchState,
  stored: StoredSale,
  reason: string,
  approvalId: string | undefined,
): MockResult {
  const sale = stored.data;
  const unfinished = ['draft', 'held', 'pending_payment'].includes(sale.status);
  if (sale.type !== 'sale' || (!unfinished && sale.status !== 'completed')) {
    return invalidTransition(sale, 'voided');
  }
  let consumes: StoredApproval | null = null;
  if (sale.status === 'completed') {
    if (approvalId === undefined) {
      return refuse('approval_required', 'A supervisor must approve voiding a completed sale.');
    }
    const binding: ApprovalBinding = {
      type: 'void',
      reference_type: 'sale',
      reference_id: sale.id,
      payload: null,
    };
    const checked = checkApproval(branch, approvalId, binding);
    if (isRefusal(checked)) {
      return checked;
    }
    consumes = checked;
    const shift = branch.shifts.find((s) => s.data.id === sale.shift_id);
    if (!shift || shift.data.status !== 'open') {
      return refuse('shift_closed', "The sale's shift is closed: refund it instead.");
    }
  }

  const stamp = tick(state);
  const mirrors: SaleTender[] = sale.tenders
    .filter((t) => cmp(t.amount, ZERO) > 0)
    .map((t) => ({
      ...t,
      id: nextId(state, 'tender'),
      amount: negate(t.amount),
      tendered: null,
      change_given: ZERO as Money,
      paid_at: stamp,
    }));
  if (sale.status === 'completed') {
    // A store credit tender was posted at completion; the void pays it back (T-030 AC-22).
    const credit = sum(sale.tenders.filter((t) => t.method_type === 'credit').map((t) => t.amount));
    const customer = state.seed.customers.find((c) => c.id === sale.customer?.id);
    if (customer && cmp(credit, ZERO) > 0) {
      customer.credit_balance = add(customer.credit_balance, credit);
    }
  }
  sale.tenders.push(...mirrors);
  sale.status = 'voided';
  sale.voided_at = stamp;
  sale.void_reason = reason;
  recalc(sale);
  if (consumes) {
    consumeApproval(state, consumes);
  }
  return ok(sale);
}

function complete(ctx: MockContext): MockResult {
  const stored = findStoredSale(ctx);
  if (isRefusal(stored)) {
    return stored;
  }
  return onlyKeys(ctx, []) ?? completeSaleIn(ctx.state, ctx.branch, stored);
}

function hold(ctx: MockContext): MockResult {
  const stored = findStoredSale(ctx);
  if (isRefusal(stored)) {
    return stored;
  }
  return onlyKeys(ctx, []) ?? holdSaleIn(ctx.state, stored, ctx.actorName);
}

function recall(ctx: MockContext): MockResult {
  const stored = findStoredSale(ctx);
  if (isRefusal(stored)) {
    return stored;
  }
  return onlyKeys(ctx, []) ?? recallSaleIn(ctx.state, ctx.branch, stored);
}

function voidSale(ctx: MockContext): MockResult {
  const stored = findStoredSale(ctx);
  if (isRefusal(stored)) {
    return stored;
  }
  const unknown = onlyKeys(ctx, ['reason', 'approval_id']);
  if (unknown) {
    return unknown;
  }
  const reason = readString(ctx.body, 'reason');
  if (isRefusal(reason)) {
    return reason;
  }
  let approvalId: string | undefined;
  if ('approval_id' in ctx.body) {
    const value = readString(ctx.body, 'approval_id');
    if (isRefusal(value)) {
      return value;
    }
    approvalId = value;
  }
  return voidSaleIn(ctx.state, ctx.branch, stored, reason, approvalId);
}

export function lifecycleHandlers(): HandlerTable {
  return {
    'sales.complete': complete,
    'sales.hold': hold,
    'sales.recall': recall,
    'sales.void': voidSale,
  };
}
