import {
  Approval,
  ApprovalPayload,
  ApprovalReferenceType,
  ApprovalType,
} from '../../sales/contract/approval';
import { Money } from '../../sales/decimal-string';
import { cmp, format4 } from '../mock-decimal';
import { HandlerTable, MockContext } from '../mock-context';
import { MockRefusal, MockResult, invalid, ok, refuse } from '../mock-envelope';
import { asRecord, isRefusal, readDecimal, readString } from '../mock-input';
import { seedId } from '../mock-seed';
import { BranchState, MockState, StoredApproval, nextId, nowIso, tick } from '../mock-state';

/**
 * Approvals (T-030 AC-22 to AC-24A). The consumption side serves the line, void and refund
 * handlers: an approval names a type, a reference and the branch, is decided once by someone
 * other than its requester, and is consumed by exactly one action. The two calls that create and
 * decide one are {@link approvalHandlers}.
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

const APPROVAL_TYPES: readonly ApprovalType[] = ['void', 'refund', 'price_override', 'discount'];
const REFERENCE_TYPES: readonly ApprovalReferenceType[] = ['sale', 'goodwill_refund'];
const PAYLOAD_FIELDS = ['product_id', 'unit_id', 'unit_price', 'discount_id'];

/** The payload an approval of this type is bound to, or the validation refusal. */
function readPayload(type: ApprovalType, value: unknown): ApprovalPayload | null | MockRefusal {
  if (value === null || value === undefined) {
    return type === 'price_override' || type === 'discount'
      ? invalid('payload', `A ${type} approval needs a payload.`)
      : null;
  }
  if (typeof value !== 'object' || Array.isArray(value)) {
    return invalid('payload', 'The payload must be an object.');
  }
  const body = asRecord(value);
  const extra = Object.keys(body).find((k) => !PAYLOAD_FIELDS.includes(k));
  if (extra !== undefined) {
    return invalid(`payload.${extra}`, 'This field is not accepted.');
  }
  const payload: ApprovalPayload = {};
  if (type === 'price_override') {
    const product = readString(body, 'product_id');
    const unit = readString(body, 'unit_id');
    const price = readDecimal(body, 'unit_price', { allowZero: true });
    for (const part of [product, unit, price]) {
      if (isRefusal(part)) {
        return part;
      }
    }
    payload.product_id = product as string;
    payload.unit_id = unit as string;
    payload.unit_price = format4(price as string) as Money;
  } else if (type === 'discount') {
    const discount = readString(body, 'discount_id');
    if (isRefusal(discount)) {
      return discount;
    }
    payload.discount_id = discount;
  } else if (Object.keys(body).length > 0) {
    return invalid('payload', `A ${type} approval carries no payload.`);
  }
  return payload;
}

function requestApproval(ctx: MockContext): MockResult {
  const extra = Object.keys(ctx.body).find(
    (k) => !['type', 'reference_type', 'reference_id', 'payload'].includes(k),
  );
  if (extra !== undefined) {
    return invalid(extra, 'This field is not accepted.');
  }
  const type = ctx.body['type'];
  if (typeof type !== 'string' || !APPROVAL_TYPES.includes(type as ApprovalType)) {
    return invalid('type', `The type must be one of ${APPROVAL_TYPES.join(', ')}.`);
  }
  const referenceType = ctx.body['reference_type'];
  if (
    typeof referenceType !== 'string' ||
    !REFERENCE_TYPES.includes(referenceType as ApprovalReferenceType)
  ) {
    return invalid(
      'reference_type',
      `The reference_type must be one of ${REFERENCE_TYPES.join(', ')}.`,
    );
  }
  const approvalType = type as ApprovalType;
  const reference = referenceType as ApprovalReferenceType;
  if (reference === 'goodwill_refund' && approvalType !== 'refund') {
    return invalid('reference_type', 'Only a refund can be a goodwill refund.');
  }
  const rawId = ctx.body['reference_id'];
  let referenceId: string | null = null;
  if (reference === 'sale') {
    const id = readString(ctx.body, 'reference_id');
    if (isRefusal(id)) {
      return id;
    }
    referenceId = id;
  } else if (rawId !== null && rawId !== undefined) {
    return invalid('reference_id', 'A goodwill refund names no sale.');
  }
  const payload = readPayload(approvalType, ctx.body['payload']);
  if (isRefusal(payload)) {
    return payload;
  }
  if (referenceId !== null && !ctx.branch.sales.some((s) => s.data.id === referenceId)) {
    return refuse('not_found', 'Sale not found.', { field: 'reference_id', detail: referenceId });
  }
  const requester =
    ctx.state.seed.employees.find((e) => e.name === ctx.actorName)?.id ?? seedId('E', 1);
  const data: Approval = {
    id: nextId(ctx.state, 'approval'),
    type: approvalType,
    reference_type: reference,
    reference_id: referenceId,
    payload,
    status: 'pending',
    requested_by_name: ctx.actorName,
    decided_by_name: null,
    decided_at: null,
    consumed_at: null,
  };
  tick(ctx.state);
  ctx.branch.approvals.push({ data, branchId: ctx.branch.branchId, requestedById: requester });
  return ok(data, 201);
}

function decideApproval(ctx: MockContext): MockResult {
  const stored = ctx.branch.approvals.find((a) => a.data.id === ctx.params['id']);
  if (!stored) {
    return refuse('not_found', 'Approval not found.');
  }
  const extra = Object.keys(ctx.body).find(
    (k) => !['decision', 'method', 'employee_code', 'pin', 'reason'].includes(k),
  );
  if (extra !== undefined) {
    return invalid(extra, 'This field is not accepted.');
  }
  const decision = ctx.body['decision'];
  if (decision !== 'approve' && decision !== 'reject') {
    return invalid('decision', 'The decision must be approve or reject.');
  }
  if (ctx.body['method'] !== 'pin_local') {
    return invalid('method', 'The method must be pin_local.');
  }
  const code = readString(ctx.body, 'employee_code');
  if (isRefusal(code)) {
    return code;
  }
  const pin = readString(ctx.body, 'pin');
  if (isRefusal(pin)) {
    return pin;
  }
  // A wrong PIN, an unknown code and a locked person are not told apart (T-030 AC-23).
  const employee = ctx.state.seed.employees.find((e) => e.code === code && e.pin === pin);
  if (!employee) {
    return refuse('pin_invalid', 'Code or PIN not recognised.');
  }
  if (stored.data.status !== 'pending') {
    return refuse('approval_not_pending', 'That approval has already been decided.');
  }
  const sale =
    stored.data.reference_type === 'sale'
      ? ctx.branch.sales.find((s) => s.data.id === stored.data.reference_id)
      : undefined;
  if (employee.id === stored.requestedById || sale?.data.employee_name === employee.name) {
    return refuse('self_approval_not_allowed', 'Nobody may approve their own work.');
  }
  if (!employee.can_approve) {
    return refuse('not_authorised', 'That person may not decide approvals at this branch.');
  }
  tick(ctx.state);
  stored.data.status = decision === 'approve' ? 'approved' : 'rejected';
  stored.data.decided_by_name = employee.name;
  stored.data.decided_at = nowIso(ctx.state);
  return ok(stored.data);
}

export function approvalHandlers(): HandlerTable {
  return {
    'approvals.request': requestApproval,
    'approvals.decide': decideApproval,
  };
}
