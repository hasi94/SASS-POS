import { Money } from '../decimal-string';
import { ApprovalId, DiscountId, ProductId, UnitId } from './common';

/** The only approval types the till can meet (T-030 AC-23). */
export type ApprovalType = 'void' | 'refund' | 'price_override' | 'discount';

/** What an approval is bound to; a goodwill refund has no sale (`goodwill_refund`, no id). */
export type ApprovalReferenceType = 'sale' | 'goodwill_refund';

/**
 * The extra facts an override or a discount approval is bound to (T-030 D-47). A price override
 * names `{product_id, unit_id, unit_price}`, a discount `{discount_id}`.
 */
export interface ApprovalPayload {
  product_id?: ProductId;
  unit_id?: UnitId;
  unit_price?: Money;
  discount_id?: DiscountId;
}

/** `POST approvals`. The branch is the server's to resolve (AC-86). */
export interface RequestApprovalRequest {
  type: ApprovalType;
  reference_type: ApprovalReferenceType;
  reference_id: string | null;
  payload: ApprovalPayload | null;
}

/**
 * `POST approvals/{id}/decide`. `pin` is typed by the supervisor, held only until this call is
 * made and never stored (AC-66).
 */
export interface DecideApprovalRequest {
  decision: 'approve' | 'reject';
  method: 'pin_local';
  employee_code: string;
  pin: string;
  reason?: string;
}

export type ApprovalStatus = 'pending' | 'approved' | 'rejected';

export interface Approval {
  id: ApprovalId;
  type: ApprovalType;
  reference_type: ApprovalReferenceType;
  reference_id: string | null;
  payload: ApprovalPayload | null;
  status: ApprovalStatus;
  requested_by_name: string;
  decided_by_name: string | null;
  decided_at: string | null;
  /** Set once a write has consumed it; an approval is single use. */
  consumed_at: string | null;
}
