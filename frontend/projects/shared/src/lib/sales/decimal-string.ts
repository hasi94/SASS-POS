/**
 * The single money helper of the till (T-037 D-6, AC-77). Money and quantities are decimal
 * strings exactly as the server sent them; the till never turns one into a JavaScript number.
 * This file is the only place in the till and the sales contract that may handle the digits:
 * it checks shape, compares two strings through scaled BigInt integers, and formats for
 * display without ever rounding. The money-rules scan excludes this file by path.
 */

/** A money amount as the server sent it, for example `"1250.0000"`. A string, never a number. */
export type Money = string & { readonly __brand: 'Money' };

/** A quantity as the server sent it, for example `"2.5000"`. A string, never a number. */
export type Quantity = string & { readonly __brand: 'Quantity' };

export interface DecimalOptions {
  /** Most digits after the point. Default 4, the scale of every money and quantity column. */
  maxScale?: number;
  /** Accept a leading minus. Default false. */
  allowNegative?: boolean;
  /** Accept a value equal to zero. Default true. */
  allowZero?: boolean;
}

const DECIMAL = /^(-)?(\d+)(?:\.(\d+))?$/;

interface Parts {
  negative: boolean;
  whole: string;
  fraction: string;
}

function split(text: string): Parts | null {
  const m = DECIMAL.exec(text);
  if (!m) {
    return null;
  }
  return { negative: m[1] === '-', whole: m[2] ?? '0', fraction: m[3] ?? '' };
}

function isZero(parts: Parts): boolean {
  return /^0*$/.test(parts.whole) && /^0*$/.test(parts.fraction);
}

/** True when `text` is a plain decimal string within the options. `" 1"`, `"1e3"`, `"1."` are not. */
export function isDecimalString(text: string, options: DecimalOptions = {}): boolean {
  const { maxScale = 4, allowNegative = false, allowZero = true } = options;
  const parts = split(text);
  if (!parts) {
    return false;
  }
  if (parts.negative && !allowNegative) {
    return false;
  }
  if (parts.fraction.length > maxScale) {
    return false;
  }
  if (!allowZero && isZero(parts)) {
    return false;
  }
  return true;
}

/** Validated cast: the text must be a decimal string (negative allowed) of at most four places. */
export function asMoney(text: string): Money {
  if (!isDecimalString(text, { allowNegative: true })) {
    throw new RangeError(`Not a money string: "${text}"`);
  }
  return text as Money;
}

/** Validated cast: the text must be a decimal string (negative allowed) of at most four places. */
export function asQuantity(text: string): Quantity {
  if (!isDecimalString(text, { allowNegative: true })) {
    throw new RangeError(`Not a quantity string: "${text}"`);
  }
  return text as Quantity;
}

function scaled(parts: Parts, scale: number): bigint {
  const digits = BigInt(parts.whole + parts.fraction.padEnd(scale, '0'));
  return parts.negative ? -digits : digits;
}

/** Compares two decimal strings exactly, whatever their scale or size. Throws on a non-decimal. */
export function compareDecimal(a: string, b: string): -1 | 0 | 1 {
  const left = split(a);
  const right = split(b);
  if (!left || !right) {
    throw new RangeError(`Cannot compare "${a}" with "${b}": not decimal strings.`);
  }
  const scale = Math.max(left.fraction.length, right.fraction.length);
  const x = scaled(left, scale);
  const y = scaled(right, scale);
  if (x === y) {
    return 0;
  }
  return x < y ? -1 : 1;
}

function group(whole: string): string {
  return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function trimmed(fraction: string, minDecimals: number): string {
  let end = fraction.length;
  while (end > minDecimals && fraction.charAt(end - 1) === '0') {
    end -= 1;
  }
  return fraction.slice(0, end).padEnd(minDecimals, '0');
}

function display(text: string, minDecimals: number): string {
  const parts = split(text);
  if (!parts) {
    // Never invent digits: a value that is not a decimal string is shown as it came.
    return text;
  }
  const whole = group(parts.whole.replace(/^0+(?=\d)/, ''));
  const fraction = trimmed(parts.fraction, minDecimals);
  const sign = parts.negative ? '-' : '';
  return fraction === '' ? `${sign}${whole}` : `${sign}${whole}.${fraction}`;
}

/** Money for display (D-7): thousands grouped, at least two decimals, never rounded. */
export function formatMoney(text: string): string {
  return display(text, 2);
}

/** A quantity for display (D-7): trailing zeros trimmed down to no decimals, never rounded. */
export function formatQuantity(text: string): string {
  return display(text, 0);
}
