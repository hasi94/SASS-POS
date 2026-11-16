import {
  asMoney,
  asQuantity,
  compareDecimal,
  formatMoney,
  formatQuantity,
  isDecimalString,
} from './decimal-string';

describe('formatMoney', () => {
  const rows: [string, string][] = [
    ['0.0000', '0.00'],
    ['1250.5000', '1,250.50'],
    ['0.0125', '0.0125'],
    ['1234567.8900', '1,234,567.89'],
    ['-5.0000', '-5.00'],
    ['1000.0000', '1,000.00'],
    ['-1234.5000', '-1,234.50'],
    ['0.1000', '0.10'],
    ['5', '5.00'],
    ['999.9999', '999.9999'],
    ['12345678901234.5600', '12,345,678,901,234.56'],
  ];
  it.each(rows)('shows %s as %s', (input, expected) => {
    expect(formatMoney(input)).toBe(expected);
  });

  it('never rounds', () => {
    expect(formatMoney('0.0001')).toBe('0.0001');
    expect(formatMoney('1.99999')).toBe('1.99999');
  });

  it('shows something that is not a decimal string exactly as it came', () => {
    expect(formatMoney('abc')).toBe('abc');
    expect(formatMoney('')).toBe('');
  });
});

describe('formatQuantity', () => {
  const rows: [string, string][] = [
    ['2.0000', '2'],
    ['0.7500', '0.75'],
    ['12.3450', '12.345'],
    ['0.0000', '0'],
    ['1500.0000', '1,500'],
    ['3', '3'],
  ];
  it.each(rows)('shows %s as %s', (input, expected) => {
    expect(formatQuantity(input)).toBe(expected);
  });
});

describe('isDecimalString', () => {
  it('accepts plain decimals', () => {
    for (const ok of ['0', '1', '1.5', '1250.0000', '0.0001', '007.5']) {
      expect(isDecimalString(ok)).toBe(true);
    }
  });

  it('rejects everything else', () => {
    for (const bad of ['', 'abc', '1.23456', '1e3', ' 1', '1 ', '1.', '.5', '+1', '1,000', '--1']) {
      expect(isDecimalString(bad)).toBe(false);
    }
  });

  it('rejects a negative unless negatives are allowed', () => {
    expect(isDecimalString('-1')).toBe(false);
    expect(isDecimalString('-1', { allowNegative: true })).toBe(true);
  });

  it('rejects zero when zero is disallowed', () => {
    expect(isDecimalString('0', { allowZero: false })).toBe(false);
    expect(isDecimalString('0.0000', { allowZero: false })).toBe(false);
    expect(isDecimalString('0.0001', { allowZero: false })).toBe(true);
  });

  it('honours the scale limit', () => {
    expect(isDecimalString('1.2345')).toBe(true);
    expect(isDecimalString('1.23456')).toBe(false);
    expect(isDecimalString('1.2', { maxScale: 0 })).toBe(false);
    expect(isDecimalString('12', { maxScale: 0 })).toBe(true);
  });
});

describe('compareDecimal', () => {
  it('treats equal values of a different scale as equal', () => {
    expect(compareDecimal('1.5', '1.5000')).toBe(0);
    expect(compareDecimal('2', '2.0000')).toBe(0);
  });

  it('orders ordinary values', () => {
    expect(compareDecimal('1.0000', '2.0000')).toBe(-1);
    expect(compareDecimal('2.0001', '2.0000')).toBe(1);
    expect(compareDecimal('10', '9.9999')).toBe(1);
  });

  it('orders negatives', () => {
    expect(compareDecimal('-1', '1')).toBe(-1);
    expect(compareDecimal('-2', '-1')).toBe(-1);
    expect(compareDecimal('-1.5', '-1.5000')).toBe(0);
    expect(compareDecimal('-0', '0')).toBe(0);
  });

  it('is exact beyond 2^53', () => {
    expect(compareDecimal('9007199254740993', '9007199254740992')).toBe(1);
    expect(compareDecimal('9007199254740993.0001', '9007199254740993.0000')).toBe(1);
    expect(compareDecimal('99999999999999999999.9999', '99999999999999999999.9999')).toBe(0);
  });

  it('refuses a value that is not a decimal string', () => {
    expect(() => compareDecimal('abc', '1')).toThrow(RangeError);
  });
});

describe('validated casts', () => {
  it('accepts a decimal string and returns it unchanged', () => {
    expect(asMoney('12.5000')).toBe('12.5000');
    expect(asQuantity('3')).toBe('3');
    expect(asMoney('-5.0000')).toBe('-5.0000');
  });

  it('refuses text that is not a decimal string', () => {
    expect(() => asMoney('12,50')).toThrow(RangeError);
    expect(() => asQuantity('1.23456')).toThrow(RangeError);
  });
});
