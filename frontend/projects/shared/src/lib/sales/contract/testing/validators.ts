/**
 * One validator per response type (T-037 P-13), and the map from call id to the validator of its
 * success body. Key-exhaustive: adding a key to a contract type is a compile error here until the
 * validator lists it. Test-only.
 */
import { Approval, ApprovalPayload } from '../approval';
import { SalesCallId } from '../calls';
import {
  ApplicableDiscount,
  CustomerSummary,
  PaymentMethod,
  ScanResolution,
  TillCategory,
  TillProduct,
  TillProductUnit,
} from '../catalog';
import { Page } from '../common';
import { RefundQuote, RefundQuoteLine, RefundResult } from '../refund';
import { AddTenderResponse, Sale, SaleLine, SaleSummary, SaleTender, SALE_STATUSES } from '../sale';
import { CASH_MOVEMENT_TYPES, CashMovement, CashShift, ClosedShift } from '../shift';
import { ApiErrorItem } from '../../../api/envelope';
import {
  Check,
  arrayOf,
  bool,
  int,
  money,
  nullable,
  oneOf,
  partialShape,
  quantity,
  recordOf,
  shape,
  str,
  ulid,
} from './shape';

const PAYMENT_METHOD_TYPES = [
  'cash',
  'card',
  'digital',
  'credit',
  'voucher',
  'bank',
  'loyalty',
  'other',
];

export const customerSummary: Check = shape<CustomerSummary>({
  id: ulid,
  code: str,
  name: str,
  phone: nullable(str),
  credit_balance: money,
});

const cashShiftFields = {
  id: ulid,
  number: str,
  status: oneOf(['open', 'closed', 'reconciled']),
  terminal_id: str,
  employee_name: str,
  opened_at: str,
  closed_at: nullable(str),
  opening_cash: money,
};

export const cashShift: Check = shape<CashShift>(cashShiftFields);

export const closedShift: Check = shape<ClosedShift>({
  ...cashShiftFields,
  counted_cash: money,
  expected_cash: money,
  variance: money,
  sales_count: int,
  refunds_count: int,
  gross: money,
  discounts: money,
  tax: money,
  net: money,
  totals_by_method: recordOf(money),
});

export const cashMovement: Check = shape<CashMovement>({
  id: ulid,
  shift_id: ulid,
  type: oneOf(CASH_MOVEMENT_TYPES),
  amount: money,
  reason: str,
  employee_name: str,
  created_at: str,
});

export const saleLine: Check = shape<SaleLine>({
  id: ulid,
  line_no: int,
  product_id: ulid,
  product_name: str,
  sku: str,
  unit_id: ulid,
  unit_code: str,
  quantity,
  unit_factor: str,
  base_quantity: quantity,
  base_unit_code: str,
  list_price: money,
  unit_price: money,
  subtotal: money,
  discount_id: nullable(ulid),
  discount_amount: money,
  tax_amount: money,
  line_total: money,
  price_overridden: bool,
  refund_of_item_id: nullable(ulid),
  refunded_quantity: quantity,
  refundable_quantity: quantity,
});

export const saleTender: Check = shape<SaleTender>({
  id: ulid,
  payment_method_id: ulid,
  method_code: str,
  method_type: oneOf(PAYMENT_METHOD_TYPES),
  amount: money,
  tendered: nullable(money),
  change_given: money,
  reference: nullable(str),
  paid_at: str,
});

export const sale: Check = shape<Sale>({
  id: ulid,
  type: oneOf(['sale', 'refund']),
  number: nullable(str),
  reference: nullable(str),
  status: oneOf(SALE_STATUSES),
  held_from_status: nullable(oneOf(['draft', 'pending_payment'])),
  currency: str,
  customer: nullable(customerSummary),
  shift_id: nullable(ulid),
  business_date: str,
  subtotal: money,
  discount_total: money,
  tax_total: money,
  total: money,
  paid_total: money,
  balance_due: money,
  change_due: money,
  lines: arrayOf(saleLine),
  tenders: arrayOf(saleTender),
  reference_sale_id: nullable(ulid),
  held_at: nullable(str),
  held_by_name: nullable(str),
  completed_at: nullable(str),
  voided_at: nullable(str),
  void_reason: nullable(str),
  employee_name: str,
});

export const saleSummary: Check = shape<SaleSummary>({
  id: ulid,
  type: oneOf(['sale', 'refund']),
  number: nullable(str),
  reference: nullable(str),
  status: oneOf(SALE_STATUSES),
  held_from_status: nullable(oneOf(['draft', 'pending_payment'])),
  currency: str,
  total: money,
  customer_name: nullable(str),
  line_count: int,
  held_at: nullable(str),
  held_by_name: nullable(str),
  completed_at: nullable(str),
  employee_name: str,
});

export const addTenderResponse: Check = shape<AddTenderResponse>({
  tender: saleTender,
  sale,
});

const approvalPayload: Check = partialShape<ApprovalPayload>({
  product_id: ulid,
  unit_id: ulid,
  unit_price: money,
  discount_id: ulid,
});

export const approval: Check = shape<Approval>({
  id: ulid,
  type: oneOf(['void', 'refund', 'price_override', 'discount']),
  reference_type: oneOf(['sale', 'goodwill_refund']),
  reference_id: nullable(str),
  payload: nullable(approvalPayload),
  status: oneOf(['pending', 'approved', 'rejected']),
  requested_by_name: str,
  decided_by_name: nullable(str),
  decided_at: nullable(str),
  consumed_at: nullable(str),
});

const refundQuoteLine: Check = shape<RefundQuoteLine>({
  refund_of_item_id: nullable(ulid),
  product_id: ulid,
  product_name: str,
  unit_code: str,
  quantity,
  line_total: money,
});

export const refundQuote: Check = shape<RefundQuote>({
  currency: str,
  lines: arrayOf(refundQuoteLine),
  subtotal: money,
  discount_total: money,
  tax_total: money,
  total: money,
});

export const refundResult: Check = shape<RefundResult>({
  refund: sale,
  original: nullable(saleSummary),
});

export const tillCategory: Check = shape<TillCategory>({ id: ulid, name: str });

const tillProductUnit: Check = shape<TillProductUnit>({ unit_id: ulid, unit_code: str });

export const tillProduct: Check = shape<TillProduct>({
  id: ulid,
  name: str,
  sku: str,
  plu: nullable(str),
  category_id: ulid,
  list_price: money,
  currency: str,
  is_weighable: bool,
  unit_id: ulid,
  unit_code: str,
  weigh_unit_id: nullable(ulid),
  weigh_unit_code: nullable(str),
  units: arrayOf(tillProductUnit),
  allow_price_override: bool,
});

export const scanResolution: Check = shape<ScanResolution>({
  kind: oneOf(['product', 'embedded_weight', 'embedded_price']),
  product: tillProduct,
  unit_id: ulid,
  unit_code: str,
  quantity: nullable(quantity),
  source: oneOf(['barcode', 'plu', 'rule']),
});

export const paymentMethod: Check = shape<PaymentMethod>({
  id: ulid,
  code: str,
  name: str,
  type: oneOf(PAYMENT_METHOD_TYPES),
  opens_drawer: bool,
  requires_reference: bool,
  allows_change: bool,
  allows_refund: bool,
  allows_partial: bool,
});

export const applicableDiscount: Check = shape<ApplicableDiscount>({
  id: ulid,
  code: str,
  name: str,
  requires_approval: bool,
  description: str,
});

/** A `Page<T>` of whatever `item` validates. */
export function page<T>(item: Check): Check {
  return shape<Page<T>>({ items: arrayOf(item), next_cursor: nullable(str) });
}

/** The validator of each call's `data` on success. */
export const SUCCESS_VALIDATORS: Record<SalesCallId, Check> = {
  'shifts.current': nullable(cashShift),
  'shifts.open': cashShift,
  'shifts.movement': cashMovement,
  'shifts.close': closedShift,
  'sales.create': sale,
  'sales.get': sale,
  'sales.list': page<SaleSummary>(saleSummary),
  'sales.add-line': sale,
  'sales.update-line': sale,
  'sales.remove-line': sale,
  'sales.attach-customer': sale,
  'sales.add-tender': addTenderResponse,
  'sales.remove-tender': sale,
  'sales.complete': sale,
  'sales.hold': sale,
  'sales.recall': sale,
  'sales.void': sale,
  'approvals.request': approval,
  'approvals.decide': approval,
  'refunds.quote': refundQuote,
  'refunds.create': refundResult,
  'payment-methods.list': arrayOf(paymentMethod),
  'till.categories': arrayOf(tillCategory),
  'till.products': page<TillProduct>(tillProduct),
  'till.scan': scanResolution,
  'discounts.applicable': arrayOf(applicableDiscount),
  'customers.lookup': arrayOf(customerSummary),
};

const errorItem: Check = shape<Required<ApiErrorItem>>({
  code: str,
  field: nullable(str),
  detail: str,
});

/** The refusal envelope every refused call returns (T-021): `success false`, `data null`. */
export const REFUSAL_ENVELOPE: Check = (value, path) => {
  const problem = shape<{
    success: boolean;
    data: null;
    message: string;
    errors: ApiErrorItem[];
    meta: { request_id: string };
  }>({
    success: bool,
    data: (v, p) => (v === null ? null : `${p}: expected null`),
    message: str,
    errors: arrayOf(errorItem),
    meta: shape<{ request_id: string }>({ request_id: str }),
  })(value, path);
  if (problem) {
    return problem;
  }
  const record = value as { success: boolean; errors: unknown[] };
  if (record.success !== false) {
    return `${path}.success: a refusal must have success false`;
  }
  return record.errors.length > 0
    ? null
    : `${path}.errors: a refusal must carry at least one error`;
};

/** The success envelope around `data`: `success true`, an empty `errors`, a `meta.request_id`. */
export function successEnvelope(data: Check): Check {
  return (value, path) => {
    const problem = shape<{
      success: boolean;
      data: unknown;
      message: string;
      errors: ApiErrorItem[];
      meta: { request_id: string };
    }>({
      success: bool,
      data,
      message: str,
      errors: arrayOf(errorItem),
      meta: shape<{ request_id: string }>({ request_id: str }),
    })(value, path);
    if (problem) {
      return problem;
    }
    const record = value as { success: boolean; errors: unknown[] };
    if (record.success !== true) {
      return `${path}.success: a success body must have success true`;
    }
    return record.errors.length === 0 ? null : `${path}.errors: a success body has no errors`;
  };
}
