import { Page } from '../../sales/contract/common';
import { SALE_STATUSES, Sale, SaleLine, SaleStatus, SaleSummary } from '../../sales/contract/sale';
import { Money } from '../../sales/decimal-string';
import { ZERO } from '../mock-decimal';
import { HandlerTable, MockContext } from '../mock-context';
import { MockRefusal, MockResult, invalid, ok, refuse } from '../mock-envelope';
import { isRefusal, readDecimal, readOptionalString, readString } from '../mock-input';
import {
  buildLine,
  findDiscount,
  findProduct,
  findUnit,
  priceLine,
  Priced,
  recalc,
  unsellableFact,
} from '../mock-pricing';
import { SeedDiscount, SeedProduct, SeedProductUnit } from '../mock-seed';
import {
  BranchState,
  MockState,
  StoredApproval,
  StoredSale,
  businessDate,
  nextId,
  nextReference,
  openShiftOf,
  tick,
} from '../mock-state';
import { customerSummaryOf } from './catalog';
import { ApprovalBinding, checkApproval, consumeApproval } from './approvals';

/** A line can be changed only while the sale is a draft, or held from a draft (T-030 AC-6). */
export function isEditable(sale: Sale): boolean {
  return sale.status === 'draft' || (sale.status === 'held' && sale.held_from_status === 'draft');
}

/** Opens a draft sale on the branch's open shift. The caller has checked that one is open. */
export function createSaleIn(
  state: MockState,
  branch: BranchState,
  employeeName: string,
  customerId: string | null,
  terminalId: string = branch.terminalId,
): StoredSale {
  const customer = state.seed.customers.find((c) => c.id === customerId);
  const sale: Sale = {
    id: nextId(state, 'sale'),
    type: 'sale',
    number: null,
    reference: nextReference(state),
    status: 'draft',
    held_from_status: null,
    currency: state.seed.currency,
    customer: customer ? customerSummaryOf(customer) : null,
    shift_id: openShiftOf(branch)?.data.id ?? null,
    business_date: businessDate(state),
    subtotal: ZERO as Money,
    discount_total: ZERO as Money,
    tax_total: ZERO as Money,
    total: ZERO as Money,
    paid_total: ZERO as Money,
    balance_due: ZERO as Money,
    change_due: ZERO as Money,
    lines: [],
    tenders: [],
    reference_sale_id: null,
    held_at: null,
    held_by_name: null,
    completed_at: null,
    voided_at: null,
    void_reason: null,
    employee_name: employeeName,
  };
  tick(state);
  const stored: StoredSale = { terminalId, data: sale };
  branch.sales.push(stored);
  return stored;
}

export function summaryOf(sale: Sale): SaleSummary {
  return {
    id: sale.id,
    type: sale.type,
    number: sale.number,
    reference: sale.reference,
    status: sale.status,
    held_from_status: sale.held_from_status,
    currency: sale.currency,
    total: sale.total,
    customer_name: sale.customer?.name ?? null,
    line_count: sale.lines.length,
    held_at: sale.held_at,
    held_by_name: sale.held_by_name,
    completed_at: sale.completed_at,
    employee_name: sale.employee_name,
  };
}

/** What a line change asks for. `null` means "no discount"; `undefined` means "not mentioned". */
export interface LineSpec {
  productId: string;
  unitId: string;
  quantity: string;
  discountId: string | null | undefined;
  unitPrice: string | undefined;
  approvalId: string | undefined;
  /** The line being changed, when it is a change and not an add. */
  existing: SaleLine | null;
}

export interface ResolvedLine {
  product: SeedProduct;
  unit: SeedProductUnit;
  discount: SeedDiscount | null;
  overridePrice: string | null;
  /** Approvals this change consumes once it succeeds. */
  consumes: StoredApproval[];
}

/**
 * The shared rules of add-line and update-line (T-030 AC-2 to AC-7, AC-23a, AC-47, AC-55):
 * sellability, unit, then override and discount with their approvals. Returns what the pricing
 * needs, or the refusal.
 */
export function resolveLine(
  state: MockState,
  branch: BranchState,
  saleId: string,
  spec: LineSpec,
): ResolvedLine | MockRefusal {
  const product = findProduct(state.seed, spec.productId);
  if (!product) {
    return refuse('not_found', 'Product not found.', {
      field: 'product_id',
      detail: spec.productId,
    });
  }
  const failed = unsellableFact(product);
  if (failed) {
    return refuse('product_not_sellable', 'This product cannot be sold.', {
      field: 'failed_fact',
      detail: failed,
    });
  }
  const unit = findUnit(product, spec.unitId);
  if (!unit) {
    const unitCode = state.seed.units.find((u) => u.id === spec.unitId)?.code ?? spec.unitId;
    return refuse('unit_unresolvable', 'That unit does not apply to the product.', {
      field: 'unit_code',
      detail: unitCode,
    });
  }

  const existing = spec.existing;
  const sameUnit = existing !== null && existing.unit_id === unit.unit_id;
  // A price override already on the line survives a quantity change; a changed unit drops it.
  const keptOverride = existing !== null && existing.price_overridden && sameUnit;
  const overridePrice = spec.unitPrice ?? (keptOverride ? existing.unit_price : null);
  const wantsNewOverride = spec.unitPrice !== undefined;
  const discountId =
    spec.discountId === undefined ? (existing?.discount_id ?? null) : spec.discountId;
  const consumes: StoredApproval[] = [];

  if (overridePrice !== null) {
    if (!product.allow_price_override) {
      return refuse(
        'price_override_not_allowed',
        'Price override is not allowed for this product.',
      );
    }
    if (discountId !== null) {
      return refuse(
        'discount_with_override',
        'A line with a price override cannot take a discount.',
      );
    }
    if (wantsNewOverride) {
      if (spec.approvalId === undefined) {
        return refuse('approval_required', 'A supervisor must approve a price override.');
      }
      const binding: ApprovalBinding = {
        type: 'price_override',
        reference_type: 'sale',
        reference_id: saleId,
        payload: {
          product_id: product.id,
          unit_id: unit.unit_id,
          unit_price: overridePrice as Money,
        },
      };
      const checked = checkApproval(branch, spec.approvalId, binding);
      if (isRefusal(checked)) {
        return checked;
      }
      consumes.push(checked);
    }
    return { product, unit, discount: null, overridePrice, consumes };
  }

  if (discountId === null) {
    return { product, unit, discount: null, overridePrice: null, consumes };
  }
  const discount = findDiscount(state.seed, discountId);
  if (!discount) {
    return refuse('unknown_discount', 'That discount does not exist.', {
      field: 'discount_id',
      detail: discountId,
    });
  }
  if (!discount.is_active) {
    return refuse('inactive', 'That discount is no longer active.');
  }
  if (!product.allow_discount) {
    return refuse('discount_not_allowed', 'Discounts are not allowed on this product.');
  }
  if (discount.category_ids.length > 0 && !discount.category_ids.includes(product.category_id)) {
    return refuse('not_applicable', 'That discount does not apply to this product.');
  }
  const unchanged = existing !== null && existing.discount_id === discount.id;
  if (discount.requires_approval && !unchanged) {
    if (spec.approvalId === undefined) {
      return refuse('approval_required', 'A supervisor must approve this discount.');
    }
    const binding: ApprovalBinding = {
      type: 'discount',
      reference_type: 'sale',
      reference_id: saleId,
      payload: { discount_id: discount.id },
    };
    const checked = checkApproval(branch, spec.approvalId, binding);
    if (isRefusal(checked)) {
      return checked;
    }
    consumes.push(checked);
  }
  return { product, unit, discount, overridePrice: null, consumes };
}

/** Prices a resolved line, or refuses `unresolvable_price`. */
export function priceResolved(resolved: ResolvedLine, quantity: string): Priced | MockRefusal {
  const priced = priceLine({
    product: resolved.product,
    unit: resolved.unit,
    quantity,
    discount: resolved.discount,
    overridePrice: resolved.overridePrice,
  });
  return priced ?? refuse('unresolvable_price', 'No price for this product.');
}

/**
 * Adds a line to a stored sale. Used by the add-line handler and by scenarios, so a scenario
 * line is priced exactly as a requested one.
 */
export function addLineTo(
  state: MockState,
  branch: BranchState,
  stored: StoredSale,
  spec: Omit<LineSpec, 'existing'>,
): MockResult {
  const resolved = resolveLine(state, branch, stored.data.id, { ...spec, existing: null });
  if (isRefusal(resolved)) {
    return resolved;
  }
  const priced = priceResolved(resolved, spec.quantity);
  if (isRefusal(priced)) {
    return priced;
  }
  tick(state);
  const line = buildLine({
    id: nextId(state, 'line'),
    lineNo: stored.data.lines.length + 1,
    product: resolved.product,
    unit: resolved.unit,
    quantity: spec.quantity,
    priced,
    discount: resolved.discount,
    overridden: resolved.overridePrice !== null,
  });
  stored.data.lines.push(line);
  recalc(stored.data);
  for (const approval of resolved.consumes) {
    consumeApproval(state, approval);
  }
  return ok(stored.data);
}

const LINE_FIELDS = [
  'product_id',
  'unit_id',
  'quantity',
  'discount_id',
  'unit_price',
  'approval_id',
];
const UPDATE_FIELDS = ['quantity', 'unit_id', 'discount_id', 'unit_price', 'approval_id'];

function rejectUnknown(
  body: Readonly<Record<string, unknown>>,
  allowed: readonly string[],
): MockRefusal | null {
  const extra = Object.keys(body).find((k) => !allowed.includes(k));
  return extra === undefined ? null : invalid(extra, 'This field is not accepted.');
}

function findSale(ctx: MockContext): StoredSale | MockRefusal {
  const sale = ctx.branch.sales.find((s) => s.data.id === ctx.params['id']);
  return sale ?? refuse('not_found', 'Sale not found.');
}

function notEditable(): MockRefusal {
  return refuse('sale_not_editable', 'Payment has started: remove the tenders to edit lines.');
}

function create(ctx: MockContext): MockResult {
  const unknown = rejectUnknown(ctx.body, ['customer_id']);
  if (unknown) {
    return unknown;
  }
  const customerId = readOptionalString(ctx.body, 'customer_id');
  if (isRefusal(customerId)) {
    return customerId;
  }
  if (!openShiftOf(ctx.branch)) {
    return refuse('no_open_shift', 'This terminal has no open shift.');
  }
  if (customerId !== null && !ctx.state.seed.customers.some((c) => c.id === customerId)) {
    return refuse('not_found', 'Customer not found.', { field: 'customer_id', detail: customerId });
  }
  return ok(createSaleIn(ctx.state, ctx.branch, ctx.actorName, customerId).data, 201);
}

function get(ctx: MockContext): MockResult {
  const sale = findSale(ctx);
  return isRefusal(sale) ? sale : ok(sale.data);
}

function list(ctx: MockContext): MockResult {
  const statusText = ctx.query('status');
  let statuses: SaleStatus[] | null = null;
  if (statusText !== null) {
    const parts = statusText.split(',');
    const bad = parts.find((p) => !SALE_STATUSES.includes(p as SaleStatus));
    if (bad !== undefined) {
      return invalid('status', `"${bad}" is not a sale status.`);
    }
    statuses = parts as SaleStatus[];
  }
  const scope = ctx.query('scope') ?? 'current_terminal';
  if (!['current_terminal', 'current_shift', 'branch'].includes(scope)) {
    return invalid('scope', 'The scope must be current_terminal, current_shift or branch.');
  }
  const type = ctx.query('type');
  if (type !== null && type !== 'sale' && type !== 'refund') {
    return invalid('type', 'The type must be sale or refund.');
  }
  const limitText = ctx.query('limit') ?? '50';
  if (!/^[1-9]\d{0,2}$/.test(limitText) || parseInt(limitText, 10) > 50) {
    return invalid('limit', 'The limit must be a whole number from 1 to 50.');
  }
  const limit = parseInt(limitText, 10);
  const cursorText = ctx.query('cursor');
  let offset = 0;
  if (cursorText !== null) {
    const m = /^c(\d+)$/.exec(cursorText);
    if (!m) {
      return invalid('cursor', 'The cursor is not one this server issued.');
    }
    offset = parseInt(m[1] ?? '0', 10);
  }
  const number = ctx.query('number');
  const shiftId = openShiftOf(ctx.branch)?.data.id ?? null;
  const matching = ctx.branch.sales
    .filter((s) => {
      if (scope === 'current_terminal' && s.terminalId !== ctx.branch.terminalId) {
        return false;
      }
      if (scope === 'current_shift' && (shiftId === null || s.data.shift_id !== shiftId)) {
        return false;
      }
      if (statuses !== null && !statuses.includes(s.data.status)) {
        return false;
      }
      if (type !== null && s.data.type !== type) {
        return false;
      }
      return number === null || s.data.number === number;
    })
    .reverse();
  const items = matching.slice(offset, offset + limit).map((s) => summaryOf(s.data));
  const page: Page<SaleSummary> = {
    items,
    next_cursor: offset + limit < matching.length ? `c${offset + limit}` : null,
  };
  return ok(page);
}

function addLine(ctx: MockContext): MockResult {
  const stored = findSale(ctx);
  if (isRefusal(stored)) {
    return stored;
  }
  const unknown = rejectUnknown(ctx.body, LINE_FIELDS);
  if (unknown) {
    return unknown;
  }
  const productId = readString(ctx.body, 'product_id');
  if (isRefusal(productId)) {
    return productId;
  }
  const unitId = readString(ctx.body, 'unit_id');
  if (isRefusal(unitId)) {
    return unitId;
  }
  const quantity = readDecimal(ctx.body, 'quantity', { allowZero: false });
  if (isRefusal(quantity)) {
    return quantity;
  }
  const extras = readLineExtras(ctx.body);
  if (isRefusal(extras)) {
    return extras;
  }
  if (!isEditable(stored.data)) {
    return notEditable();
  }
  return addLineTo(ctx.state, ctx.branch, stored, {
    productId,
    unitId,
    quantity,
    discountId: extras.discountId,
    unitPrice: extras.unitPrice,
    approvalId: extras.approvalId,
  });
}

interface LineExtras {
  discountId: string | null | undefined;
  unitPrice: string | undefined;
  approvalId: string | undefined;
}

function readLineExtras(body: Readonly<Record<string, unknown>>): LineExtras | MockRefusal {
  let discountId: string | null | undefined;
  if ('discount_id' in body) {
    const value = readOptionalString(body, 'discount_id');
    if (isRefusal(value)) {
      return value;
    }
    discountId = value;
  }
  let unitPrice: string | undefined;
  if ('unit_price' in body) {
    const value = readDecimal(body, 'unit_price', { allowZero: true });
    if (isRefusal(value)) {
      return value;
    }
    unitPrice = value;
  }
  let approvalId: string | undefined;
  if ('approval_id' in body) {
    const value = readString(body, 'approval_id');
    if (isRefusal(value)) {
      return value;
    }
    approvalId = value;
  }
  return { discountId, unitPrice, approvalId };
}

function updateLine(ctx: MockContext): MockResult {
  const stored = findSale(ctx);
  if (isRefusal(stored)) {
    return stored;
  }
  const index = stored.data.lines.findIndex((l) => l.id === ctx.params['lineId']);
  const existing = stored.data.lines[index];
  if (!existing) {
    return refuse('not_found', 'Line not found.', {
      field: 'line_id',
      detail: ctx.params['lineId'] ?? '',
    });
  }
  const unknown = rejectUnknown(ctx.body, UPDATE_FIELDS);
  if (unknown) {
    return unknown;
  }
  let quantity = existing.quantity as string;
  if ('quantity' in ctx.body) {
    const value = readDecimal(ctx.body, 'quantity', { allowZero: false });
    if (isRefusal(value)) {
      return value;
    }
    quantity = value;
  }
  let unitId = existing.unit_id;
  if ('unit_id' in ctx.body) {
    const value = readString(ctx.body, 'unit_id');
    if (isRefusal(value)) {
      return value;
    }
    unitId = value;
  }
  const extras = readLineExtras(ctx.body);
  if (isRefusal(extras)) {
    return extras;
  }
  if (!isEditable(stored.data)) {
    return notEditable();
  }
  const resolved = resolveLine(ctx.state, ctx.branch, stored.data.id, {
    productId: existing.product_id,
    unitId,
    quantity,
    discountId: extras.discountId,
    unitPrice: extras.unitPrice,
    approvalId: extras.approvalId,
    existing,
  });
  if (isRefusal(resolved)) {
    return resolved;
  }
  const priced = priceResolved(resolved, quantity);
  if (isRefusal(priced)) {
    return priced;
  }
  tick(ctx.state);
  stored.data.lines[index] = buildLine({
    id: existing.id,
    lineNo: existing.line_no,
    product: resolved.product,
    unit: resolved.unit,
    quantity,
    priced,
    discount: resolved.discount,
    overridden: resolved.overridePrice !== null,
  });
  recalc(stored.data);
  for (const approval of resolved.consumes) {
    consumeApproval(ctx.state, approval);
  }
  return ok(stored.data);
}

function removeLine(ctx: MockContext): MockResult {
  const stored = findSale(ctx);
  if (isRefusal(stored)) {
    return stored;
  }
  const index = stored.data.lines.findIndex((l) => l.id === ctx.params['lineId']);
  if (index === -1) {
    return refuse('not_found', 'Line not found.', {
      field: 'line_id',
      detail: ctx.params['lineId'] ?? '',
    });
  }
  if (!isEditable(stored.data)) {
    return notEditable();
  }
  tick(ctx.state);
  stored.data.lines.splice(index, 1);
  recalc(stored.data);
  return ok(stored.data);
}

function attachCustomer(ctx: MockContext): MockResult {
  const stored = findSale(ctx);
  if (isRefusal(stored)) {
    return stored;
  }
  const unknown = rejectUnknown(ctx.body, ['customer_id']);
  if (unknown) {
    return unknown;
  }
  const customerId = readString(ctx.body, 'customer_id');
  if (isRefusal(customerId)) {
    return customerId;
  }
  if (!isEditable(stored.data)) {
    return notEditable();
  }
  const customer = ctx.state.seed.customers.find((c) => c.id === customerId);
  if (!customer) {
    return refuse('not_found', 'Customer not found.', { field: 'customer_id', detail: customerId });
  }
  tick(ctx.state);
  stored.data.customer = customerSummaryOf(customer);
  return ok(stored.data);
}

export function saleHandlers(): HandlerTable {
  return {
    'sales.create': create,
    'sales.get': get,
    'sales.list': list,
    'sales.add-line': addLine,
    'sales.update-line': updateLine,
    'sales.remove-line': removeLine,
    'sales.attach-customer': attachCustomer,
  };
}
