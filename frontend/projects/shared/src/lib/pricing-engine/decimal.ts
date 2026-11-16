/*
 * Exact decimal arithmetic for the pricing engine: a BigInt unscaled value plus a small integer
 * scale, so no money value ever passes through a JavaScript number. Every Brick call in the PHP
 * engine has a twin here:
 *
 *   toScale(4, HalfUp)            -> roundStep, toScaleHalfUp
 *   dividedBy(x, 4, HalfUp)       -> divideToScale
 *   withPointMovedLeft(2)         -> movePointLeft
 *   toScale(4, Unnecessary)       -> the first check of text()
 *
 * Half-up here is "half away from zero", as Brick's HalfUp: BigInt division truncates toward zero,
 * so divideHalfUp is the one rounding site and adjusts by the sign.
 */

import { PricingRefused } from './refusal';

/** value = u / 10^s. The scale is a small non-negative count of digits, never a money value. */
export interface Dec {
  readonly u: bigint;
  readonly s: number;
}

/** The largest value a DECIMAL(18,4) column holds. */
export const MAX_AMOUNT = '99999999999999.9999';

const TEN = 10n;
const ZERO_BIG = 0n;

function pow10(digits: number): bigint {
  return TEN ** BigInt(digits);
}

function absBig(x: bigint): bigint {
  return x < ZERO_BIG ? -x : x;
}

/** Parses a canonical decimal string (digits, optional `.digits`). Anything else is an engine defect. */
export function dec(canonical: string): Dec {
  if (!/^\d+(\.\d+)?$/.test(canonical)) {
    throw new Error('dec(): not a canonical decimal: ' + canonical);
  }

  const dot = canonical.indexOf('.');

  if (dot < 0) {
    return { u: BigInt(canonical), s: 0 };
  }

  return {
    u: BigInt(canonical.slice(0, dot) + canonical.slice(dot + 1)),
    s: canonical.length - dot - 1,
  };
}

export const ZERO: Dec = { u: ZERO_BIG, s: 0 };
export const ONE: Dec = { u: 1n, s: 0 };
export const HUNDRED: Dec = { u: 100n, s: 0 };
/** MAX_AMOUNT as a Dec, a literal so that nothing runs when the module loads. */
const MAX: Dec = { u: 999999999999999999n, s: 4 };

function align(a: Dec, b: Dec): { a: bigint; b: bigint; s: number } {
  if (a.s === b.s) {
    return { a: a.u, b: b.u, s: a.s };
  }

  return a.s < b.s
    ? { a: a.u * pow10(b.s - a.s), b: b.u, s: b.s }
    : { a: a.u, b: b.u * pow10(a.s - b.s), s: a.s };
}

export function add(a: Dec, b: Dec): Dec {
  const x = align(a, b);

  return { u: x.a + x.b, s: x.s };
}

export function sub(a: Dec, b: Dec): Dec {
  const x = align(a, b);

  return { u: x.a - x.b, s: x.s };
}

export function mul(a: Dec, b: Dec): Dec {
  return { u: a.u * b.u, s: a.s + b.s };
}

/** -1, 0 or 1. */
export function cmp(a: Dec, b: Dec): number {
  const x = align(a, b);

  return x.a < x.b ? -1 : x.a > x.b ? 1 : 0;
}

export function isZero(a: Dec): boolean {
  return a.u === ZERO_BIG;
}

export function isNegative(a: Dec): boolean {
  return a.u < ZERO_BIG;
}

export function abs(a: Dec): Dec {
  return { u: absBig(a.u), s: a.s };
}

export function movePointLeft(a: Dec, places: number): Dec {
  return { u: a.u, s: a.s + places };
}

/**
 * n / d rounded to an integer, ties away from zero. The only rounding site in the engine.
 * A zero divisor is an engine defect and is unreachable from valid input.
 */
export function divideHalfUp(n: bigint, d: bigint): bigint {
  if (d === ZERO_BIG) {
    throw new Error('divideHalfUp(): division by zero');
  }

  const q = n / d;
  const r = n % d;

  if (2n * absBig(r) >= absBig(d)) {
    return n < ZERO_BIG === d < ZERO_BIG ? q + 1n : q - 1n;
  }

  return q;
}

/** Rescales to `scale` places, rounding half away from zero when digits are dropped. */
export function toScaleHalfUp(x: Dec, scale: number): Dec {
  if (x.s > scale) {
    return { u: divideHalfUp(x.u, pow10(x.s - scale)), s: scale };
  }

  return { u: x.u * pow10(scale - x.s), s: scale };
}

/** a / b to `scale` places, ties away from zero. */
export function divideToScale(a: Dec, b: Dec, scale: number): Dec {
  const e = scale + b.s - a.s;

  if (e >= 0) {
    return { u: divideHalfUp(a.u * pow10(e), b.u), s: scale };
  }

  return { u: divideHalfUp(a.u, b.u * pow10(-e)), s: scale };
}

/** Twin of `Decimal::checkRange`: `out_of_range` above DECIMAL(18,4). */
export function checkRange(x: Dec): void {
  if (cmp(abs(x), MAX) > 0) {
    throw new PricingRefused('out_of_range', 'The value does not fit DECIMAL(18,4).');
  }
}

/** Twin of `Decimal::round`: a named step, four decimals half-up, then the range check. */
export function roundStep(x: Dec): Dec {
  const rounded = toScaleHalfUp(x, 4);

  checkRange(rounded);

  return rounded;
}

/** Twin of `Decimal::percentOf`: `amount * percent / 100`, exact, rounded once. */
export function percentOf(amount: Dec, percent: Dec): Dec {
  const exact = divideToScale(mul(amount, percent), HUNDRED, 4);

  checkRange(exact);

  return exact;
}

/**
 * Twin of `Decimal::text`: the canonical output string, four decimals, in range, never negative.
 * An unrounded value (a digit beyond the fourth) is refused `invalid_amount`.
 */
export function text(x: Dec): string {
  if (x.s > 4 && x.u % pow10(x.s - 4) !== ZERO_BIG) {
    throw new PricingRefused(
      'invalid_amount',
      'The value has more than four decimals; an unrounded value must never reach an output.',
    );
  }

  const scaled = toScaleHalfUp(x, 4);

  checkRange(scaled);

  if (isNegative(scaled)) {
    throw new PricingRefused('out_of_range', 'The value is negative.');
  }

  const digits = scaled.u.toString().padStart(5, '0');

  return digits.slice(0, digits.length - 4) + '.' + digits.slice(digits.length - 4);
}
