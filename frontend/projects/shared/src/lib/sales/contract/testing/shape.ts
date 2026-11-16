/**
 * Key-exhaustive runtime validators (T-037 P-13). `shape<T>()` takes one check per key of `T`
 * and the compiler forces the list to be complete (`-?` makes optional keys required here), so a
 * type that gains a key breaks the validator until it is updated. At run time a check rejects a
 * missing key, an extra key and a wrong kind of value. Test-only: not exported from the public
 * API, and the money-rules scan excludes this folder.
 */
import { isDecimalString } from '../../decimal-string';

/** Returns a problem description, or null when the value is acceptable. */
export type Check = (value: unknown, path: string) => string | null;

const ULID = /^[0-9A-HJKMNP-TV-Z]{26}$/;

function describe(value: unknown): string {
  return typeof value === 'string' ? `"${value}"` : (JSON.stringify(value) ?? String(value));
}

function kindCheck(name: string, test: (value: unknown) => boolean): Check {
  return (value, path) =>
    test(value) ? null : `${path}: expected ${name}, got ${describe(value)}`;
}

export const str: Check = kindCheck('a string', (v) => typeof v === 'string');
export const bool: Check = kindCheck('a boolean', (v) => typeof v === 'boolean');
export const int: Check = kindCheck(
  'an integer',
  (v) => typeof v === 'number' && Number.isInteger(v),
);
export const money: Check = kindCheck(
  'a money string',
  (v) => typeof v === 'string' && isDecimalString(v, { allowNegative: true }),
);
export const quantity: Check = money;
export const ulid: Check = kindCheck('a ULID', (v) => typeof v === 'string' && ULID.test(v));

export function nullable(inner: Check): Check {
  return (value, path) => (value === null ? null : inner(value, path));
}

export function oneOf(values: readonly string[]): Check {
  return kindCheck(
    `one of ${values.join('|')}`,
    (v) => typeof v === 'string' && values.includes(v),
  );
}

export function arrayOf(inner: Check): Check {
  return (value, path) => {
    if (!Array.isArray(value)) {
      return `${path}: expected an array, got ${describe(value)}`;
    }
    for (let i = 0; i < value.length; i++) {
      const problem = inner(value[i], `${path}[${i}]`);
      if (problem) {
        return problem;
      }
    }
    return null;
  };
}

/** An object whose every value passes `inner` (keys are open). */
export function recordOf(inner: Check): Check {
  return (value, path) => {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
      return `${path}: expected an object, got ${describe(value)}`;
    }
    for (const [key, item] of Object.entries(value)) {
      const problem = inner(item, `${path}.${key}`);
      if (problem) {
        return problem;
      }
    }
    return null;
  };
}

/** A check for an object with exactly the keys of `T`, each passing its own check. */
export function shape<T>(spec: { [K in keyof T]-?: Check }): Check {
  const keys = Object.keys(spec);
  return (value, path) => {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
      return `${path}: expected an object, got ${describe(value)}`;
    }
    const record = value as Record<string, unknown>;
    for (const key of keys) {
      if (!(key in record)) {
        return `${path}: missing key "${key}"`;
      }
    }
    for (const key of Object.keys(record)) {
      if (!keys.includes(key)) {
        return `${path}: unexpected key "${key}"`;
      }
    }
    for (const key of keys) {
      const check = (spec as Record<string, Check>)[key];
      const problem = check?.(record[key], `${path}.${key}`);
      if (problem) {
        return problem;
      }
    }
    return null;
  };
}

/**
 * Like {@link shape}, for a type whose keys are optional: a key may be absent, but one that is
 * present must pass its check and an unknown key is still rejected.
 */
export function partialShape<T>(spec: { [K in keyof T]-?: Check }): Check {
  const keys = Object.keys(spec);
  return (value, path) => {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
      return `${path}: expected an object, got ${describe(value)}`;
    }
    const record = value as Record<string, unknown>;
    for (const key of Object.keys(record)) {
      if (!keys.includes(key)) {
        return `${path}: unexpected key "${key}"`;
      }
      const check = (spec as Record<string, Check>)[key];
      const problem = check?.(record[key], `${path}.${key}`);
      if (problem) {
        return problem;
      }
    }
    return null;
  };
}

/** Runs a check at the root. */
export function problemWith(check: Check, value: unknown): string | null {
  return check(value, '$');
}
