/*
 * QA: calendar edges of the date and instant checks (AC-28), mirroring PHP `checkdate`. The century
 * rule is the classic trap: 1900 and 2100 are not leap years, 2000 and 2400 are.
 */

import { PricingInputError } from './refusal';
import { date, instant } from './shape';

function throwsInput(fn: () => unknown): boolean {
  try {
    fn();
  } catch (error) {
    return error instanceof PricingInputError;
  }

  return false;
}

describe('the century leap-year rule', () => {
  it.each(['2000-02-29', '2400-02-29', '2024-02-29'])('accepts %s', (value) => {
    expect(date(value, 'x')).toBe(value);
    expect(instant(value + 'T00:00:00.000Z', 'x')).toBe(value + 'T00:00:00.000Z');
  });

  it.each(['1900-02-29', '2100-02-29', '2200-02-29', '2023-02-29'])('refuses %s', (value) => {
    expect(throwsInput(() => date(value, 'x'))).toBe(true);
    expect(throwsInput(() => instant(value + 'T00:00:00.000Z', 'x'))).toBe(true);
  });

  it('accepts the last instant of a day and refuses the first of the next hour/minute/second field', () => {
    expect(instant('2025-12-31T23:59:59.999Z', 'x')).toBe('2025-12-31T23:59:59.999Z');
    expect(throwsInput(() => instant('2025-12-31T24:00:00.000Z', 'x'))).toBe(true);
    expect(throwsInput(() => instant('2025-12-31T23:60:00.000Z', 'x'))).toBe(true);
    expect(throwsInput(() => instant('2025-12-31T23:59:60.000Z', 'x'))).toBe(true);
  });

  it('refuses an instant that is not UTC or not millisecond precision', () => {
    expect(throwsInput(() => instant('2025-06-15T09:30:00Z', 'x'))).toBe(true);
    expect(throwsInput(() => instant('2025-06-15T09:30:00.000+00:00', 'x'))).toBe(true);
    expect(throwsInput(() => instant('2025-06-15 09:30:00.000Z', 'x'))).toBe(true);
    expect(throwsInput(() => instant('2025-06-15T09:30:00.0000Z', 'x'))).toBe(true);
  });
});
