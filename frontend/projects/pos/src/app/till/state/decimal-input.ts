import { compareDecimal, isDecimalString } from '@flexipos/shared';

export interface DecimalRules {
  /** The field's name as the cashier knows it; the message names it (AC-10, AC-26, AC-31). */
  label: string;
  /** Whether zero is acceptable (opening cash and a counted figure: yes; a weight: no). */
  allowZero: boolean;
}

/**
 * Checks what a cashier typed into an amount or quantity field before any call is made. Returns
 * the message to show, or null when the text is acceptable. The check is on the digits as text
 * (shape, precision, sign, zero) and never turns them into a number (D-6).
 */
export function checkDecimalInput(text: string, rules: DecimalRules): string | null {
  const value = text.trim();
  const { label } = rules;
  if (value === '') {
    return `${label} is required`;
  }
  if (!isDecimalString(value, { maxScale: 99, allowNegative: true })) {
    return `${label} must be a number such as 12.50`;
  }
  if (!isDecimalString(value, { allowNegative: true })) {
    return `${label} can have at most 4 decimal places`;
  }
  const sign = compareDecimal(value, '0');
  if (sign < 0) {
    return `${label} cannot be negative`;
  }
  if (sign === 0 && !rules.allowZero) {
    return `${label} must be more than zero`;
  }
  return null;
}
