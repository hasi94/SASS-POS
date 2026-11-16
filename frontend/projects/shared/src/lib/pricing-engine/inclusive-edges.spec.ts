/*
 * QA: the boundaries of the inclusive back-out (AC-16) that the shared fixtures do not pin. The
 * expected values were derived by an independent exact-fraction re-implementation of PHP's
 * `TaxCalculator::inclusiveNet`, not by this engine.
 */

import { calculateTax } from './tax';
import { failureOf, id, taxInput, taxMember } from './testing/builders';

const FIXED = taxMember({
  tax_id: id('01TAXFIXED'),
  code: 'FX',
  type: 'fixed',
  sequence: '1',
  rates: [{ rate: '1.0000', effective_from: '2020-01-01', effective_to: null }],
});

const COMPOUND = taxMember({
  tax_id: id('01TAXCOMPOUND'),
  code: 'CP',
  is_compound: true,
  sequence: '2',
  rates: [{ rate: '10.0000', effective_from: '2020-01-01', effective_to: null }],
});

function inclusive(amount: string, members = [FIXED, COMPOUND]) {
  return taxInput({ members, amount, base_quantity: '1.0000', is_inclusive: true });
}

describe('inclusive back-out boundaries (AC-16)', () => {
  it('accepts a fixed total exactly equal to the gross: net 0.0000, not a refusal', () => {
    const got = calculateTax(inclusive('1.0000', [FIXED]));

    expect(got.net).toBe('0.0000');
    expect(got.total).toBe('1.0000');
    expect(got.lines.map((line) => [line.base, line.amount])).toEqual([['0.0000', '1.0000']]);
  });

  it('refuses a fixed total one unit above the gross', () => {
    expect(failureOf(() => calculateTax(inclusive('0.9999', [FIXED])))).toBe(
      'refused inclusive_amount_too_small',
    );
  });

  it('refuses when the fixed tax fits but the compound tax charged on it does not', () => {
    // gross 1.0000, fixed 1.0000, a 10 % compound member after it: gross - F - K = -0.1000.
    expect(failureOf(() => calculateTax(inclusive('1.0000')))).toBe(
      'refused inclusive_amount_too_small',
    );
  });

  it('accepts gross - F - K of exactly zero', () => {
    const got = calculateTax(inclusive('1.1000'));

    expect(got.net).toBe('0.0000');
    expect(got.total).toBe('1.1000');
    expect(got.lines.map((line) => [line.base, line.amount])).toEqual([
      ['0.0000', '1.0000'],
      ['1.0000', '0.1000'],
    ]);
  });

  it('one unit above that boundary backs out a net of 0.0001 and keeps net + total = gross', () => {
    const got = calculateTax(inclusive('1.1001'));

    expect(got.net).toBe('0.0001');
    expect(got.total).toBe('1.1000');
    expect(got.lines.map((line) => [line.base, line.amount])).toEqual([
      ['0.0001', '1.0000'],
      ['1.0001', '0.1000'],
    ]);
  });
});
