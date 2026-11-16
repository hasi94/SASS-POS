/**
 * Shared vocabulary of the sales contract (T-037 AC-1, AC-2). Every type under `contract/` is the
 * single declaration of what crosses the wire between the till and the Sales API; no component
 * declares an ad-hoc response shape. Field names are the T-030 column names, and every money and
 * quantity field is a branded decimal string (see `../decimal-string.ts`), never a number.
 */

/** A 26 character ULID. Ids the till makes itself (tender, cash movement, refund) are these. */
export type Ulid = string;

export type SaleId = string;
export type SaleLineId = string;
export type TenderId = string;
export type ShiftId = string;
export type ProductId = string;
export type UnitId = string;
export type CategoryId = string;
export type CustomerId = string;
export type PaymentMethodId = string;
export type ApprovalId = string;
export type DiscountId = string;

/** The currency code the server supplied, shown as given. The till converts nothing (D-25). */
export type Currency = string;

/** One page of a cursor-paginated list. `next_cursor` is null on the last page. */
export interface Page<T> {
  items: T[];
  next_cursor: string | null;
}
