import {
  Dec,
  abs,
  add,
  dec,
  divideHalfUp,
  divideToScale,
  isNegative,
  mul,
  percentOf,
  roundStep,
  sub,
  text,
  toScaleHalfUp,
} from './decimal';
import { PricingRefused } from './refusal';

const MAX = '99999999999999.9999';

function show(x: Dec): string {
  return (isNegative(x) ? '-' : '') + text(abs(x));
}

function reasonOf(fn: () => unknown): string {
  try {
    fn();
  } catch (error) {
    return error instanceof PricingRefused ? error.reason : 'threw ' + String(error);
  }

  return 'no throw';
}

function neg(canonical: string): Dec {
  const x = dec(canonical);

  return { u: -x.u, s: x.s };
}

describe('divideHalfUp', () => {
  it.each([
    [5n, 10n, 1n],
    [-5n, 10n, -1n],
    [5n, -10n, -1n],
    [-5n, -10n, 1n],
    [4n, 10n, 0n],
    [-4n, 10n, 0n],
    [15n, 10n, 2n],
    [-15n, 10n, -2n],
  ])('%s / %s rounds half away from zero to %s', (n, d, expected) => {
    expect(divideHalfUp(n, d)).toBe(expected);
  });

  it('treats a zero divisor as an engine defect, not a refusal', () => {
    expect(() => divideHalfUp(1n, 0n)).toThrow(Error);
    expect(reasonOf(() => divideHalfUp(1n, 0n))).toMatch(/^threw /);
  });
});

describe('toScaleHalfUp', () => {
  it.each([
    ['0.00005', '0.0001'],
    ['1.00005', '1.0001'],
    ['2.50005', '2.5001'],
    ['2.50004', '2.5000'],
  ])('rounds the positive tie or near-tie %s to %s', (input, expected) => {
    expect(show(toScaleHalfUp(dec(input), 4))).toBe(expected);
  });

  it.each([
    ['0.00005', '-0.0001'],
    ['1.00005', '-1.0001'],
    ['2.50005', '-2.5001'],
    ['2.50004', '-2.5000'],
  ])('rounds the negative tie or near-tie -%s to %s (away from zero)', (input, expected) => {
    expect(show(toScaleHalfUp(neg(input), 4))).toBe(expected);
  });

  it('pads a value with fewer places', () => {
    expect(show(toScaleHalfUp(dec('7.5'), 4))).toBe('7.5000');
  });
});

describe('divideToScale', () => {
  it('rounds ties away from zero for a negative numerator or denominator', () => {
    expect(show(divideToScale({ u: -1n, s: 0 }, { u: 16000n, s: 0 }, 4))).toBe('-0.0001');
    expect(show(divideToScale({ u: 1n, s: 0 }, { u: -16000n, s: 0 }, 4))).toBe('-0.0001');
  });

  it('rounds a repeating quotient', () => {
    expect(show(divideToScale(dec('1'), dec('3'), 4))).toBe('0.3333');
    expect(show(divideToScale(dec('2'), dec('3'), 4))).toBe('0.6667');
  });

  it('works when the divisor has more places than the dividend', () => {
    expect(show(divideToScale(dec('1.0000'), dec('4.000000'), 4))).toBe('0.2500');
  });
});

describe('percentOf', () => {
  it('multiplies exactly and rounds once (AC-14)', () => {
    // 100 x 12.3456 / 100 is 12.3456; x * round(p / 100, 4) would give 12.3500.
    expect(text(percentOf(dec('100.0000'), dec('12.3456')))).toBe('12.3456');
  });
});

describe('precision beyond a JavaScript number (AC-9)', () => {
  it('round-trips the largest amount', () => {
    expect(text(dec(MAX))).toBe(MAX);
  });

  it('adds one unit in the last place without losing a digit', () => {
    expect(text(add(dec('99999999999999.9998'), dec('0.0001')))).toBe(MAX);
  });

  it('multiplies by a factor of one exactly', () => {
    expect(text(mul(dec(MAX), dec('1.000000')))).toBe(MAX);
  });

  it('rounds half of the largest amount up, exactly', () => {
    expect(text(percentOf(dec(MAX), dec('50.0000')))).toBe('50000000000000.0000');
  });
});

describe('range (AC-10)', () => {
  const over = dec('100000000000000.0000');

  it('accepts exactly the largest amount at every checked step', () => {
    expect(text(roundStep(dec(MAX)))).toBe(MAX);
    expect(text(percentOf(dec(MAX), dec('100.0000')))).toBe(MAX);
    expect(text(dec(MAX))).toBe(MAX);
  });

  it('refuses one unit above it at every checked step', () => {
    expect(reasonOf(() => roundStep(over))).toBe('out_of_range');
    expect(reasonOf(() => percentOf(over, dec('100.0000')))).toBe('out_of_range');
    expect(reasonOf(() => text(over))).toBe('out_of_range');
  });

  it('refuses a rounded value that tips over the limit', () => {
    expect(reasonOf(() => roundStep(dec('99999999999999.99995')))).toBe('out_of_range');
  });
});

describe('text', () => {
  it('refuses a negative value as out of range', () => {
    expect(reasonOf(() => text(neg('0.0001')))).toBe('out_of_range');
  });

  it('prints a zero reached through subtraction as 0.0000, never -0.0000', () => {
    expect(text(sub(dec('1.0000'), dec('1.0000')))).toBe('0.0000');
    expect(text(toScaleHalfUp(neg('0.00004'), 4))).toBe('0.0000');
  });

  it('refuses an unrounded value as invalid_amount', () => {
    expect(reasonOf(() => text(dec('1.00001')))).toBe('invalid_amount');
  });

  it('accepts trailing zeros beyond the fourth place', () => {
    expect(text(dec('1.500000'))).toBe('1.5000');
  });

  it('prints 7.5 and 7.5000 identically, with exactly four decimals', () => {
    expect(text(dec('7.5'))).toBe('7.5000');
    expect(text(dec('7.5000'))).toBe('7.5000');
    expect(text(dec('0'))).toBe('0.0000');
    expect(text(dec('12'))).toBe('12.0000');
  });
});

describe('dec', () => {
  it('rejects anything but a canonical decimal string as an engine defect', () => {
    expect(() => dec('-1')).toThrow(Error);
    expect(() => dec('')).toThrow(Error);
    expect(() => dec('1e3')).toThrow(Error);
  });
});
