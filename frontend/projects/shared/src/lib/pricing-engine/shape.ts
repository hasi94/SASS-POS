/*
 * The shape checks every engine input shares: twins of PHP's `Shape`, plus the guards that read
 * fields out of a decoded JSON object. Every field is treated as `unknown` and checked, so a
 * JavaScript number or a bigint where a string is required is refused, never coerced.
 *
 * Two different failures, as in PHP (D-7):
 * - a malformed amount (money, quantity, rate, factor) is the refusal `invalid_amount`;
 * - a malformed id, date, instant, currency, integer or field shape is a PricingInputError.
 */

import { Dec, dec } from './decimal';
import { PricingInputError, PricingRefused, UnsupportedRuleError } from './refusal';

const MONEY_PATTERN = /^(\d{1,14})(?:\.(\d{1,4}))?$/;
const RATE_PATTERN = /^(\d{1,5})(?:\.(\d{1,4}))?$/;
const FACTOR_PATTERN = /^(\d{1,12})(?:\.(\d{1,6}))?$/;

const INT64_MAX = 9223372036854775807n;
const INT64_MIN = -9223372036854775808n;

function canonicalDecimal(value: unknown, pattern: RegExp, scale: number, what: string): string {
  const parts = typeof value === 'string' ? pattern.exec(value) : null;

  if (parts === null) {
    throw new PricingRefused('invalid_amount', '[' + what + '] is not a plain decimal string.');
  }

  const integer = (parts[1] ?? '').replace(/^0+/, '');

  return (integer === '' ? '0' : integer) + '.' + (parts[2] ?? '').padEnd(scale, '0');
}

/** A money or quantity amount, DECIMAL(18,4), never negative. Returns the canonical string. */
export function money(value: unknown, what: string): string {
  return canonicalDecimal(value, MONEY_PATTERN, 4, what);
}

/** A rate or percentage, DECIMAL(9,4), never negative. `15.0000` means 15 %. */
export function rate(value: unknown, what: string): string {
  return canonicalDecimal(value, RATE_PATTERN, 4, what);
}

/** A unit factor, DECIMAL(18,6), at least 1. */
export function factor(value: unknown, what: string): string {
  const canonical = canonicalDecimal(value, FACTOR_PATTERN, 6, what);

  if (canonical.startsWith('0.')) {
    throw new PricingRefused('invalid_amount', '[' + what + '] must be at least 1.');
  }

  return canonical;
}

export function moneyDec(value: unknown, what: string): Dec {
  return dec(money(value, what));
}

export function rateDec(value: unknown, what: string): Dec {
  return dec(rate(value, what));
}

export function factorDec(value: unknown, what: string): Dec {
  return dec(factor(value, what));
}

/** A 26-character alphanumeric id, returned upper-cased. */
export function ulid(value: unknown, what: string): string {
  if (typeof value !== 'string' || !/^[0-9A-Za-z]{26}$/.test(value)) {
    throw new PricingInputError('[' + what + '] is not a 26-character ULID.');
  }

  return value.toUpperCase();
}

function isLeap(year: number): boolean {
  return year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
}

/** Twin of PHP `checkdate`: year 1 to 32767, a real month and day. */
function isCalendarDate(year: number, month: number, day: number): boolean {
  if (year < 1 || year > 32767 || month < 1 || month > 12 || day < 1) {
    return false;
  }

  const lengths = [31, isLeap(year) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

  return day <= (lengths[month - 1] ?? 0);
}

function digits(part: string | undefined): number {
  return parseInt(part ?? '', 10);
}

/** `YYYY-MM-DD`, a real calendar date. */
export function date(value: unknown, what: string): string {
  const parts = typeof value === 'string' ? /^(\d{4})-(\d{2})-(\d{2})$/.exec(value) : null;

  if (parts === null || !isCalendarDate(digits(parts[1]), digits(parts[2]), digits(parts[3]))) {
    throw new PricingInputError('[' + what + '] must be a valid Y-m-d date.');
  }

  return parts[0];
}

/** `YYYY-MM-DDTHH:MM:SS.mmmZ`, UTC. The fixed width lets two instants compare as plain strings. */
export function instant(value: unknown, what: string): string {
  const parts =
    typeof value === 'string'
      ? /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\.\d{3}Z$/.exec(value)
      : null;

  if (
    parts === null ||
    !isCalendarDate(digits(parts[1]), digits(parts[2]), digits(parts[3])) ||
    digits(parts[4]) >= 24 ||
    digits(parts[5]) >= 60 ||
    digits(parts[6]) >= 60
  ) {
    throw new PricingInputError(
      '[' + what + '] must be a valid UTC instant, YYYY-MM-DDTHH:MM:SS.mmmZ.',
    );
  }

  return parts[0];
}

/** Three upper-case letters. */
export function currency(value: unknown, what: string): string {
  if (typeof value !== 'string' || !/^[A-Z]{3}$/.test(value)) {
    throw new PricingInputError('[' + what + '] must be three upper-case letters.');
  }

  return value;
}

// ------------------------------------------------------------ field guards

export type Obj = Record<string, unknown>;

/** A decoded JSON object (not null, not an array). */
export function obj(value: unknown, what: string): Obj {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    throw new PricingInputError('[' + what + '] must be an object.');
  }

  return value as Obj;
}

/** A decoded JSON array. */
export function list(node: Obj, key: string): unknown[] {
  const value = node[key];

  if (!Array.isArray(value)) {
    throw new PricingInputError('[' + key + '] must be a list.');
  }

  return value as unknown[];
}

/**
 * An amount field read as it came: a money, rate or factor check follows, and a value that is not
 * a plain decimal string (a number, a bigint, null, a missing key) is refused `invalid_amount`
 * there, which is what AC-5 requires.
 */
export function raw(node: Obj, key: string): unknown {
  return node[key];
}

/** A nullable amount: `null` is absent, a missing key is an error, anything else is checked later. */
export function rawOrNull(node: Obj, key: string): unknown {
  const value = node[key];

  if (value === undefined) {
    throw new PricingInputError('[' + key + '] is missing (use null).');
  }

  return value;
}

export function str(node: Obj, key: string): string {
  const value = node[key];

  if (typeof value !== 'string') {
    throw new PricingInputError('[' + key + '] must be a string.');
  }

  return value;
}

/**
 * A string that must belong to a closed vocabulary. The rule pre-pass already refused an unknown
 * value, so the throw here is the same error, kept as a second line of defence.
 */
export function enumOf<T extends string>(node: Obj, key: string, vocabulary: readonly T[]): T {
  const value = str(node, key);

  if (!(vocabulary as readonly string[]).includes(value)) {
    throw new UnsupportedRuleError(key, value);
  }

  return value as T;
}

/** `null` is absent; `undefined` (a missing key) is an error: the caller must say null. */
export function strOrNull(node: Obj, key: string): string | null {
  const value = node[key];

  if (value === undefined) {
    throw new PricingInputError('[' + key + '] is missing (use null).');
  }

  return value === null ? null : str(node, key);
}

export function bool(node: Obj, key: string): boolean {
  const value = node[key];

  if (typeof value !== 'boolean') {
    throw new PricingInputError('[' + key + '] must be a boolean.');
  }

  return value;
}

/** An integer carried as a string, signed 64-bit. Compared as bigint, never through a number. */
export function int(node: Obj, key: string): bigint {
  const value = str(node, key);

  if (!/^-?\d+$/.test(value)) {
    throw new PricingInputError('[' + key + '] must be an integer string.');
  }

  const parsed = BigInt(value);

  if (parsed > INT64_MAX || parsed < INT64_MIN) {
    throw new PricingInputError('[' + key + '] does not fit a signed 64-bit integer.');
  }

  return parsed;
}

export function intOrNull(node: Obj, key: string): bigint | null {
  return strOrNull(node, key) === null ? null : int(node, key);
}

/** A list of strings (`applies_to.products` and the like). */
export function strings(node: Obj, key: string): string[] {
  return list(node, key).map((item) => {
    if (typeof item !== 'string') {
      throw new PricingInputError('[' + key + '] holds a non-string.');
    }

    return item;
  });
}
