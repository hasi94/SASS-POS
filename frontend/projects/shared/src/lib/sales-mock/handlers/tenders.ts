import { SaleTender } from '../../sales/contract/sale';
import { Money } from '../../sales/decimal-string';
import { HandlerTable, MockContext } from '../mock-context';
import { MockResult, invalid, ok, refuse } from '../mock-envelope';
import { ZERO, cmp, format4, isZero, sub } from '../mock-decimal';
import { isRefusal, readDecimal, readOptionalString, readString, readUlid } from '../mock-input';
import { recalc } from '../mock-pricing';
import { SeedPaymentMethod } from '../mock-seed';
import { MockState, StoredSale, nextId, tick } from '../mock-state';
import { findStoredSale, invalidTransition, recallSaleIn } from './lifecycle';

/**
 * `sales.add-tender` and `sales.remove-tender` (T-030 AC-11 to AC-13A, T-037 P-1).
 *
 * The till sends what the customer handed over (`tendered`); the server derives what is applied
 * and what goes back as change. That derivation is the conformance obligation of the route layer:
 * `tendered <= balance_due` applies it all, `tendered > balance_due` on a method that allows
 * change applies `balance_due` and gives the rest back, and without `allows_change` it is
 * refused `exceeds_balance`.
 */

/** Method types that have no deduction mechanism behind them (T-030 AC-12, D-41). */
const UNSUPPORTED_TYPES: readonly string[] = ['loyalty', 'voucher'];

export interface TenderSplit {
  /** What is applied to the sale. */
  amount: string;
  /** What is handed back. */
  change: string;
}

/** The P-1 rule. Null means the tender is more than is owed and may not give change. */
export function splitTender(
  balanceDue: string,
  tendered: string,
  allowsChange: boolean,
): TenderSplit | null {
  if (cmp(tendered, balanceDue) <= 0) {
    return { amount: format4(tendered), change: ZERO };
  }
  if (!allowsChange) {
    return null;
  }
  return { amount: format4(balanceDue), change: sub(tendered, balanceDue) };
}

/** What a replay of a tender is compared against: the request triple of P-1. */
function tenderKey(methodId: string, tendered: string, reference: string | null): string {
  return JSON.stringify({ method: methodId, tendered: format4(tendered), reference });
}

/** Writes a tender onto a sale and moves the sale to `pending_payment`. The caller checked it. */
export function applyTender(
  state: MockState,
  stored: StoredSale,
  method: SeedPaymentMethod,
  request: { id: string; tendered: string; reference: string | null },
  split: TenderSplit,
): SaleTender {
  const tender: SaleTender = {
    id: request.id,
    payment_method_id: method.id,
    method_code: method.code,
    method_type: method.type as SaleTender['method_type'],
    amount: split.amount as Money,
    tendered: format4(request.tendered) as Money,
    change_given: split.change as Money,
    reference: request.reference,
    paid_at: tick(state),
  };
  stored.data.tenders.push(tender);
  stored.data.status = 'pending_payment';
  recalc(stored.data);
  return tender;
}

/** The ULID the mock gives a tender no request named (scenario steps). */
export function newTenderId(state: MockState): string {
  return nextId(state, 'tender');
}

const TENDER_FIELDS = ['id', 'payment_method_id', 'tendered', 'reference'];

function addTender(ctx: MockContext): MockResult {
  const stored = findStoredSale(ctx);
  if (isRefusal(stored)) {
    return stored;
  }
  const extra = Object.keys(ctx.body).find((k) => !TENDER_FIELDS.includes(k));
  if (extra !== undefined) {
    return invalid(extra, 'This field is not accepted.');
  }
  const id = readUlid(ctx.body, 'id');
  if (isRefusal(id)) {
    return id;
  }
  const methodId = readString(ctx.body, 'payment_method_id');
  if (isRefusal(methodId)) {
    return methodId;
  }
  const tendered = readDecimal(ctx.body, 'tendered', { allowZero: false });
  if (isRefusal(tendered)) {
    return tendered;
  }
  const reference = readOptionalString(ctx.body, 'reference');
  if (isRefusal(reference)) {
    return reference;
  }
  const cleanReference = reference === null || reference.trim() === '' ? null : reference.trim();

  const sale = stored.data;
  const earlier = ctx.branch.sales
    .flatMap((s) => s.data.tenders.map((t) => ({ sale: s, tender: t })))
    .find((e) => e.tender.id === id);
  if (earlier) {
    const same =
      earlier.sale === stored &&
      tenderKey(
        earlier.tender.payment_method_id,
        earlier.tender.tendered ?? '',
        earlier.tender.reference,
      ) === tenderKey(methodId, tendered, cleanReference);
    return same
      ? ok({ tender: earlier.tender, sale }, 200)
      : refuse('replay_conflict', 'That id was already used for a different tender.');
  }

  const method = ctx.state.seed.paymentMethods.find((m) => m.id === methodId);
  if (!method) {
    return refuse('not_found', 'Payment method not found.', {
      field: 'payment_method_id',
      detail: methodId,
    });
  }
  const heldFromPayment = sale.status === 'held' && sale.held_from_status === 'pending_payment';
  if (
    sale.type !== 'sale' ||
    (sale.status !== 'draft' && sale.status !== 'pending_payment' && !heldFromPayment)
  ) {
    return invalidTransition(sale, 'paid');
  }
  if (UNSUPPORTED_TYPES.includes(method.type)) {
    return refuse('unsupported_method_type', 'That payment method cannot be used for a sale.');
  }
  if (method.type === 'credit' && sale.customer === null) {
    return refuse('customer_required', 'Store credit needs a customer on the sale.');
  }
  if (method.requires_reference && cleanReference === null) {
    return invalid('reference', 'A reference is required for this payment method.');
  }
  if (isZero(sale.balance_due)) {
    return refuse('exceeds_balance', 'Nothing is owed on this sale.');
  }
  const split = splitTender(sale.balance_due, tendered, method.allows_change);
  if (!split) {
    return refuse(
      'exceeds_balance',
      'That is more than the balance and this method gives no change.',
    );
  }
  if (heldFromPayment) {
    // Tendering a sale held from payment is an implicit recall (T-030 AC-11).
    const recalled = recallSaleIn(ctx.state, ctx.branch, stored);
    if (!recalled.ok) {
      return recalled;
    }
  }
  const tender = applyTender(
    ctx.state,
    stored,
    method,
    { id, tendered, reference: cleanReference },
    split,
  );
  return ok({ tender, sale }, 201);
}

function removeTender(ctx: MockContext): MockResult {
  const stored = findStoredSale(ctx);
  if (isRefusal(stored)) {
    return stored;
  }
  const sale = stored.data;
  const index = sale.tenders.findIndex((t) => t.id === ctx.params['tenderId']);
  if (index === -1) {
    return refuse('not_found', 'Tender not found.', {
      field: 'tender_id',
      detail: ctx.params['tenderId'] ?? '',
    });
  }
  if (sale.status !== 'pending_payment') {
    return invalidTransition(sale, 'changed in payment');
  }
  tick(ctx.state);
  sale.tenders.splice(index, 1);
  if (sale.tenders.length === 0) {
    sale.status = 'draft';
  }
  recalc(sale);
  return ok(sale);
}

export function tenderHandlers(): HandlerTable {
  return {
    'sales.add-tender': addTender,
    'sales.remove-tender': removeTender,
  };
}
