import { Money } from '../decimal-string';
import { ShiftId, Ulid } from './common';

export type CashShiftStatus = 'open' | 'closed' | 'reconciled';

/** A cash shift as the server reports it (T-030 `cash_shifts`). */
export interface CashShift {
  id: ShiftId;
  number: string;
  status: CashShiftStatus;
  terminal_id: string;
  employee_name: string;
  opened_at: string;
  closed_at: string | null;
  opening_cash: Money;
}

/** `POST shifts`. No terminal, branch or date: the server resolves them from the session (AC-86). */
export interface OpenShiftRequest {
  opening_cash: Money;
}

/** The sign is a property of the type: the first two add to the drawer, the rest take from it. */
export type CashMovementType =
  'cash_in' | 'cash_out' | 'float_in' | 'float_out' | 'drop' | 'petty_cash' | 'bank_deposit';

export const CASH_MOVEMENT_TYPES: readonly CashMovementType[] = [
  'cash_in',
  'cash_out',
  'float_in',
  'float_out',
  'drop',
  'petty_cash',
  'bank_deposit',
];

/** `POST shifts/{id}/movements`. `id` is the idempotency key, kept across retries of one submit. */
export interface CashMovementRequest {
  id: Ulid;
  type: CashMovementType;
  /** Always positive; the type carries the direction. */
  amount: Money;
  reason: string;
}

export interface CashMovement {
  id: Ulid;
  shift_id: ShiftId;
  type: CashMovementType;
  amount: Money;
  reason: string;
  employee_name: string;
  created_at: string;
}

/** `POST shifts/{id}/close`: a blind count (D-16). */
export interface CloseShiftRequest {
  counted_cash: Money;
}

/** The shift after close: the server's figures, shown as received (AC-13). */
export interface ClosedShift extends CashShift {
  counted_cash: Money;
  expected_cash: Money;
  variance: Money;
  sales_count: number;
  refunds_count: number;
  gross: Money;
  discounts: Money;
  tax: Money;
  net: Money;
  /** Payment method code to the summed, signed tender amounts of the shift. */
  totals_by_method: Record<string, Money>;
}
