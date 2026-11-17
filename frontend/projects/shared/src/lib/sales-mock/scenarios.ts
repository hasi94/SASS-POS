import { SaleTender } from '../sales/contract/sale';
import { Money } from '../sales/decimal-string';
import { format4 } from './mock-decimal';
import { completeSaleIn, voidSaleIn } from './handlers/lifecycle';
import { refundLineIn } from './handlers/refunds';
import { addLineTo, createSaleIn } from './handlers/sales';
import { closeShiftIn, openShiftIn, recordMovementIn } from './handlers/shifts';
import { applyTender, newTenderId, splitTender } from './handlers/tenders';
import { isRefusal } from './mock-input';
import { findDiscount, recalc } from './mock-pricing';
import { seedId } from './mock-seed';
import {
  BranchState,
  MockState,
  StoredSale,
  branchState,
  nextId,
  openShiftOf,
  tick,
} from './mock-state';

/**
 * Named `given` steps applied straight to the mock's state, for fixtures and specs (T-037 P-11).
 * A step is one fact about the world ("a shift is open", "a sale has a line"); a fixture lists
 * the steps it needs in order, then sends its request. Steps never go through HTTP, so they do
 * not advance the request counter and a fixture's first answer is always request 000001.
 */
export interface ScenarioContext {
  state: MockState;
  branch: BranchState;
  actorName: string;
}

export type Scenario = (ctx: ScenarioContext) => void;

/** The demo products by their seeded ids, so a step reads as what it means. */
const RICE = seedId('P', 1);
const POSTER = seedId('P', 5);
const SOAP = seedId('P', 6);
const EACH = seedId('N', 1);
const SACHET = seedId('P', 4);
const CASH_METHOD = seedId('M', 1);
const CREDIT_METHOD = seedId('M', 3);
const CUSTOMER_WITH_CREDIT = seedId('K', 1);
const CUSTOMER_WITHOUT_CREDIT = seedId('K', 2);
const USED_UP_DISCOUNT = seedId('D', 4);
/** The ids the refund steps use, so a fixture can replay the very request a step made. */
export const SCENARIO_REFUND_ID = seedId('Z', 1);
export const SCENARIO_REFUND_TENDER_ID = seedId('Y', 1);
const OTHER_TERMINAL = 'TRM-OTHER';

function requireOpenShift(ctx: ScenarioContext): void {
  if (!openShiftOf(ctx.branch)) {
    throw new Error('This step needs a shift_open step before it.');
  }
}

function latestSale(ctx: ScenarioContext): StoredSale {
  const sale = ctx.branch.sales[ctx.branch.sales.length - 1];
  if (!sale) {
    throw new Error('This step needs a sale step before it.');
  }
  return sale;
}

function addLine(
  ctx: ScenarioContext,
  sale: StoredSale,
  productId: string,
  quantity: string,
): void {
  const result = addLineTo(ctx.state, ctx.branch, sale, {
    productId,
    unitId: EACH,
    quantity,
    discountId: undefined,
    unitPrice: undefined,
    approvalId: undefined,
  });
  if (isRefusal(result)) {
    throw new Error(`Scenario line was refused: ${result.code}`);
  }
}

function recordCashTender(ctx: ScenarioContext, sale: StoredSale, amount: string): void {
  const tender: SaleTender = {
    id: nextId(ctx.state, 'tender'),
    payment_method_id: CASH_METHOD,
    method_code: 'CASH',
    method_type: 'cash',
    amount: format4(amount) as Money,
    tendered: format4(amount) as Money,
    change_given: '0.0000' as Money,
    reference: null,
    paid_at: tick(ctx.state),
  };
  sale.data.tenders.push(tender);
  sale.data.status = 'pending_payment';
  recalc(sale.data);
}

function hold(ctx: ScenarioContext, sale: StoredSale): void {
  sale.data.held_from_status = sale.data.status === 'pending_payment' ? 'pending_payment' : 'draft';
  sale.data.status = 'held';
  sale.data.held_at = tick(ctx.state);
  sale.data.held_by_name = ctx.actorName;
}

const shiftOpen: Scenario = (ctx) => {
  openShiftIn(ctx.state, ctx.branch, '5000.0000', ctx.actorName);
};

const shiftClosed: Scenario = (ctx) => {
  const shift = openShiftIn(ctx.state, ctx.branch, '5000.0000', ctx.actorName);
  closeShiftIn(ctx.state, ctx.branch, shift, '5000.0000');
};

const draftSaleEmpty: Scenario = (ctx) => {
  requireOpenShift(ctx);
  createSaleIn(ctx.state, ctx.branch, ctx.actorName, null);
};

const draftSaleWithLine: Scenario = (ctx) => {
  requireOpenShift(ctx);
  addLine(ctx, createSaleIn(ctx.state, ctx.branch, ctx.actorName, null), RICE, '1.0000');
};

const draftSaleWithPosterLine: Scenario = (ctx) => {
  requireOpenShift(ctx);
  addLine(ctx, createSaleIn(ctx.state, ctx.branch, ctx.actorName, null), POSTER, '1.0000');
};

const pendingPaymentSale: Scenario = (ctx) => {
  requireOpenShift(ctx);
  const sale = createSaleIn(ctx.state, ctx.branch, ctx.actorName, null);
  addLine(ctx, sale, RICE, '1.0000');
  recordCashTender(ctx, sale, '100.0000');
};

const heldFromDraft: Scenario = (ctx) => {
  requireOpenShift(ctx);
  const sale = createSaleIn(ctx.state, ctx.branch, ctx.actorName, null);
  addLine(ctx, sale, RICE, '1.0000');
  hold(ctx, sale);
};

const heldFromPendingPayment: Scenario = (ctx) => {
  requireOpenShift(ctx);
  const sale = createSaleIn(ctx.state, ctx.branch, ctx.actorName, null);
  addLine(ctx, sale, RICE, '1.0000');
  recordCashTender(ctx, sale, '100.0000');
  hold(ctx, sale);
};

const heldSaleOtherTerminal: Scenario = (ctx) => {
  requireOpenShift(ctx);
  const sale = createSaleIn(ctx.state, ctx.branch, 'Cashier Perera', null, OTHER_TERMINAL);
  addLine(ctx, sale, RICE, '2.0000');
  hold(ctx, sale);
};

const cashMovementRecorded: Scenario = (ctx) => {
  const shift = openShiftOf(ctx.branch);
  if (!shift) {
    throw new Error('This step needs a shift_open step before it.');
  }
  recordMovementIn(
    ctx.state,
    ctx.branch,
    shift,
    { id: seedId('X', 1), type: 'cash_in', amount: '500.0000', reason: 'Float top-up' },
    ctx.actorName,
  );
};

const draftSaleWithDiscountedLine: Scenario = (ctx) => {
  requireOpenShift(ctx);
  const sale = createSaleIn(ctx.state, ctx.branch, ctx.actorName, null);
  const result = addLineTo(ctx.state, ctx.branch, sale, {
    productId: SOAP,
    unitId: EACH,
    quantity: '2.0000',
    discountId: seedId('D', 1),
    unitPrice: undefined,
    approvalId: undefined,
  });
  if (isRefusal(result)) {
    throw new Error(`Scenario line was refused: ${result.code}`);
  }
};

/** An approved, unused `price_override` approval for the poster, bound to the latest sale. */
const approvedPriceOverride: Scenario = (ctx) => {
  const sale = latestSale(ctx);
  ctx.branch.approvals.push({
    branchId: ctx.branch.branchId,
    requestedById: seedId('E', 1),
    data: {
      id: nextId(ctx.state, 'approval'),
      type: 'price_override',
      reference_type: 'sale',
      reference_id: sale.data.id,
      payload: { product_id: POSTER, unit_id: EACH, unit_price: '1200.0000' as Money },
      status: 'approved',
      requested_by_name: ctx.actorName,
      decided_by_name: 'Supervisor Silva',
      decided_at: tick(ctx.state),
      consumed_at: null,
    },
  });
};

/** An approved, unused `discount` approval for the manager discount, bound to the latest sale. */
const approvedDiscount: Scenario = (ctx) => {
  const sale = latestSale(ctx);
  const discount = findDiscount(ctx.state.seed, seedId('D', 2));
  ctx.branch.approvals.push({
    branchId: ctx.branch.branchId,
    requestedById: seedId('E', 1),
    data: {
      id: nextId(ctx.state, 'approval'),
      type: 'discount',
      reference_type: 'sale',
      reference_id: sale.data.id,
      payload: { discount_id: discount?.id ?? '' },
      status: 'approved',
      requested_by_name: ctx.actorName,
      decided_by_name: 'Supervisor Silva',
      decided_at: tick(ctx.state),
      consumed_at: null,
    },
  });
};

/** Records a tender through the same split rule a request goes through. */
function pay(
  ctx: ScenarioContext,
  sale: StoredSale,
  methodId: string,
  tendered: string,
  reference: string | null = null,
): void {
  const method = ctx.state.seed.paymentMethods.find((m) => m.id === methodId);
  if (!method) {
    throw new Error('Scenario names an unknown payment method.');
  }
  const split = splitTender(sale.data.balance_due, tendered, method.allows_change);
  if (!split) {
    throw new Error('Scenario tender is more than the balance.');
  }
  applyTender(ctx.state, sale, method, { id: newTenderId(ctx.state), tendered, reference }, split);
}

function complete(ctx: ScenarioContext, sale: StoredSale): void {
  const result = completeSaleIn(ctx.state, ctx.branch, sale);
  if (isRefusal(result)) {
    throw new Error(`Scenario completion was refused: ${result.code}`);
  }
}

function draftWith(
  ctx: ScenarioContext,
  customerId: string | null,
  productId: string,
  quantity: string,
): StoredSale {
  requireOpenShift(ctx);
  const sale = createSaleIn(ctx.state, ctx.branch, ctx.actorName, customerId);
  addLine(ctx, sale, productId, quantity);
  return sale;
}

const draftSaleWithFiveRice: Scenario = (ctx) => {
  draftWith(ctx, null, RICE, '5.0000');
};

const draftSaleWithCustomerLine: Scenario = (ctx) => {
  draftWith(ctx, CUSTOMER_WITH_CREDIT, RICE, '1.0000');
};

const draftSaleWithZeroPricedLine: Scenario = (ctx) => {
  draftWith(ctx, null, SACHET, '1.0000');
};

const paidSale: Scenario = (ctx) => {
  pay(ctx, draftWith(ctx, null, RICE, '1.0000'), CASH_METHOD, '250.0000');
};

const overpaidSale: Scenario = (ctx) => {
  pay(ctx, draftWith(ctx, null, RICE, '1.0000'), CASH_METHOD, '300.0000');
};

const creditPaidSale: Scenario = (ctx) => {
  pay(ctx, draftWith(ctx, CUSTOMER_WITH_CREDIT, RICE, '1.0000'), CREDIT_METHOD, '250.0000');
};

const creditShortSale: Scenario = (ctx) => {
  pay(ctx, draftWith(ctx, CUSTOMER_WITHOUT_CREDIT, RICE, '1.0000'), CREDIT_METHOD, '250.0000');
};

/** A paid sale whose discount has used up its limit: completion is refused. */
const usedUpDiscountSale: Scenario = (ctx) => {
  requireOpenShift(ctx);
  const sale = createSaleIn(ctx.state, ctx.branch, ctx.actorName, null);
  const result = addLineTo(ctx.state, ctx.branch, sale, {
    productId: SOAP,
    unitId: EACH,
    quantity: '1.0000',
    discountId: USED_UP_DISCOUNT,
    unitPrice: undefined,
    approvalId: undefined,
  });
  if (isRefusal(result)) {
    throw new Error(`Scenario line was refused: ${result.code}`);
  }
  pay(ctx, sale, CASH_METHOD, sale.data.total);
};

const completedSaleOpenShift: Scenario = (ctx) => {
  const sale = draftWith(ctx, null, RICE, '2.0000');
  pay(ctx, sale, CASH_METHOD, '500.0000');
  complete(ctx, sale);
};

const closeOpenShift: Scenario = (ctx) => {
  const shift = openShiftOf(ctx.branch);
  if (!shift) {
    throw new Error('This step needs a shift_open step before it.');
  }
  closeShiftIn(ctx.state, ctx.branch, shift, '5000.0000');
};

const completedSaleClosedShift: Scenario = (ctx) => {
  completedSaleOpenShift(ctx);
  closeOpenShift(ctx);
};

const completedCreditSale: Scenario = (ctx) => {
  const sale = draftWith(ctx, CUSTOMER_WITH_CREDIT, RICE, '1.0000');
  pay(ctx, sale, CREDIT_METHOD, '250.0000');
  complete(ctx, sale);
};

const completedZeroSale: Scenario = (ctx) => {
  complete(ctx, draftWith(ctx, null, SACHET, '1.0000'));
};

const voidedSale: Scenario = (ctx) => {
  const sale = draftWith(ctx, null, RICE, '1.0000');
  const result = voidSaleIn(ctx.state, ctx.branch, sale, 'Scenario void', undefined);
  if (isRefusal(result)) {
    throw new Error(`Scenario void was refused: ${result.code}`);
  }
};

/** A completed sale at another branch of the same tenant: this branch may not refund it. */
const completedSaleOtherBranch: Scenario = (ctx) => {
  const other = branchState(ctx.state, ctx.branch.tenantId, 'BR-OTHER');
  openShiftIn(ctx.state, other, '5000.0000', ctx.actorName);
  const otherCtx: ScenarioContext = { state: ctx.state, branch: other, actorName: ctx.actorName };
  const sale = draftWith(otherCtx, null, RICE, '1.0000');
  pay(otherCtx, sale, CASH_METHOD, '250.0000');
  complete(otherCtx, sale);
};

/** Rice x2 sold and then fully refunded in cash by a refund the scenario made. */
const refundedSale: Scenario = (ctx) => {
  completedSaleOpenShift(ctx);
  const sale = latestSale(ctx);
  refundLineIn(ctx, sale.data.id, SCENARIO_REFUND_ID, SCENARIO_REFUND_TENDER_ID, CASH_METHOD, null);
};

/** Rice x2 sold and one of the two refunded in cash. */
const partiallyRefundedSale: Scenario = (ctx) => {
  completedSaleOpenShift(ctx);
  const sale = latestSale(ctx);
  refundLineIn(
    ctx,
    sale.data.id,
    SCENARIO_REFUND_ID,
    SCENARIO_REFUND_TENDER_ID,
    CASH_METHOD,
    '1.0000',
  );
};

/** The sale an approval step binds to: the latest sale that is not a refund. */
function latestNonRefund(ctx: ScenarioContext): StoredSale {
  const sale = [...ctx.branch.sales].reverse().find((s) => s.data.type !== 'refund');
  if (!sale) {
    throw new Error('This step needs a sale step before it.');
  }
  return sale;
}

/** An approval of `void` or `refund` bound to the latest sale (or, for goodwill, to no sale). */
function approvalFor(
  ctx: ScenarioContext,
  type: 'void' | 'refund',
  status: 'pending' | 'approved' | 'rejected',
  consumed: boolean,
  goodwill: boolean,
): void {
  const sale = goodwill ? null : latestNonRefund(ctx);
  ctx.branch.approvals.push({
    branchId: ctx.branch.branchId,
    requestedById: seedId('E', 1),
    data: {
      id: nextId(ctx.state, 'approval'),
      type,
      reference_type: sale ? 'sale' : 'goodwill_refund',
      reference_id: sale?.data.id ?? null,
      payload: null,
      status,
      requested_by_name: ctx.actorName,
      decided_by_name: status === 'pending' ? null : 'Supervisor Silva',
      decided_at: status === 'pending' ? null : tick(ctx.state),
      consumed_at: consumed ? tick(ctx.state) : null,
    },
  });
}

/** Every step by name. */
export function scenarioTable(): Record<string, Scenario> {
  return {
    shift_open: shiftOpen,
    shift_closed: shiftClosed,
    draft_sale_empty: draftSaleEmpty,
    draft_sale_with_line: draftSaleWithLine,
    draft_sale_with_poster_line: draftSaleWithPosterLine,
    draft_sale_with_discounted_line: draftSaleWithDiscountedLine,
    cash_movement_recorded: cashMovementRecorded,
    pending_payment_sale: pendingPaymentSale,
    held_from_draft: heldFromDraft,
    held_from_pending_payment: heldFromPendingPayment,
    held_sale_other_terminal: heldSaleOtherTerminal,
    approved_price_override: approvedPriceOverride,
    approved_discount: approvedDiscount,
    draft_sale_with_five_rice: draftSaleWithFiveRice,
    draft_sale_with_customer_line: draftSaleWithCustomerLine,
    draft_sale_with_zero_priced_line: draftSaleWithZeroPricedLine,
    paid_sale: paidSale,
    overpaid_sale: overpaidSale,
    credit_paid_sale: creditPaidSale,
    credit_short_sale: creditShortSale,
    used_up_discount_sale: usedUpDiscountSale,
    completed_sale_open_shift: completedSaleOpenShift,
    completed_sale_closed_shift: completedSaleClosedShift,
    completed_credit_sale: completedCreditSale,
    completed_zero_sale: completedZeroSale,
    completed_sale_other_branch: completedSaleOtherBranch,
    voided_sale: voidedSale,
    refunded_sale: refundedSale,
    partially_refunded_sale: partiallyRefundedSale,
    close_open_shift: closeOpenShift,
    pending_void_approval: (ctx) => approvalFor(ctx, 'void', 'pending', false, false),
    approved_void_approval: (ctx) => approvalFor(ctx, 'void', 'approved', false, false),
    rejected_void_approval: (ctx) => approvalFor(ctx, 'void', 'rejected', false, false),
    used_void_approval: (ctx) => approvalFor(ctx, 'void', 'approved', true, false),
    approved_refund_approval: (ctx) => approvalFor(ctx, 'refund', 'approved', false, false),
    approved_goodwill_refund_approval: (ctx) => approvalFor(ctx, 'refund', 'approved', false, true),
  };
}

/** Applies the steps in order to one tenant and branch of the mock's state. */
export function applyGiven(
  state: MockState,
  tenantId: string,
  branchId: string,
  actorName: string,
  steps: readonly string[],
): void {
  const table = scenarioTable();
  const branch = branchState(state, tenantId, branchId);
  for (const step of steps) {
    const scenario = table[step];
    if (!scenario) {
      throw new Error(`Unknown scenario step: ${step}`);
    }
    scenario({ state, branch, actorName });
  }
}
