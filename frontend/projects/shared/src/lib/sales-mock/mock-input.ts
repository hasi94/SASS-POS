import { isDecimalString } from '../sales/decimal-string';
import { MockRefusal, invalid } from './mock-envelope';

/**
 * Small readers for the request body. Each returns either the value or a `validation` refusal
 * naming the field, so a handler can return the refusal as it is.
 */

const ULID = /^[0-9A-HJKMNP-TV-Z]{26}$/;

export function asRecord(body: unknown): Record<string, unknown> {
  return typeof body === 'object' && body !== null && !Array.isArray(body)
    ? (body as Record<string, unknown>)
    : {};
}

export function isRefusal(value: unknown): value is MockRefusal {
  return typeof value === 'object' && value !== null && (value as { ok?: unknown }).ok === false;
}

export function readString(
  body: Record<string, unknown>,
  field: string,
  label = field,
): string | MockRefusal {
  const value = body[field];
  if (typeof value !== 'string' || value.trim() === '') {
    return invalid(field, `The ${label} is required.`);
  }
  return value;
}

export function readOptionalString(
  body: Record<string, unknown>,
  field: string,
): string | null | MockRefusal {
  const value = body[field];
  if (value === undefined || value === null) {
    return null;
  }
  if (typeof value !== 'string') {
    return invalid(field, `The ${field} must be text.`);
  }
  return value;
}

export function readUlid(body: Record<string, unknown>, field: string): string | MockRefusal {
  const value = body[field];
  if (typeof value !== 'string' || !ULID.test(value)) {
    return invalid(field, `The ${field} must be a ULID.`);
  }
  return value;
}

export interface DecimalRules {
  allowZero: boolean;
  allowNegative?: boolean;
}

export function readDecimal(
  body: Record<string, unknown>,
  field: string,
  rules: DecimalRules,
): string | MockRefusal {
  const value = body[field];
  if (typeof value !== 'string' || value === '') {
    return invalid(field, `The ${field} is required.`);
  }
  const options = { allowZero: rules.allowZero, allowNegative: rules.allowNegative ?? false };
  if (!isDecimalString(value, options)) {
    return invalid(
      field,
      rules.allowZero
        ? `The ${field} must be a decimal string with at most four decimal places, zero or more.`
        : `The ${field} must be a decimal string with at most four decimal places, more than zero.`,
    );
  }
  return value;
}
