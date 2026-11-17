import { POSITIVE_DECIMAL, SIGNED_DECIMAL, isGreaterThanZero } from './decimal';

describe('decimal text (T-036 review W-1, W-4)', () => {
  it.each(['0', '1', '1.5', '.5', '3.', '+2', '-1.5', '-.5'])('%s is signed decimal text', (t) => {
    expect(SIGNED_DECIMAL.test(t)).toBe(true);
  });

  it.each(['', 'abc', '0x10', '1e3', '0b1', '1,5', '--1', '1 2', ' 1', '.'])(
    '%s is not signed decimal text',
    (t) => {
      expect(SIGNED_DECIMAL.test(t)).toBe(false);
    },
  );

  it('positive decimal text refuses a minus sign but allows a plus', () => {
    expect(POSITIVE_DECIMAL.test('-1')).toBe(false);
    expect(POSITIVE_DECIMAL.test('+1')).toBe(true);
  });

  it.each(['1', '0.5', '.5', '24', '+3'])('%s is greater than zero', (t) => {
    expect(isGreaterThanZero(t)).toBe(true);
  });

  it.each(['0', '0.0', '-1', '0x10', '1e3', '0b1', 'abc', ''])('%s is not', (t) => {
    expect(isGreaterThanZero(t)).toBe(false);
  });
});
