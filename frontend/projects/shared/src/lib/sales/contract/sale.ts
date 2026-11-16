import { Money, Quantity } from '../decimal-string';
import { CustomerSummary, PaymentMethodType } from './catalog';
import {
  ApprovalId,
  Currency,
  CustomerId,
  DiscountId,
  PaymentMethodId,
  ProductId,
  SaleId,
  SaleLineId,
  ShiftId,
  TenderId,
  UnitId,
  Ulid,
} from './common';

export type SaleStatus =
  'draft' | 'pending_payment' | 'held' | 'completed' | 'voided' | 'partially_refunded' | 'refunded';

export const SALE_STATUSES: readonly SaleStatus[] = [
  'draft',
  'pending_payment',
  'held',
  'completed',
  'voided',
  'partially_refunded',
  'refunded',
];

export type SaleType = 'sale' | 'refund';

/**
 * One sale line, with T-030's `sale_items` column names. Every figure is the server's string:
 * the till never computes a price, a discount, a tax or a total (D-6).
 */
export interface SaleLine {
  id: SaleLineId;
  line_no: number;
  product_id: ProductId;
  product_name: string;
  sku: string;
  unit_id: UnitId;
  unit_code: string;
  quantity: Quantity;
  unit_factor: string;
  /** In the product's stock unit (`base_unit_code`). */
  base_quantity: Quantity;
  base_unit_code: string;
  list_price: Money;
  unit_price: Money;
  /** The line amount before the discount. */
  subtotal: Money;
  discount_id: DiscountId | null;
  discount_amount: Money;
  tax_amount: Money;
  line_total: Money;
  price_overridden: boolean;
  /** Set on a refund line: the original line it refunds. */
  refund_of_item_id: SaleLineId | null;
  /** Already refunded, in the stock unit (T-030 `refunded_quantity`). */
  refunded_quantity: Quantity;
  /** Server-derived (P-7, Q-3): what is still refundable, in this line's own unit. */
  refundable_quantity: Quantity;
}

/**
 * A recorded tender. `amount` is what was applied to the sale, `tendered` what the customer
 * handed over, `change_given` the difference handed back (T-030 `sale_tenders`).
 */
export interface SaleTender {
  id: TenderId;
  payment_method_id: PaymentMethodId;
  method_code: string;
  method_type: PaymentMethodType;
  amount: Money;
  tendered: Money | null;
  change_given: Money;
  reference: string | null;
  paid_at: string;
}

export interface Sale {
  id: SaleId;
  type: SaleType;
  /** Allocated at completion; null before. */
  number: string | null;
  /** A short server-supplied reference for a sale that has no number yet (AC-51). */
  reference: string | null;
  status: SaleStatus;
  /** The status a held sale was parked from; null when not held. */
  held_from_status: 'draft' | 'pending_payment' | null;
  currency: Currency;
  customer: CustomerSummary | null;
  shift_id: ShiftId | null;
  business_date: string;
  subtotal: Money;
  discount_total: Money;
  tax_total: Money;
  total: Money;
  paid_total: Money;
  balance_due: Money;
  change_due: Money;
  lines: SaleLine[];
  tenders: SaleTender[];
  reference_sale_id: SaleId | null;
  held_at: string | null;
  held_by_name: string | null;
  completed_at: string | null;
  voided_at: string | null;
  void_reason: string | null;
  employee_name: string;
}

/** A sale as listed (held list, this shift's completed sales, the resume lookup). */
export interface SaleSummary {
  id: SaleId;
  type: SaleType;
  number: string | null;
  reference: string | null;
  status: SaleStatus;
  held_from_status: 'draft' | 'pending_payment' | null;
  currency: Currency;
  total: Money;
  customer_name: string | null;
  line_count: number;
  held_at: string | null;
  held_by_name: string | null;
  completed_at: string | null;
  employee_name: string;
}

/** `POST sales`. Branch, terminal, shift and business date are the server's to resolve (AC-86). */
export interface CreateSaleRequest {
  customer_id?: CustomerId | null;
}

export interface AddLineRequest {
  product_id: ProductId;
  unit_id: UnitId;
  quantity: Quantity;
  discount_id?: DiscountId | null;
  /** A price override; needs `approval_id` (AC-34). */
  unit_price?: Money;
  approval_id?: ApprovalId;
}

export interface UpdateLineRequest {
  quantity?: Quantity;
  unit_id?: UnitId;
  /** `null` takes the discount off. */
  discount_id?: DiscountId | null;
  unit_price?: Money;
  approval_id?: ApprovalId;
}

/** `PUT sales/{id}/customer`. @proposed (P-7): T-030 has no slice for it yet. */
export interface AttachCustomerRequest {
  customer_id: CustomerId;
}

/**
 * `POST sales/{id}/tenders` (P-1, resolves D-8).
 *
 * The till sends what the customer handed over and nothing else: this type has **no** `amount`
 * and **no** `change_given` key, so the till cannot send them (a compile error, not a
 * convention). The server derives both. The derivation the route layer owes before calling
 * T-030's `AddTender` (which takes `amount` and `tendered` both), pinned by fixtures:
 *
 * - `tendered <= balance_due`: `amount = tendered`, `change_given = 0.0000`.
 * - `tendered > balance_due` on a method that `allows_change`: `amount = balance_due` and
 *   `change_given = tendered - balance_due`.
 * - `tendered > balance_due` on a method that does not allow change: refused `exceeds_balance`.
 *
 * Replay key: the request triple `{payment_method_id, tendered, reference}` under the same `id`.
 */
export interface AddTenderRequest {
  /** Idempotency key: one per submission, kept for retries of that submission (D-12). */
  id: Ulid;
  payment_method_id: PaymentMethodId;
  tendered: Money;
  reference?: string | null;
}

export interface AddTenderResponse {
  tender: SaleTender;
  sale: Sale;
}

export interface VoidRequest {
  reason: string;
  /** Required when the sale is completed (a `void` approval bound to it). */
  approval_id?: ApprovalId;
}

export type SaleListScope = 'current_terminal' | 'current_shift' | 'branch';

/** `GET sales`. Each field is a query parameter; `status` is sent comma separated. */
export interface ListSalesQuery {
  status?: SaleStatus[];
  scope?: SaleListScope;
  number?: string;
  type?: SaleType;
  cursor?: string;
  limit?: number;
}
