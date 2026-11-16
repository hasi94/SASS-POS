import { PricingInputError, PricingRefused } from './refusal';
import {
  currency,
  date,
  factor,
  instant,
  int,
  intOrNull,
  money,
  obj,
  rate,
  str,
  strOrNull,
  bool,
  ulid,
} from './shape';

function failure(fn: () => unknown): string {
  try {
    fn();
  } catch (error) {
    if (error instanceof PricingRefused) {
      return 'refused ' + error.reason;
    }

    return error instanceof PricingInputError ? 'input-error' : 'other ' + String(error);
  }

  return 'no throw';
}

const NOT_AMOUNTS: unknown[] = [
  '',
  ' 1',
  '1 ',
  '1.0000\n',
  '-1.0000',
  '+1',
  '1e3',
  '1.00001',
  '.5',
  '5.',
  '1,5',
  '123456789012345',
  1,
  1n,
  null,
  NaN,
  undefined,
];

describe('money', () => {
  it('canonicalises to four decimals', () => {
    expect(money('1.0000', 'x')).toBe('1.0000');
    expect(money('007.5', 'x')).toBe('7.5000');
    expect(money('0', 'x')).toBe('0.0000');
    expect(money('99999999999999.9999', 'x')).toBe('99999999999999.9999');
  });

  it.each(NOT_AMOUNTS.map((value) => [value]))('refuses %s as invalid_amount', (value) => {
    expect(failure(() => money(value, 'x'))).toBe('refused invalid_amount');
  });
});

describe('rate', () => {
  it('accepts at most 5 integer and 4 fraction digits', () => {
    expect(rate('99999.9999', 'x')).toBe('99999.9999');
    expect(rate('15', 'x')).toBe('15.0000');
    expect(failure(() => rate('100000.0000', 'x'))).toBe('refused invalid_amount');
    expect(failure(() => rate('1.00001', 'x'))).toBe('refused invalid_amount');
    expect(failure(() => rate(15, 'x'))).toBe('refused invalid_amount');
  });
});

describe('factor', () => {
  it('accepts at most 12 integer and 6 fraction digits and nothing below 1', () => {
    expect(factor('1.000000', 'x')).toBe('1.000000');
    expect(factor('24', 'x')).toBe('24.000000');
    expect(factor('999999999999.999999', 'x')).toBe('999999999999.999999');
    expect(failure(() => factor('0.999999', 'x'))).toBe('refused invalid_amount');
    expect(failure(() => factor('0.000000', 'x'))).toBe('refused invalid_amount');
    expect(failure(() => factor('1000000000000.000000', 'x'))).toBe('refused invalid_amount');
    expect(failure(() => factor('1.0000001', 'x'))).toBe('refused invalid_amount');
    expect(failure(() => factor(1, 'x'))).toBe('refused invalid_amount');
  });
});

describe('ulid', () => {
  it('upper-cases its result', () => {
    expect(ulid('01abcdefghijklmnopqrstuvwx', 'x')).toBe('01ABCDEFGHIJKLMNOPQRSTUVWX');
  });

  it.each([
    ['too short', '0123456789012345678901234'],
    ['too long', '012345678901234567890123456'],
    ['a dash', '01234567890123456789-12345'],
    ['a number', 1],
    ['null', null],
  ])('throws a programmer error for %s', (_label, value) => {
    expect(failure(() => ulid(value, 'x'))).toBe('input-error');
  });
});

describe('date', () => {
  it('accepts real calendar dates', () => {
    expect(date('2025-02-28', 'x')).toBe('2025-02-28');
    expect(date('2024-02-29', 'x')).toBe('2024-02-29');
    expect(date('0001-01-01', 'x')).toBe('0001-01-01');
  });

  it.each(['2025-02-29', '0000-01-01', '2025-13-01', '2025-00-10', '2025-04-31', '2025-1-01', ''])(
    'throws a programmer error for %s',
    (value) => {
      expect(failure(() => date(value, 'x'))).toBe('input-error');
    },
  );
});

describe('instant', () => {
  it('accepts a valid UTC instant', () => {
    expect(instant('2025-06-15T09:30:00.000Z', 'x')).toBe('2025-06-15T09:30:00.000Z');
    expect(instant('2025-06-15T23:59:59.999Z', 'x')).toBe('2025-06-15T23:59:59.999Z');
  });

  it.each([
    '2025-06-15T24:00:00.000Z',
    '2025-06-15T09:60:00.000Z',
    '2025-06-15T09:30:60.000Z',
    '2025-06-15T09:30:00.000',
    '2025-06-15T09:30:00Z',
    '2025-02-30T09:30:00.000Z',
    '2025-06-15 09:30:00.000Z',
  ])('throws a programmer error for %s', (value) => {
    expect(failure(() => instant(value, 'x'))).toBe('input-error');
  });
});

describe('currency', () => {
  it('needs three upper-case letters', () => {
    expect(currency('USD', 'x')).toBe('USD');
    expect(failure(() => currency('usd', 'x'))).toBe('input-error');
    expect(failure(() => currency('US', 'x'))).toBe('input-error');
  });
});

describe('integers carried as strings', () => {
  it('reads decimal-integer strings as bigint', () => {
    expect(int({ n: '10' }, 'n')).toBe(10n);
    expect(int({ n: '-1' }, 'n')).toBe(-1n);
    expect(int({ n: '007' }, 'n')).toBe(7n);
  });

  it('accepts both signed 64-bit bounds and refuses one beyond each', () => {
    expect(int({ n: '9223372036854775807' }, 'n')).toBe(9223372036854775807n);
    expect(int({ n: '-9223372036854775808' }, 'n')).toBe(-9223372036854775808n);
    expect(failure(() => int({ n: '9223372036854775808' }, 'n'))).toBe('input-error');
    expect(failure(() => int({ n: '-9223372036854775809' }, 'n'))).toBe('input-error');
  });

  it.each(['1.0', '1e3', '', ' 1', '+1', 'x'])('refuses %j', (value) => {
    expect(failure(() => int({ n: value }, 'n'))).toBe('input-error');
  });

  it('refuses a JavaScript number', () => {
    expect(failure(() => int({ n: 5 }, 'n'))).toBe('input-error');
  });

  it('reads a nullable integer, and refuses a missing key', () => {
    expect(intOrNull({ n: null }, 'n')).toBeNull();
    expect(intOrNull({ n: '3' }, 'n')).toBe(3n);
    expect(failure(() => intOrNull({}, 'n'))).toBe('input-error');
  });
});

describe('field guards', () => {
  it('treats null as absent and a missing key as an error', () => {
    expect(strOrNull({ a: null }, 'a')).toBeNull();
    expect(strOrNull({ a: 'x' }, 'a')).toBe('x');
    expect(failure(() => strOrNull({}, 'a'))).toBe('input-error');
    expect(failure(() => strOrNull({ a: 5 }, 'a'))).toBe('input-error');
  });

  it('refuses a non-string and a non-boolean', () => {
    expect(failure(() => str({ a: 5 }, 'a'))).toBe('input-error');
    expect(failure(() => bool({ a: 'true' }, 'a'))).toBe('input-error');
    expect(failure(() => bool({}, 'a'))).toBe('input-error');
    expect(bool({ a: false }, 'a')).toBe(false);
  });

  it('refuses a non-object', () => {
    expect(failure(() => obj(null, 'x'))).toBe('input-error');
    expect(failure(() => obj([], 'x'))).toBe('input-error');
    expect(failure(() => obj('x', 'x'))).toBe('input-error');
  });
});
