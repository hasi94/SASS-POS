import { checkDecimalInput } from './decimal-input';

describe('checkDecimalInput (AC-10, AC-26, AC-31)', () => {
  const cash = { label: 'Opening cash', allowZero: true };
  const weight = { label: 'Weight', allowZero: false };

  it('accepts plain decimal strings, with surrounding spaces', () => {
    for (const text of ['0', '0.0000', '5000', '12.5', ' 1250.5000 ', '1234567890123.1234']) {
      expect(checkDecimalInput(text, cash), text).toBeNull();
    }
  });

  it('names the field in every message', () => {
    expect(checkDecimalInput('', cash)).toBe('Opening cash is required');
    expect(checkDecimalInput('   ', cash)).toBe('Opening cash is required');
    expect(checkDecimalInput('abc', cash)).toBe('Opening cash must be a number such as 12.50');
    expect(checkDecimalInput('1e3', cash)).toBe('Opening cash must be a number such as 12.50');
    expect(checkDecimalInput('1,5', cash)).toBe('Opening cash must be a number such as 12.50');
    expect(checkDecimalInput('.5', cash)).toBe('Opening cash must be a number such as 12.50');
    expect(checkDecimalInput('-5', cash)).toBe('Opening cash cannot be negative');
    expect(checkDecimalInput('1.23456', cash)).toBe(
      'Opening cash can have at most 4 decimal places',
    );
  });

  it('refuses zero only where zero is not allowed', () => {
    expect(checkDecimalInput('0', cash)).toBeNull();
    expect(checkDecimalInput('0', weight)).toBe('Weight must be more than zero');
    expect(checkDecimalInput('0.0000', weight)).toBe('Weight must be more than zero');
    expect(checkDecimalInput('0.0001', weight)).toBeNull();
  });

  it('reads a negative zero as negative, not as zero', () => {
    expect(checkDecimalInput('-0.5', weight)).toBe('Weight cannot be negative');
  });
});
