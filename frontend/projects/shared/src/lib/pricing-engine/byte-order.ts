/*
 * Ordering helpers. Codes and ids are compared in UTF-8 byte order, as PHP `strcmp`; priorities,
 * sequences and counts are integers compared as bigint. JavaScript's default `<` on strings orders
 * by UTF-16 code unit, which puts a non-BMP character before a high BMP one: the wrong order.
 */

/**
 * -1, 0 or 1 by code point. For valid Unicode, UTF-8 byte order equals code-point order, so this is
 * `strcmp` without allocating a byte buffer. A lone surrogate sorts at its own code point.
 */
export function compareBytes(a: string, b: string): number {
  const left = Array.from(a, (char) => char.codePointAt(0) ?? 0);
  const right = Array.from(b, (char) => char.codePointAt(0) ?? 0);
  const shared = left.length < right.length ? left.length : right.length;

  for (let i = 0; i < shared; i++) {
    const x = left[i] ?? 0;
    const y = right[i] ?? 0;

    if (x !== y) {
      return x < y ? -1 : 1;
    }
  }

  return left.length === right.length ? 0 : left.length < right.length ? -1 : 1;
}

/** -1, 0 or 1. */
export function compareInt(a: bigint, b: bigint): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

/** Descending integer order. */
export function cmpDesc(a: bigint, b: bigint): number {
  return compareInt(b, a);
}
