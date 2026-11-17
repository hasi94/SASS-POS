import { ApiError, SaleLine } from '@flexipos/shared';
import {
  OWED_BACK_EXCEEDED,
  SALE_NOT_FOUND,
  describeRefundError,
  isFullyRefunded,
  quantityProblem,
} from './refund-store';

function refusal(code: string, status = 422): ApiError {
  return new ApiError(status, 'refused', [{ code, field: null }], 'req-1');
}

function lineWith(refundable: string): SaleLine {
  return { refundable_quantity: refundable } as SaleLine;
}

describe('quantityProblem (AC-60)', () => {
  it.each([
    ['', 'Enter a quantity'],
    ['   ', 'Enter a quantity'],
    ['abc', 'Enter the quantity as a number'],
    ['1e3', 'Enter the quantity as a number'],
    ['0', 'The quantity must be more than zero'],
    ['0.0000', 'The quantity must be more than zero'],
    ['-1', 'The quantity must be more than zero'],
    ['-0.5', 'The quantity must be more than zero'],
    ['1.00001', 'At most 4 decimal places'],
  ])('refuses %j in the form', (text, why) => {
    expect(quantityProblem(text, '10.0000')).toBe(why);
  });

  it('accepts a quantity up to what is left, to four decimals', () => {
    expect(quantityProblem('1', '2.0000')).toBeNull();
    expect(quantityProblem('2', '2.0000')).toBeNull();
    expect(quantityProblem('0.0001', '2.0000')).toBeNull();
    expect(quantityProblem('2.0000', '2')).toBeNull();
  });

  it('refuses more than is left, by exact decimal comparison', () => {
    expect(quantityProblem('2.0001', '2.0000')).toBe(
      'More than is left to refund (at most 2.0000)',
    );
    expect(quantityProblem('10', '9.9999')).not.toBeNull();
    // A float would call these equal; the string comparison does not.
    expect(quantityProblem('9007199254740993', '9007199254740992')).not.toBeNull();
  });

  it('has no upper bound when there is no original line (goodwill)', () => {
    expect(quantityProblem('100000', null)).toBeNull();
    expect(quantityProblem('0', null)).not.toBeNull();
  });
});

describe('isFullyRefunded (AC-59)', () => {
  it('is true only when nothing is left', () => {
    expect(isFullyRefunded(lineWith('0.0000'))).toBe(true);
    expect(isFullyRefunded(lineWith('0'))).toBe(true);
    expect(isFullyRefunded(lineWith('0.0001'))).toBe(false);
    expect(isFullyRefunded(lineWith('2.0000'))).toBe(false);
  });
});

describe('describeRefundError (AC-59, AC-64)', () => {
  it('words exceeds_balance as owed back, not as a payment balance', () => {
    expect(describeRefundError(refusal('exceeds_balance')).text).toBe(OWED_BACK_EXCEEDED);
    expect(OWED_BACK_EXCEEDED).toBe('More than is owed back');
  });

  it('words not_found and not_refundable as a sale that is not at this branch', () => {
    expect(describeRefundError(refusal('not_found', 404)).text).toBe(SALE_NOT_FOUND);
    expect(describeRefundError(refusal('not_refundable')).text).toBe(
      'Sale not found at this branch',
    );
  });

  it('leaves every other refusal to the catalogue', () => {
    expect(describeRefundError(refusal('over_refund')).text).toContain(
      'More than is left to refund',
    );
    expect(describeRefundError(new Error('boom')).text).toBe('Something went wrong');
  });
});
