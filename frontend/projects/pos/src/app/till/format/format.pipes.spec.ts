import { MoneyPipe } from './money.pipe';
import { QuantityPipe } from './quantity.pipe';

describe('MoneyPipe (AC-78)', () => {
  const pipe = new MoneyPipe();

  const rows: [string, string][] = [
    ['0.0000', '0.00'],
    ['1250.5000', '1,250.50'],
    ['0.0125', '0.0125'],
    ['1234567.8900', '1,234,567.89'],
    ['-5.0000', '-5.00'],
  ];
  it.each(rows)('shows %s as %s', (input, expected) => {
    expect(pipe.transform(input)).toBe(expected);
  });

  it('adds the currency code the server supplied', () => {
    expect(pipe.transform('1250.5000', 'LKR')).toBe('1,250.50 LKR');
  });

  it('shows nothing for nothing', () => {
    expect(pipe.transform(null, 'LKR')).toBe('');
    expect(pipe.transform(undefined)).toBe('');
    expect(pipe.transform('')).toBe('');
  });
});

describe('QuantityPipe (AC-79)', () => {
  const pipe = new QuantityPipe();

  it('trims trailing zeros and adds the unit code', () => {
    expect(pipe.transform('2.0000', 'kg')).toBe('2 kg');
    expect(pipe.transform('0.7500', 'kg')).toBe('0.75 kg');
    expect(pipe.transform('12.3450', 'ea')).toBe('12.345 ea');
  });

  it('works without a unit', () => {
    expect(pipe.transform('3.0000')).toBe('3');
  });

  it('shows nothing for nothing', () => {
    expect(pipe.transform(null)).toBe('');
  });
});
