import {
  add,
  cmp,
  format4,
  fromUnits,
  isNegative,
  isZero,
  min,
  mul,
  sub,
  sum,
  toUnits,
} from './mock-decimal';

describe('mock-decimal', () => {
  it('adds exactly: 0.1 + 0.2 is 0.3000', () => {
    expect(add('0.1', '0.2')).toBe('0.3000');
  });

  it('multiplies large values exactly: 1234567.8912 x 3.0000', () => {
    expect(mul('1234567.8912', '3.0000')).toBe('3703703.6736');
  });

  it('rounds half up at the fifth decimal', () => {
    // 0.0001 x 0.5 = 0.00005
    expect(mul('0.0001', '0.5')).toBe('0.0001');
    // 0.0001 x 0.4999 = 0.00004999
    expect(mul('0.0001', '0.4999')).toBe('0.0000');
    // 1.0001 x 1.5 = 1.50015
    expect(mul('1.0001', '1.5')).toBe('1.5002');
    // 1.0003 x 1.5 = 1.50045
    expect(mul('1.0003', '1.5')).toBe('1.5005');
  });

  it('rounds a negative half away from zero', () => {
    expect(mul('-0.0001', '0.5')).toBe('-0.0001');
    expect(mul('-1.0001', '1.5')).toBe('-1.5002');
    expect(mul('1.0001', '-1.5')).toBe('-1.5002');
    expect(mul('-0.0001', '0.4999')).toBe('0.0000');
  });

  it('keeps the sign through add and subtract', () => {
    expect(add('-1.5', '0.25')).toBe('-1.2500');
    expect(sub('1', '2.5')).toBe('-1.5000');
    expect(sub('0', '0')).toBe('0.0000');
  });

  it('is exact beyond 2^53', () => {
    expect(add('9007199254740993.0001', '0.0001')).toBe('9007199254740993.0002');
    expect(mul('9007199254740993', '2')).toBe('18014398509481986.0000');
    expect(sub('99999999999999999999.9999', '0.0001')).toBe('99999999999999999999.9998');
  });

  it('multiplies by a factor of six decimals without losing a digit', () => {
    // 2.5 x 24.000000
    expect(mul('2.5', '24.000000')).toBe('60.0000');
    // 3 x 1.234567 = 3.703701
    expect(mul('3', '1.234567')).toBe('3.7037');
  });

  it('compares and takes the smaller', () => {
    expect(cmp('1.5', '1.5000')).toBe(0);
    expect(cmp('1', '2')).toBe(-1);
    expect(cmp('-1', '-2')).toBe(1);
    expect(min('3', '2.9999')).toBe('2.9999');
    expect(min('1', '1.0000')).toBe('1.0000');
  });

  it('formats to four decimals', () => {
    expect(format4('5')).toBe('5.0000');
    expect(format4('0.5')).toBe('0.5000');
    expect(fromUnits(5n)).toBe('0.0005');
    expect(fromUnits(-5n)).toBe('-0.0005');
    expect(fromUnits(0n)).toBe('0.0000');
    expect(toUnits('12.3456')).toBe(123456n);
  });

  it('knows zero and negative', () => {
    expect(isZero('0')).toBe(true);
    expect(isZero('0.0000')).toBe(true);
    expect(isZero('0.0001')).toBe(false);
    expect(isNegative('-0.0001')).toBe(true);
    expect(isNegative('0')).toBe(false);
  });

  it('sums a list', () => {
    expect(sum([])).toBe('0.0000');
    expect(sum(['0.1', '0.2', '0.3'])).toBe('0.6000');
  });

  it('refuses text that is not a decimal', () => {
    expect(() => add('abc', '1')).toThrow(RangeError);
  });
});
