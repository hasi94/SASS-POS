/**
 * Exact decimal arithmetic for the sales mock (T-037 AC-6): BigInt integers scaled to four
 * decimals, never JavaScript floating point. This arithmetic exists only under `sales-mock/`
 * and is never imported by application code (the seam rule in `money-rules.spec.ts`).
 *
 * Rounding is half up in magnitude (half away from zero), applied once, at the end of a
 * multiplication.
 */

/** Digits after the point in every money and quantity string the mock returns. */
export const SCALE = 4;

const DECIMAL = /^(-)?(\d+)(?:\.(\d+))?$/;

/** A decimal read exactly: `units` scaled by ten to the power `scale`. */
export interface Dec {
  readonly units: bigint;
  readonly scale: number;
}

export function parseDec(text: string): Dec {
  const m = DECIMAL.exec(text);
  if (!m) {
    throw new RangeError(`Not a decimal string: "${text}"`);
  }
  const fraction = m[3] ?? '';
  const digits = BigInt((m[2] ?? '0') + fraction);
  return { units: m[1] === '-' ? -digits : digits, scale: fraction.length };
}

function pow10(exponent: number): bigint {
  return 10n ** BigInt(exponent);
}

/** Moves a decimal to `scale` places, rounding half up in magnitude when it must shrink. */
function rescale(value: Dec, scale: number): bigint {
  if (value.scale <= scale) {
    return value.units * pow10(scale - value.scale);
  }
  const divisor = pow10(value.scale - scale);
  const magnitude = value.units < 0n ? -value.units : value.units;
  const quotient = magnitude / divisor;
  const remainder = magnitude % divisor;
  const rounded = remainder * 2n >= divisor ? quotient + 1n : quotient;
  return value.units < 0n ? -rounded : rounded;
}

/** Reads a decimal string as units of 0.0001 (rounded half up if it has more places). */
export function toUnits(text: string): bigint {
  return rescale(parseDec(text), SCALE);
}

/** Writes units of 0.0001 as a four-decimal string. */
export function fromUnits(units: bigint): string {
  const negative = units < 0n;
  const digits = (negative ? -units : units).toString().padStart(SCALE + 1, '0');
  const whole = digits.slice(0, digits.length - SCALE);
  const fraction = digits.slice(digits.length - SCALE);
  return `${negative ? '-' : ''}${whole}.${fraction}`;
}

/** Normalises any decimal string to four decimals. */
export function format4(text: string): string {
  return fromUnits(toUnits(text));
}

export function add(a: string, b: string): string {
  return fromUnits(toUnits(a) + toUnits(b));
}

export function sub(a: string, b: string): string {
  return fromUnits(toUnits(a) - toUnits(b));
}

/** The product of two decimals, rounded half up at the fourth decimal. */
export function mul(a: string, b: string): string {
  const x = parseDec(a);
  const y = parseDec(b);
  return fromUnits(rescale({ units: x.units * y.units, scale: x.scale + y.scale }, SCALE));
}

export function cmp(a: string, b: string): -1 | 0 | 1 {
  const x = toUnits(a);
  const y = toUnits(b);
  if (x === y) {
    return 0;
  }
  return x < y ? -1 : 1;
}

export function min(a: string, b: string): string {
  return cmp(a, b) <= 0 ? format4(a) : format4(b);
}

export function isZero(a: string): boolean {
  return toUnits(a) === 0n;
}

export function isNegative(a: string): boolean {
  return toUnits(a) < 0n;
}

/** Sums a list, starting from `0.0000`. */
export function sum(values: readonly string[]): string {
  let total = 0n;
  for (const value of values) {
    total = total + toUnits(value);
  }
  return fromUnits(total);
}

export const ZERO = '0.0000';
