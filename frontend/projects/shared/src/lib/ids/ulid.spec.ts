import { ulid } from './ulid';

const CROCKFORD = /^[0-9A-HJKMNP-TV-Z]{26}$/;

describe('ulid', () => {
  it('is 26 characters of the Crockford alphabet', () => {
    expect(ulid()).toMatch(CROCKFORD);
  });

  it('sorts by time for an increasing clock', () => {
    const fixedRandom = (bytes: Uint8Array): void => {
      bytes.fill(7);
    };
    const earlier = ulid(() => 1_700_000_000_000, fixedRandom);
    const later = ulid(() => 1_700_000_000_001, fixedRandom);
    const muchLater = ulid(() => 1_800_000_000_000, fixedRandom);
    expect(earlier < later).toBe(true);
    expect(later < muchLater).toBe(true);
  });

  it('is deterministic with an injected clock and random source', () => {
    const random = (bytes: Uint8Array): void => {
      bytes.fill(255);
    };
    const first = ulid(() => 0, random);
    expect(first).toBe('0000000000ZZZZZZZZZZZZZZZZ');
    expect(ulid(() => 0, random)).toBe(first);
  });

  it('encodes the time part exactly', () => {
    const zeros = (bytes: Uint8Array): void => {
      bytes.fill(0);
    };
    // 32 is "10" in base 32.
    expect(ulid(() => 32, zeros)).toBe('00000000100000000000000000');
  });

  it('never repeats across many calls with the default random source', () => {
    const seen = new Set<string>();
    for (let i = 0; i < 500; i++) {
      seen.add(ulid());
    }
    expect(seen.size).toBe(500);
  });
});
