import {
  ApprovalPayload,
  ApprovalReferenceType,
  ApprovalType,
} from '../../sales/contract/approval';
import { cmp } from '../mock-decimal';
import { HandlerTable } from '../mock-context';
import { MockRefusal, refuse } from '../mock-envelope';
import { BranchState, MockState, StoredApproval, nowIso } from '../mock-state';

/**
 * Approvals. Chunk 0 ships the consumption side, which the line handlers need (a price override
 * and a discount that requires approval name an approval id). Track A1 adds the two calls that
 * create and decide one (`approvals.request`, `approvals.decide`) to {@link approvalHandlers}.
 */

/** What an action says an approval must be bound to (T-030 D-47, AC-24A). */
export interface ApprovalBinding {
  type: ApprovalType;
  reference_type: ApprovalReferenceType;
  reference_id: string | null;
  payload: ApprovalPayload | null;
}

function samePayload(expected: ApprovalPayload | null, actual: ApprovalPayload | null): boolean {
  if (expected === null) {
    return true;
  }
  if (actual === null) {
    return false;
  }
  if (expected.product_id !== undefined && expected.product_id !== actual.product_id) {
    return false;
  }
  if (expected.unit_id !== undefined && expected.unit_id !== actual.unit_id) {
    return false;
  }
  if (expected.discount_id !== undefined && expected.discount_id !== actual.discount_id) {
    return false;
  }
  if (expected.unit_price !== undefined) {
    if (actual.unit_price === undefined || cmp(expected.unit_price, actual.unit_price) !== 0) {
      return false;
    }
  }
  return true;
}

/**
 * Checks that an approval may be consumed by an action bound to `expected`, without consuming it.
 * Returns the stored approval, or the refusal: `approval_mismatch` (unknown here, other branch, or
 * bound to something else), `approval_already_used`, `approval_not_approved`.
 */
export function checkApproval(
  branch: BranchState,
  approvalId: string,
  expected: ApprovalBinding,
): StoredApproval | MockRefusal {
  const stored = branch.approvals.find((a) => a.data.id === approvalId);
  if (!stored || stored.branchId !== branch.branchId) {
    return refuse('approval_mismatch', 'That approval does not belong to this action.');
  }
  if (stored.data.consumed_at !== null) {
    return refuse('approval_already_used', 'That approval has already been used.');
  }
  if (stored.data.status !== 'approved') {
    return refuse('approval_not_approved', 'That approval has not been approved.');
  }
  const bound =
    stored.data.type === expected.type &&
    stored.data.reference_type === expected.reference_type &&
    stored.data.reference_id === expected.reference_id &&
    samePayload(expected.payload, stored.data.payload);
  if (!bound) {
    return refuse('approval_mismatch', 'That approval does not belong to this action.');
  }
  return stored;
}

/** Marks an approval used. Call it in the same step as the change it authorised. */
export function consumeApproval(state: MockState, stored: StoredApproval): void {
  stored.data.consumed_at = nowIso(state);
}

export function approvalHandlers(): HandlerTable {
  return {};
}
