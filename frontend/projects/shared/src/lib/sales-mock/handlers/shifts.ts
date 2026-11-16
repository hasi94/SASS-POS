import {
  CASH_MOVEMENT_TYPES,
  CashMovement,
  CashMovementType,
  CashShift,
  ClosedShift,
} from '../../sales/contract/shift';
import { Money } from '../../sales/decimal-string';
import { add, cmp, format4, sub, sum, ZERO } from '../mock-decimal';
import { HandlerTable, MockContext } from '../mock-context';
import { MockRefusal, MockResult, invalid, ok, refuse } from '../mock-envelope';
import { isRefusal, readDecimal, readString, readUlid } from '../mock-input';
import {
  BranchState,
  MockState,
  StoredShift,
  nextId,
  nextShiftNumber,
  openShiftOf,
  tick,
} from '../mock-state';

/** Opens a shift on the branch's terminal. The caller has checked that none is open. */
export function openShiftIn(
  state: MockState,
  branch: BranchState,
  openingCash: string,
  employeeName: string,
): StoredShift {
  const data: CashShift = {
    id: nextId(state, 'shift'),
    number: nextShiftNumber(state),
    status: 'open',
    terminal_id: branch.terminalId,
    employee_name: employeeName,
    opened_at: tick(state),
    closed_at: null,
    opening_cash: format4(openingCash) as Money,
  };
  const stored: StoredShift = { terminalId: branch.terminalId, data, closed: null };
  branch.shifts.push(stored);
  return stored;
}

const INCOMING: readonly CashMovementType[] = ['cash_in', 'float_in'];

/** What a replay of a movement is compared against: everything but the id. */
function movementKey(shiftId: string, type: string, amount: string, reason: string): string {
  return JSON.stringify({ shift: shiftId, type, amount: format4(amount), reason });
}

/** Appends a movement to an open shift. The caller has checked the request and the shift. */
export function recordMovementIn(
  state: MockState,
  branch: BranchState,
  shift: StoredShift,
  movement: { id: string; type: CashMovementType; amount: string; reason: string },
  employeeName: string,
): CashMovement {
  const data: CashMovement = {
    id: movement.id,
    shift_id: shift.data.id,
    type: movement.type,
    amount: format4(movement.amount) as Money,
    reason: movement.reason,
    employee_name: employeeName,
    created_at: tick(state),
  };
  branch.movements.push({
    payloadKey: movementKey(shift.data.id, movement.type, movement.amount, movement.reason),
    data,
  });
  return data;
}

/** The server's close figures for a shift (T-030 AC-34, AC-35). */
function closeFigures(
  branch: BranchState,
  shift: StoredShift,
  counted: string,
  closedAt: string,
): ClosedShift {
  const onShift = branch.sales.filter((s) => s.data.shift_id === shift.data.id);
  const cashTenders = onShift.flatMap((s) =>
    s.data.tenders.filter((t) => t.method_type === 'cash').map((t) => t.amount as string),
  );
  const moved = branch.movements.filter((m) => m.data.shift_id === shift.data.id);
  const incoming = moved
    .filter((m) => INCOMING.includes(m.data.type))
    .map((m) => m.data.amount as string);
  const outgoing = moved
    .filter((m) => !INCOMING.includes(m.data.type))
    .map((m) => m.data.amount as string);
  const expected = sub(
    add(add(shift.data.opening_cash, sum(cashTenders)), sum(incoming)),
    sum(outgoing),
  );

  const countedSales = onShift.filter(
    (s) =>
      s.data.type === 'sale' &&
      ['completed', 'refunded', 'partially_refunded'].includes(s.data.status),
  );
  const refunds = onShift.filter((s) => s.data.type === 'refund');
  const byMethod: Record<string, Money> = {};
  for (const sale of onShift) {
    for (const tender of sale.data.tenders) {
      byMethod[tender.method_code] = add(
        byMethod[tender.method_code] ?? ZERO,
        tender.amount,
      ) as Money;
    }
  }
  return {
    ...shift.data,
    status: 'closed',
    closed_at: closedAt,
    counted_cash: format4(counted) as Money,
    expected_cash: expected as Money,
    variance: sub(counted, expected) as Money,
    sales_count: countedSales.length,
    refunds_count: refunds.length,
    gross: sum(countedSales.map((s) => s.data.subtotal)) as Money,
    discounts: sum(countedSales.map((s) => s.data.discount_total)) as Money,
    tax: sum(countedSales.map((s) => s.data.tax_total)) as Money,
    net: sub(
      sum(countedSales.map((s) => s.data.total)),
      sum(refunds.map((s) => s.data.total)),
    ) as Money,
    totals_by_method: byMethod,
  };
}

/** Closes a shift and stores the result. The caller has checked that it is open and has no open sales. */
export function closeShiftIn(
  state: MockState,
  branch: BranchState,
  shift: StoredShift,
  counted: string,
): ClosedShift {
  const result = closeFigures(branch, shift, counted, tick(state));
  shift.data = {
    id: result.id,
    number: result.number,
    status: 'closed',
    terminal_id: result.terminal_id,
    employee_name: result.employee_name,
    opened_at: result.opened_at,
    closed_at: result.closed_at,
    opening_cash: result.opening_cash,
  };
  shift.closed = result;
  return result;
}

function currentShift(ctx: MockContext): MockResult {
  return ok(openShiftOf(ctx.branch)?.data ?? null);
}

function openShift(ctx: MockContext): MockResult {
  const unknown = Object.keys(ctx.body).filter((k) => k !== 'opening_cash');
  if (unknown.length > 0) {
    return invalid(unknown[0] ?? '', 'This field is not accepted.');
  }
  const cash = readDecimal(ctx.body, 'opening_cash', { allowZero: true });
  if (isRefusal(cash)) {
    return cash;
  }
  if (openShiftOf(ctx.branch)) {
    return refuse('shift_already_open', 'This terminal already has an open shift.');
  }
  return ok(openShiftIn(ctx.state, ctx.branch, cash, ctx.actorName).data, 201);
}

function findShift(ctx: MockContext): StoredShift | MockRefusal {
  const shift = ctx.branch.shifts.find((s) => s.data.id === ctx.params['id']);
  return shift ?? refuse('not_found', 'Shift not found.');
}

function recordMovement(ctx: MockContext): MockResult {
  const shift = findShift(ctx);
  if (isRefusal(shift)) {
    return shift;
  }
  const id = readUlid(ctx.body, 'id');
  if (isRefusal(id)) {
    return id;
  }
  const type = ctx.body['type'];
  if (typeof type !== 'string' || !CASH_MOVEMENT_TYPES.includes(type as CashMovementType)) {
    return invalid('type', `The type must be one of ${CASH_MOVEMENT_TYPES.join(', ')}.`);
  }
  const amount = readDecimal(ctx.body, 'amount', { allowZero: false });
  if (isRefusal(amount)) {
    return amount;
  }
  const reason = readString(ctx.body, 'reason');
  if (isRefusal(reason)) {
    return reason;
  }
  const earlier = ctx.branch.movements.find((m) => m.data.id === id);
  if (earlier) {
    return earlier.payloadKey === movementKey(shift.data.id, type, amount, reason)
      ? ok(earlier.data)
      : refuse('replay_conflict', 'That id was already used for a different movement.');
  }
  if (shift.data.status !== 'open') {
    return refuse('shift_not_open', 'The shift is not open.');
  }
  const data = recordMovementIn(
    ctx.state,
    ctx.branch,
    shift,
    { id, type: type as CashMovementType, amount, reason },
    ctx.actorName,
  );
  return ok(data, 201);
}

function closeShift(ctx: MockContext): MockResult {
  const shift = findShift(ctx);
  if (isRefusal(shift)) {
    return shift;
  }
  const counted = readDecimal(ctx.body, 'counted_cash', { allowZero: true });
  if (isRefusal(counted)) {
    return counted;
  }
  if (shift.closed) {
    return cmp(shift.closed.counted_cash, counted) === 0
      ? ok(shift.closed)
      : refuse('replay_conflict', 'That shift was already closed with a different count.');
  }
  const openSales = ctx.branch.sales.some(
    (s) =>
      s.data.shift_id === shift.data.id &&
      (s.data.status === 'draft' || s.data.status === 'pending_payment'),
  );
  if (openSales) {
    return refuse(
      'shift_has_open_sales',
      'Finish or void the open sales before closing the shift.',
    );
  }
  return ok(closeShiftIn(ctx.state, ctx.branch, shift, counted));
}

export function shiftHandlers(): HandlerTable {
  return {
    'shifts.current': currentShift,
    'shifts.open': openShift,
    'shifts.movement': recordMovement,
    'shifts.close': closeShift,
  };
}
