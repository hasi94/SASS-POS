import { cmpDesc, compareBytes, compareInt } from './byte-order';

describe('compareBytes', () => {
  const codes = ['a', '9', 'B', '10', '\u{1F600}', '～'];

  it('orders by UTF-8 byte order, as strcmp', () => {
    expect([...codes].sort(compareBytes)).toEqual(['10', '9', 'B', 'a', '～', '\u{1F600}']);
  });

  it('differs from the JavaScript default for a non-BMP character against a high BMP one', () => {
    // UTF-16 code units: the emoji is the pair D83D DE00, which sorts before FF5E.
    expect('\u{1F600}' < '～').toBe(true);
    expect(compareBytes('\u{1F600}', '～')).toBe(1);
    expect([...codes].sort()).not.toEqual([...codes].sort(compareBytes));
  });

  it('is not numeric: "10" sorts before "9"', () => {
    expect(compareBytes('10', '9')).toBe(-1);
  });

  it('puts a prefix first and equal strings equal', () => {
    expect(compareBytes('ab', 'abc')).toBe(-1);
    expect(compareBytes('abc', 'ab')).toBe(1);
    expect(compareBytes('abc', 'abc')).toBe(0);
    expect(compareBytes('', 'a')).toBe(-1);
  });

  it('is case sensitive, upper case first', () => {
    expect(compareBytes('B', 'a')).toBe(-1);
  });
});

describe('compareInt', () => {
  it('orders as integers, so 9 sorts before 10', () => {
    expect(['10', '9', '-1'].map((x) => BigInt(x)).sort(compareInt)).toEqual([-1n, 9n, 10n]);
  });

  it('handles the signed 64-bit extremes without losing a digit', () => {
    const top = 9223372036854775807n;
    const bottom = -9223372036854775808n;

    expect(compareInt(top, top - 1n)).toBe(1);
    expect(compareInt(bottom, bottom + 1n)).toBe(-1);
    expect(compareInt(bottom, top)).toBe(-1);
    expect(compareInt(top, top)).toBe(0);
  });

  it('cmpDesc reverses it', () => {
    expect([1n, 3n, 2n].sort(cmpDesc)).toEqual([3n, 2n, 1n]);
  });
});
