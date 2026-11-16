/*
 * Test-only: replays one fixture through the operation its kind names, and compares the outcome
 * with what the fixture says (AC-33, AC-36 to AC-38). The twin of PHP's `FixtureReplay::replay`.
 */

import { calculateBillDiscount, calculateItemDiscount } from '../discount';
import { priceLine } from '../line';
import { resolvePrice } from '../price-ladder';
import { PricingRefused } from '../refusal';
import { calculateTax } from '../tax';
import type {
  BillDiscountInput,
  ItemDiscountInput,
  LineInput,
  PriceInput,
  TaxInput,
} from '../types';
import type { FixtureKind } from './fixture-lint';

/** Kind to operation: every kind has one, so a sixth kind fails to compile here. */
export const REPLAY: Record<FixtureKind, (input: unknown) => unknown> = {
  tax: (input) => calculateTax(input as TaxInput),
  price: (input) => resolvePrice(input as PriceInput),
  discount: (input) => calculateItemDiscount(input as ItemDiscountInput),
  line: (input) => priceLine(input as LineInput),
  bill: (input) => calculateBillDiscount(input as BillDiscountInput),
};

export type Outcome = { expect: unknown } | { refusal: string };

/** Engine outputs hold a number (the ladder step) where the fixture format holds a string. */
export function stringify(value: unknown): unknown {
  if (typeof value === 'number') {
    return String(value);
  }

  if (Array.isArray(value)) {
    return value.map((item: unknown) => stringify(item));
  }

  if (typeof value === 'object' && value !== null) {
    const copy: Record<string, unknown> = {};

    for (const [key, child] of Object.entries(value)) {
      copy[key] = stringify(child);
    }

    return copy;
  }

  return value;
}

/**
 * Runs the fixture. Catches only PricingRefused: an UnsupportedRuleError or PricingInputError is
 * not a refusal and must reach the caller, so it can never be swallowed as one (AC-38).
 */
export function replay(fixture: Record<string, unknown>): Outcome {
  const kind = fixture['kind'];
  const operation = Object.prototype.hasOwnProperty.call(REPLAY, String(kind))
    ? REPLAY[kind as FixtureKind]
    : undefined;

  if (operation === undefined) {
    throw new Error('Unknown fixture kind.');
  }

  try {
    return { expect: stringify(operation(fixture['input'])) };
  } catch (error) {
    if (error instanceof PricingRefused) {
      return { refusal: error.reason };
    }

    throw error;
  }
}

/** What a fixture says the answer is, in the shape `replay` returns. */
export function expected(fixture: Record<string, unknown>): Outcome {
  return Object.prototype.hasOwnProperty.call(fixture, 'refusal')
    ? { refusal: String(fixture['refusal']) }
    : { expect: fixture['expect'] };
}

function differences(actual: unknown, wanted: unknown, at: string, out: string[]): void {
  if (Array.isArray(actual) && Array.isArray(wanted)) {
    if (actual.length !== wanted.length) {
      out.push(at + ': length ' + String(actual.length) + ' != ' + String(wanted.length));
    }

    for (let index = 0; index < actual.length && index < wanted.length; index++) {
      differences(actual[index], wanted[index], at + '[' + String(index) + ']', out);
    }

    return;
  }

  if (
    typeof actual === 'object' &&
    actual !== null &&
    !Array.isArray(actual) &&
    typeof wanted === 'object' &&
    wanted !== null &&
    !Array.isArray(wanted)
  ) {
    const left = actual as Record<string, unknown>;
    const right = wanted as Record<string, unknown>;

    for (const key of new Set([...Object.keys(left), ...Object.keys(right)])) {
      if (!(key in left)) {
        out.push(at + '.' + key + ': missing from the engine output');
      } else if (!(key in right)) {
        out.push(at + '.' + key + ': not in the fixture');
      } else {
        differences(left[key], right[key], at + '.' + key, out);
      }
    }

    return;
  }

  if (actual !== wanted) {
    out.push(at + ': engine ' + JSON.stringify(actual) + ' != fixture ' + JSON.stringify(wanted));
  }
}

/**
 * Every difference between the engine and the fixture. Empty means parity. A mismatch is returned,
 * never thrown; an engine error that is not a refusal still throws.
 */
export function compareCase(fixture: Record<string, unknown>): string[] {
  const out: string[] = [];

  differences(replay(fixture), expected(fixture), '$', out);

  return out;
}
