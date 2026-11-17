import { RefundQuote, RefundQuoteLine, RefundResult } from '../../sales/contract/refund';
import { Sale, SaleLine, SaleTender } from '../../sales/contract/sale';
import { Money, Quantity } from '../../sales/decimal-string';
import { ZERO, add, cmp, format4, isZero, mul, sub, sum } from '../mock-decimal';
import { HandlerTable, MockContext } from '../mock-context';
import { MockRefusal, MockResult, invalid, ok, refuse } from '../mock-envelope';
import {
  asRecord,
  isRefusal,
  readDecimal,
  readOptionalString,
  readString,
  readUlid,
} from '../mock-input';
import { Priced, buildLine, findDiscount, findProduct, findUnit, priceLine } from '../mock-pricing';
import { SeedProduct, SeedProductUnit } from '../mock-seed';
import {
  BranchState,
  MockState,
  StoredSale,
  businessDate,
  nextId,
  openShiftOf,
  tick,
} from '../mock-state';
import { customerSummaryOf } from './catalog';
import { ApprovalBinding, checkApproval, consumeApproval } from './approvals';
import { negate, nextDocumentNumber } from './lifecycle';
import { summaryOf } from './sales';

/**
 * `refunds.quote` and `refunds.create` (T-030 AC-25 to AC-31, AC-12A/B, AC-27A/B, AC-54; T-037
 * P-7). A refund line is priced at what the original line was charged (never today's price), in
 * the original line's own unit, from a per-unit price table, so there is no division here.
 */

interface ParsedLine {
  refundOfItemId: string | null;
  productId: string | null;
  unitId: string | null;
  quantity: string;
}

interface ParsedTender {
  id: string;
  methodId: string;
  amount: string;
  reference: string | null;
}

interface QuotedLine {
  original: SaleLine | null;
  product: SeedProduct;
  unit: SeedProductUnit;
  quantity: string;
  priced: Priced;
  lineTotal: string;
  overridden: boolean;
}

/** What the quote rules need to know about the world: the mock's state and one branch of it. */
type Scope = Pick<MockContext, 'state' | 'branch'>;

interface Quoted {
  /** The sale being refunded; null for a goodwill refund. */
  original: StoredSale | null;
  lines: QuotedLine[];
  quote: RefundQuote;
}

const LINE_FIELDS = ['refund_of_item_id', 'product_id', 'unit_id', 'quantity'];
const TENDER_FIELDS = ['id', 'payment_method_id', 'amount', 'reference'];

function readReferenceSaleId(body: Readonly<Record<string, unknown>>): string | null | MockRefusal {
  if (!('reference_sale_id' in body)) {
    return invalid('reference_sale_id', 'The reference_sale_id is required (null for goodwill).');
  }
  return readOptionalString(body, 'reference_sale_id');
}

function readLines(body: Readonly<Record<string, unknown>>): ParsedLine[] | MockRefusal {
  const raw = body['lines'];
  if (!Array.isArray(raw) || raw.length === 0) {
    return invalid('lines', 'At least one line is required.');
  }
  const lines: ParsedLine[] = [];
  for (const item of raw as unknown[]) {
    const line = asRecord(item);
    const extra = Object.keys(line).find((k) => !LINE_FIELDS.includes(k));
    if (extra !== undefined) {
      return invalid(`lines.${extra}`, 'This field is not accepted.');
    }
    const itemId = readOptionalString(line, 'refund_of_item_id');
    const product = readOptionalString(line, 'product_id');
    const unit = readOptionalString(line, 'unit_id');
    const quantity = readDecimal(line, 'quantity', { allowZero: false });
    for (const part of [itemId, product, unit, quantity]) {
      if (isRefusal(part)) {
        return part;
      }
    }
    lines.push({
      refundOfItemId: itemId as string | null,
      productId: product as string | null,
      unitId: unit as string | null,
      quantity: format4(quantity as string),
    });
  }
  return lines;
}

function readTenders(body: Readonly<Record<string, unknown>>): ParsedTender[] | MockRefusal {
  const raw = body['tenders'];
  if (!Array.isArray(raw)) {
    return invalid('tenders', 'The tenders must be a list (empty for a zero refund).');
  }
  const tenders: ParsedTender[] = [];
  for (const item of raw as unknown[]) {
    const tender = asRecord(item);
    const extra = Object.keys(tender).find((k) => !TENDER_FIELDS.includes(k));
    if (extra !== undefined) {
      return invalid(`tenders.${extra}`, 'This field is not accepted.');
    }
    const id = readUlid(tender, 'id');
    const method = readString(tender, 'payment_method_id');
    const amount = readDecimal(tender, 'amount', { allowZero: false });
    const reference = readOptionalString(tender, 'reference');
    for (const part of [id, method, amount, reference]) {
      if (isRefusal(part)) {
        return part;
      }
    }
    const text = reference as string | null;
    tenders.push({
      id: id as string,
      methodId: method as string,
      amount: format4(amount as string),
      reference: text === null || text.trim() === '' ? null : text.trim(),
    });
  }
  return tenders;
}

/** What refund money has already been paid on one original line, in all refund sales. */
function refundedMoney(branch: BranchState, itemId: string): string {
  return sum(
    branch.sales
      .filter((s) => s.data.type === 'refund')
      .flatMap((s) => s.data.lines)
      .filter((l) => l.refund_of_item_id === itemId)
      .map((l) => l.line_total),
  );
}

function referencedLines(
  ctx: Scope,
  stored: StoredSale,
  parsed: ParsedLine[],
): QuotedLine[] | MockRefusal {
  const sale = stored.data;
  const seen = new Set<string>();
  const quoted: QuotedLine[] = [];
  for (const line of parsed) {
    if (line.refundOfItemId === null) {
      return invalid('refund_of_item_id', 'A refund of a sale names the line it refunds.');
    }
    if (seen.has(line.refundOfItemId)) {
      return invalid('refund_of_item_id', 'Each line can be refunded once per refund.');
    }
    seen.add(line.refundOfItemId);
    const original = sale.lines.find((l) => l.id === line.refundOfItemId);
    if (!original) {
      return refuse('not_found', 'Line not found on that sale.', {
        field: 'line_id',
        detail: line.refundOfItemId,
      });
    }
    if (line.productId !== null && line.productId !== original.product_id) {
      return invalid('product_id', 'The product is not the one on the original line.');
    }
    if (line.unitId !== null && line.unitId !== original.unit_id) {
      return refuse(
        'inherited_unit_mismatch',
        'A refund is made in the unit the line was sold in.',
        { field: 'unit_code', detail: original.unit_code },
      );
    }
    if (cmp(line.quantity, original.refundable_quantity) > 0) {
      return refuse('over_refund', 'More than is left to refund on this line.', {
        field: 'refundable_quantity',
        detail: original.refundable_quantity,
      });
    }
    const product = findProduct(ctx.state.seed, original.product_id);
    if (!product) {
      return refuse('unknown_product', 'That product no longer exists.');
    }
    const unit = findUnit(product, original.unit_id);
    if (!unit) {
      return refuse('unit_unresolvable', 'That unit does not apply to the product.', {
        field: 'unit_code',
        detail: original.unit_code,
      });
    }
    // Priced at what the line was charged: its own unit price (an override survives), its discount.
    const discount = original.discount_id
      ? findDiscount(ctx.state.seed, original.discount_id)
      : null;
    const priced = priceLine({
      product,
      unit,
      quantity: line.quantity,
      discount: discount ?? null,
      overridePrice: original.unit_price,
    });
    if (!priced) {
      return refuse('unresolvable_price', 'No price for this product.');
    }
    const left = sub(original.line_total, refundedMoney(ctx.branch, original.id));
    // The last quantity takes exactly what is left of the charge, so partial refunds sum to it.
    const last = cmp(line.quantity, original.refundable_quantity) === 0;
    const lineTotal = last ? left : priced.line_total;
    if (cmp(lineTotal, left) > 0) {
      return refuse('over_refund', 'More than is left to refund on this line.', {
        field: 'refundable_amount',
        detail: left,
      });
    }
    quoted.push({
      original,
      product,
      unit,
      quantity: line.quantity,
      priced,
      lineTotal,
      overridden: original.price_overridden,
    });
  }
  return quoted;
}

function goodwillLines(ctx: Scope, parsed: ParsedLine[]): QuotedLine[] | MockRefusal {
  const quoted: QuotedLine[] = [];
  for (const line of parsed) {
    if (line.refundOfItemId !== null) {
      return invalid('refund_of_item_id', 'A goodwill refund names no original line.');
    }
    if (line.productId === null) {
      return invalid('product_id', 'The product is required.');
    }
    if (line.unitId === null) {
      return invalid('unit_id', 'The unit is required.');
    }
    const product = findProduct(ctx.state.seed, line.productId);
    if (!product) {
      return refuse('unknown_product', 'That product is not known.');
    }
    const unit = findUnit(product, line.unitId);
    if (!unit) {
      const code = ctx.state.seed.units.find((u) => u.id === line.unitId)?.code ?? line.unitId;
      return refuse('unit_unresolvable', 'That unit does not apply to the product.', {
        field: 'unit_code',
        detail: code,
      });
    }
    const priced = priceLine({
      product,
      unit,
      quantity: line.quantity,
      discount: null,
      overridePrice: null,
    });
    if (!priced) {
      return refuse('unresolvable_price', 'No price for this product.');
    }
    quoted.push({
      original: null,
      product,
      unit,
      quantity: line.quantity,
      priced,
      lineTotal: priced.line_total,
      overridden: false,
    });
  }
  return quoted;
}

/** Finds and checks the sale a refund names, or says why it cannot be refunded. */
function refundableSale(ctx: Scope, saleId: string): StoredSale | MockRefusal {
  const stored = ctx.branch.sales.find((s) => s.data.id === saleId);
  if (!stored) {
    const elsewhere = [...ctx.state.branches.values()].some(
      (b) => b !== ctx.branch && b.sales.some((s) => s.data.id === saleId),
    );
    return elsewhere
      ? refuse('not_refundable', 'That sale belongs to another branch.')
      : refuse('not_found', 'Sale not found.', { field: 'reference_sale_id', detail: saleId });
  }
  const sale = stored.data;
  if (sale.type === 'sale' && sale.status === 'refunded') {
    return refuse('fully_refunded', 'Every line of that sale is already refunded.');
  }
  if (sale.type !== 'sale' || !['completed', 'partially_refunded'].includes(sale.status)) {
    return refuse('not_refundable', 'Only a completed sale can be refunded.');
  }
  if (sale.lines.every((l) => isZero(l.refundable_quantity))) {
    return refuse('fully_refunded', 'Every line of that sale is already refunded.');
  }
  return stored;
}

function quoteOf(
  ctx: Scope,
  referenceSaleId: string | null,
  parsed: ParsedLine[],
): Quoted | MockRefusal {
  let original: StoredSale | null = null;
  if (referenceSaleId !== null) {
    const found = refundableSale(ctx, referenceSaleId);
    if (isRefusal(found)) {
      return found;
    }
    original = found;
  }
  const lines = original ? referencedLines(ctx, original, parsed) : goodwillLines(ctx, parsed);
  if (isRefusal(lines)) {
    return lines;
  }
  const quoteLines: RefundQuoteLine[] = lines.map((l) => ({
    refund_of_item_id: l.original?.id ?? null,
    product_id: l.product.id,
    product_name: l.product.name,
    unit_code: l.unit.unit_code,
    quantity: l.quantity as Quantity,
    line_total: l.lineTotal as Money,
  }));
  const quote: RefundQuote = {
    currency: ctx.state.seed.currency,
    lines: quoteLines,
    subtotal: sum(lines.map((l) => l.priced.subtotal)) as Money,
    discount_total: sum(lines.map((l) => l.priced.discount_amount)) as Money,
    tax_total: sum(lines.map((l) => l.priced.tax_amount)) as Money,
    total: sum(lines.map((l) => l.lineTotal)) as Money,
  };
  return { original, lines, quote };
}

function quote(ctx: MockContext): MockResult {
  const extra = Object.keys(ctx.body).find((k) => !['reference_sale_id', 'lines'].includes(k));
  if (extra !== undefined) {
    return invalid(extra, 'This field is not accepted.');
  }
  const reference = readReferenceSaleId(ctx.body);
  if (isRefusal(reference)) {
    return reference;
  }
  const lines = readLines(ctx.body);
  if (isRefusal(lines)) {
    return lines;
  }
  const quoted = quoteOf(ctx, reference, lines);
  return isRefusal(quoted) ? quoted : ok(quoted.quote);
}

/**
 * What a replay of a refund is compared against, from the request alone (a referenced line is
 * named by the original line and quantity, a goodwill line by product, unit and quantity).
 */
function requestKey(
  referenceSaleId: string | null,
  lines: readonly ParsedLine[],
  tenders: readonly ParsedTender[],
): string {
  return JSON.stringify({
    sale: referenceSaleId,
    lines: lines.map((l) =>
      l.refundOfItemId !== null
        ? [l.refundOfItemId, l.quantity]
        : [null, l.productId, l.unitId, l.quantity],
    ),
    tenders: tenders.map((t) => [t.id, t.methodId, t.amount, t.reference]),
  });
}

function storedKey(refund: Sale): string {
  return JSON.stringify({
    sale: refund.reference_sale_id,
    lines: refund.lines.map((l) =>
      l.refund_of_item_id !== null
        ? [l.refund_of_item_id, format4(l.quantity)]
        : [null, l.product_id, l.unit_id, format4(l.quantity)],
    ),
    tenders: refund.tenders.map((t) => [
      t.id,
      t.payment_method_id,
      format4(negate(t.amount)),
      t.reference,
    ]),
  });
}

function resultOf(branch: BranchState, refund: Sale): RefundResult {
  const original = branch.sales.find((s) => s.data.id === refund.reference_sale_id);
  return { refund, original: original ? summaryOf(original.data) : null };
}

function create(ctx: MockContext): MockResult {
  const extra = Object.keys(ctx.body).find(
    (k) =>
      !['id', 'reference_sale_id', 'approval_id', 'customer_id', 'lines', 'tenders'].includes(k),
  );
  if (extra !== undefined) {
    return invalid(extra, 'This field is not accepted.');
  }
  const id = readUlid(ctx.body, 'id');
  if (isRefusal(id)) {
    return id;
  }
  const reference = readReferenceSaleId(ctx.body);
  if (isRefusal(reference)) {
    return reference;
  }
  const lines = readLines(ctx.body);
  if (isRefusal(lines)) {
    return lines;
  }
  const tenders = readTenders(ctx.body);
  if (isRefusal(tenders)) {
    return tenders;
  }
  const customerId = readOptionalString(ctx.body, 'customer_id');
  if (isRefusal(customerId)) {
    return customerId;
  }

  const earlier = ctx.branch.sales.find((s) => s.data.id === id);
  if (earlier) {
    return earlier.data.type === 'refund' &&
      storedKey(earlier.data) === requestKey(reference, lines, tenders)
      ? ok(resultOf(ctx.branch, earlier.data), 200)
      : refuse('replay_conflict', 'That id was already used for a different refund.');
  }
  const shift = openShiftOf(ctx.branch);
  if (!shift) {
    return refuse('no_open_shift', 'This terminal has no open shift.');
  }
  const quoted = quoteOf(ctx, reference, lines);
  if (isRefusal(quoted)) {
    return quoted;
  }

  const approvalId = readOptionalString(ctx.body, 'approval_id');
  if (isRefusal(approvalId)) {
    return approvalId;
  }
  if (approvalId === null) {
    return refuse('approval_required', 'A supervisor must approve a refund.');
  }
  const binding: ApprovalBinding = {
    type: 'refund',
    reference_type: reference === null ? 'goodwill_refund' : 'sale',
    reference_id: reference,
    payload: null,
  };
  const approval = checkApproval(ctx.branch, approvalId, binding);
  if (isRefusal(approval)) {
    return approval;
  }

  // A referenced refund keeps the original's customer; a goodwill refund may name one.
  let customer = quoted.original?.data.customer ?? null;
  if (!quoted.original && customerId !== null) {
    const found = ctx.state.seed.customers.find((c) => c.id === customerId);
    if (!found) {
      return refuse('not_found', 'Customer not found.', {
        field: 'customer_id',
        detail: customerId,
      });
    }
    customer = customerSummaryOf(found);
  }

  const total = quoted.quote.total;
  const methods = ctx.state.seed.paymentMethods;
  const ids = new Set<string>();
  for (const tender of tenders) {
    const method = methods.find((m) => m.id === tender.methodId);
    if (!method) {
      return refuse('not_found', 'Payment method not found.', {
        field: 'payment_method_id',
        detail: tender.methodId,
      });
    }
    if (ids.has(tender.id)) {
      return invalid('tenders', 'Each tender needs its own id.');
    }
    ids.add(tender.id);
    if (!method.allows_refund) {
      return refuse('unsupported_method_type', 'That payment method cannot pay a refund out.');
    }
    if (method.type === 'credit' && customer === null) {
      return refuse('customer_required', 'Store credit needs a customer on the refund.');
    }
    if (method.requires_reference && tender.reference === null) {
      return invalid('reference', 'A reference is required for this payment method.');
    }
  }
  const payout = sum(tenders.map((t) => t.amount));
  if (cmp(payout, total) > 0) {
    return refuse('exceeds_balance', 'That is more than is owed back.');
  }
  if (cmp(payout, total) < 0) {
    const remaining = sub(total, payout);
    return refuse('balance_due', `Balance remaining: ${remaining}`, {
      field: 'balance_due',
      detail: remaining,
    });
  }

  const result = commit(
    ctx.state,
    ctx.branch,
    id,
    quoted,
    tenders,
    customer,
    ctx.actorName,
    shift.data.id,
  );
  consumeApproval(ctx.state, approval);
  return ok(result, 201);
}

function commit(
  state: MockState,
  branch: BranchState,
  id: string,
  quoted: Quoted,
  tenders: ParsedTender[],
  customer: Sale['customer'],
  actorName: string,
  shiftId: string,
): RefundResult {
  const stamp = tick(state);
  const refundLines: SaleLine[] = quoted.lines.map((l, index) => {
    const line = buildLine({
      id: nextId(state, 'line'),
      lineNo: index + 1,
      product: l.product,
      unit: l.unit,
      quantity: l.quantity,
      priced: l.priced,
      discount: null,
      overridden: false,
    });
    line.discount_id = l.original?.discount_id ?? null;
    line.line_total = l.lineTotal as Money;
    line.refund_of_item_id = l.original?.id ?? null;
    line.refundable_quantity = ZERO as Quantity;
    return line;
  });
  const refundTenders: SaleTender[] = tenders.map((t) => {
    const method = state.seed.paymentMethods.find((m) => m.id === t.methodId);
    return {
      id: t.id,
      payment_method_id: t.methodId,
      method_code: method?.code ?? '',
      method_type: (method?.type ?? 'other') as SaleTender['method_type'],
      amount: negate(t.amount),
      tendered: null,
      change_given: ZERO as Money,
      reference: t.reference,
      paid_at: stamp,
    };
  });
  const refund: Sale = {
    id,
    type: 'refund',
    number: nextDocumentNumber(branch, 'refund'),
    reference: null,
    status: 'completed',
    held_from_status: null,
    currency: state.seed.currency,
    customer,
    shift_id: shiftId,
    business_date: businessDate(state),
    subtotal: quoted.quote.subtotal,
    discount_total: quoted.quote.discount_total,
    tax_total: quoted.quote.tax_total,
    total: quoted.quote.total,
    paid_total: negate(sum(tenders.map((t) => t.amount))),
    balance_due: ZERO as Money,
    change_due: ZERO as Money,
    lines: refundLines,
    tenders: refundTenders,
    reference_sale_id: quoted.original?.data.id ?? null,
    held_at: null,
    held_by_name: null,
    completed_at: stamp,
    voided_at: null,
    void_reason: null,
    employee_name: actorName,
  };
  branch.sales.push({ terminalId: branch.terminalId, data: refund });

  // Pay a store credit refund back onto the customer's balance.
  const credit = sum(
    refundTenders.filter((t) => t.method_type === 'credit').map((t) => negate(t.amount)),
  );
  const owner = state.seed.customers.find((c) => c.id === customer?.id);
  if (owner && cmp(credit, ZERO) > 0) {
    owner.credit_balance = add(owner.credit_balance, credit);
  }

  if (quoted.original) {
    const original = quoted.original.data;
    for (const l of quoted.lines) {
      const line = original.lines.find((o) => o.id === l.original?.id);
      if (line) {
        line.refunded_quantity = add(
          line.refunded_quantity,
          mul(l.quantity, line.unit_factor),
        ) as Quantity;
        line.refundable_quantity = sub(line.refundable_quantity, l.quantity) as Quantity;
      }
    }
    original.status = original.lines.every((l) => isZero(l.refundable_quantity))
      ? 'refunded'
      : 'partially_refunded';
  }
  return resultOf(branch, refund);
}

/**
 * Refunds one line of a completed sale outright, paying the whole of it back in the first
 * allowed method. For scenario steps: it walks the same quote and the same writes as a request.
 */
export function refundLineIn(
  ctx: Scope & { actorName: string },
  originalSaleId: string,
  refundId: string,
  tenderId: string,
  methodId: string,
  quantity: string | null,
): void {
  const stored = ctx.branch.sales.find((s) => s.data.id === originalSaleId);
  const line = stored?.data.lines[0];
  if (!stored || !line) {
    throw new Error('This step needs a completed sale before it.');
  }
  const parsed: ParsedLine[] = [
    {
      refundOfItemId: line.id,
      productId: null,
      unitId: null,
      quantity: format4(quantity ?? line.refundable_quantity),
    },
  ];
  const quoted = quoteOf(ctx, originalSaleId, parsed);
  if (isRefusal(quoted)) {
    throw new Error(`Scenario refund was refused: ${quoted.code}`);
  }
  const tenders: ParsedTender[] = isZero(quoted.quote.total)
    ? []
    : [{ id: tenderId, methodId, amount: quoted.quote.total, reference: null }];
  const shift = openShiftOf(ctx.branch);
  commit(
    ctx.state,
    ctx.branch,
    refundId,
    quoted,
    tenders,
    stored.data.customer,
    ctx.actorName,
    shift?.data.id ?? stored.data.shift_id ?? '',
  );
}

export function refundHandlers(): HandlerTable {
  return {
    'refunds.quote': quote,
    'refunds.create': create,
  };
}
