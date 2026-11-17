/**
 * Plain decimal text, the only number syntax a form accepts (T-036 review W-1, W-4). Unlike
 * `Number(text)` these refuse `0x10`, `1e3` and `0b1`: what the person typed is what is sent.
 */

/** Digits with an optional sign and fraction: `-1.5`, `+2`, `.5`, `3.`. */
export const SIGNED_DECIMAL = /^[+-]?(\d+(\.\d*)?|\.\d+)$/;

/** As {@link SIGNED_DECIMAL} but a minus sign is not allowed. */
export const POSITIVE_DECIMAL = /^\+?(\d+(\.\d*)?|\.\d+)$/;

/** True when `text` is plain decimal text whose value is greater than 0. */
export function isGreaterThanZero(text: string): boolean {
  return POSITIVE_DECIMAL.test(text) && Number(text) > 0;
}
