/**
 * A small, in-repo ULID generator (T-037 D-12): 48 bits of millisecond time followed by 80
 * bits of randomness, written as 26 Crockford base32 characters, so ids sort by creation time.
 * The till makes one per tender and per cash movement and keeps it for retries of the same
 * submission. No dependency; the arithmetic is BigInt, so there is no floating point anywhere.
 */
const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const TIME_CHARS = 10;
const RANDOM_CHARS = 16;
const RANDOM_BYTES = 10;
const MASK_5_BITS = 31n;

function encode(value: bigint, length: number): string {
  let rest = value;
  let out = '';
  for (let i = 0; i < length; i++) {
    out = ALPHABET.charAt(Number(rest & MASK_5_BITS)) + out;
    rest = rest >> 5n;
  }
  return out;
}

function defaultRandom(bytes: Uint8Array): void {
  crypto.getRandomValues(bytes);
}

/**
 * Makes one ULID. `now` and `random` are injectable so a spec is deterministic; the defaults
 * are the wall clock and the platform's cryptographic random source.
 */
export function ulid(
  now: () => number = Date.now,
  random: (bytes: Uint8Array) => void = defaultRandom,
): string {
  const bytes = new Uint8Array(RANDOM_BYTES);
  random(bytes);
  let randomPart = 0n;
  for (const byte of bytes) {
    randomPart = (randomPart << 8n) | BigInt(byte);
  }
  return encode(BigInt(now()), TIME_CHARS) + encode(randomPart, RANDOM_CHARS);
}
