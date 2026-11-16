import { Money, Quantity } from '../decimal-string';
import {
  ApprovalId,
  Currency,
  CustomerId,
  PaymentMethodId,
  ProductId,
  SaleId,
  SaleLineId,
  Ulid,
  UnitId,
} from './common';
import { Sale, SaleSummary } from './sale';

/**
 * One line of a refund. A referenced refund names the original line (`refund_of_item_id`) and the
 * quantity in that line's own unit; a goodwill refund names the product and unit instead. The
 * till never sends a price: refund prices are the server's (AC-61).
 */
export interface RefundLineRequest {
  refund_of_item_id?: SaleLineId | null;
  product_id?: ProductId;
  unit_id?: UnitId;
  quantity: Quantity;
}

/** `POST refunds/quote`. @proposed (P-7): read-only, writes nothing. */
export interface RefundQuoteRequest {
  reference_sale_id: SaleId | null;
  lines: RefundLineRequest[];
}

export interface RefundQuoteLine {
  refund_of_item_id: SaleLineId | null;
  product_id: ProductId;
  product_name: string;
  unit_code: string;
  quantity: Quantity;
  line_total: Money;
}

export interface RefundQuote {
  currency: Currency;
  lines: RefundQuoteLine[];
  subtotal: Money;
  discount_total: Money;
  tax_total: Money;
  total: Money;
}

/**
 * A refund tender: a positive payout (T-030 AC-30 stores it negative; AC-12B stores `tendered`
 * as null), so it has no `tendered` and no change.
 */
export interface RefundTenderRequest {
  id: Ulid;
  payment_method_id: PaymentMethodId;
  amount: Money;
  reference?: string | null;
}

/** `POST refunds`. `id` is a client ULID, the idempotency key (@proposed, P-7). */
export interface RefundRequest {
  id: Ulid;
  /** Null for a goodwill refund. */
  reference_sale_id: SaleId | null;
  approval_id: ApprovalId;
  customer_id?: CustomerId | null;
  lines: RefundLineRequest[];
  tenders: RefundTenderRequest[];
}

export interface RefundResult {
  refund: Sale;
  /** The original sale after the refund (new status); null for a goodwill refund. */
  original: SaleSummary | null;
}
