import { PricingRefused } from './refusal';
import { loadFixtureSet } from './testing/fixture-files';
import type { FixtureFile } from './testing/fixture-files';
import { REPLAY, replay } from './testing/fixture-replay';
import type { FixtureKind } from './testing/fixture-lint';

// All cases are loaded before anything is stubbed or frozen (AC-43).
const set: FixtureFile[] = loadFixtureSet();

type Json = Record<string, unknown>;

/**
 * A fixture whose answer legitimately depends on list order, with the reason. None is expected: a
 * duplicate key is refused whatever the order, and a tie falls to a total order. A non-empty list
 * must be reported to the lead.
 */
const ORDER_DEPENDENT: Record<string, string> = {};

function cases(): { file: FixtureFile; fixture: Json }[] {
  return set.map((file) => ({ file, fixture: file.decoded as Json }));
}

/** Every path to an array anywhere under `node`. */
function arrayPaths(node: unknown, path: (string | number)[], found: (string | number)[][]): void {
  if (Array.isArray(node)) {
    found.push(path);
    node.forEach((child: unknown, index) => arrayPaths(child, [...path, index], found));
  } else if (typeof node === 'object' && node !== null) {
    for (const [key, child] of Object.entries(node)) {
      arrayPaths(child, [...path, key], found);
    }
  }
}

function at(root: unknown, path: (string | number)[]): unknown {
  let node = root;

  for (const step of path) {
    node = (node as Record<string | number, unknown>)[step];
  }

  return node;
}

function withArrayAt(
  fixture: Json,
  path: (string | number)[],
  change: (a: unknown[]) => unknown[],
): Json {
  const copy = structuredClone(fixture);
  const parent = at(copy, path.slice(0, -1)) as Record<string | number, unknown>;
  const last = path[path.length - 1] as string | number;

  parent[last] = change(parent[last] as unknown[]);

  return copy;
}

function deepFreeze<T>(value: T): T {
  if (typeof value === 'object' && value !== null && !Object.isFrozen(value)) {
    Object.freeze(value);

    for (const child of Object.values(value)) {
      deepFreeze(child);
    }
  }

  return value;
}

describe('determinism: list order (AC-39)', () => {
  it('names no order-dependent fixture, and every exclusion names a real fixture', () => {
    const names = new Set(set.map((file) => file.name));

    for (const name of Object.keys(ORDER_DEPENDENT)) {
      expect(names.has(name), 'excluded fixture does not exist: ' + name).toBe(true);
    }

    expect(Object.keys(ORDER_DEPENDENT)).toEqual([]);
  });

  it('finds lists to permute in a representative share of the set', () => {
    const withLists = cases().filter(({ fixture }) => {
      const found: (string | number)[][] = [];

      arrayPaths(fixture['input'], [], found);

      return found.length > 0;
    });

    expect(withLists.length).toBeGreaterThan(30);
  });

  it.each(cases().map(({ file, fixture }) => [file.kind + '/' + file.name, fixture] as const))(
    '%s gives the same result with every list reversed and rotated',
    (label, fixture) => {
      const name = label.split('/')[1] ?? '';

      if (name in ORDER_DEPENDENT) {
        return;
      }

      const original = replay(fixture);
      const found: (string | number)[][] = [];

      arrayPaths(fixture['input'], [], found);

      for (const path of found) {
        const full = ['input', ...path];

        const reversed = withArrayAt(fixture, full, (list) => [...list].reverse());
        const rotated = withArrayAt(fixture, full, (list) => [
          ...list.slice(1),
          ...list.slice(0, 1),
        ]);

        expect(replay(reversed), 'reversed ' + full.join('.')).toEqual(original);
        expect(replay(rotated), 'rotated ' + full.join('.')).toEqual(original);
      }
    },
  );

  it('control: a permutation that changes the data does change the result', () => {
    // Reversing the discounted subtotals of a bill cannot change the sum, but dropping one does:
    // proves the comparison above is capable of seeing a difference.
    const bill = set.find((file) => file.name === 'bill-percentage-on-summed-subtotals');
    const fixture = structuredClone(bill?.decoded) as Json;
    const input = fixture['input'] as { discounted_subtotals: string[] };

    input.discounted_subtotals = input.discounted_subtotals.slice(1);

    expect(replay(fixture)).not.toEqual(replay(bill?.decoded as Json));
  });
});

describe('determinism: no network, no clock (AC-43)', () => {
  type Slot = 'fetch' | 'XMLHttpRequest' | 'Date';

  const slots: Slot[] = ['fetch', 'XMLHttpRequest', 'Date'];
  const root = globalThis as unknown as Record<Slot, unknown>;

  /** Replaces the three globals with stubs that record the call and throw, runs `body`, restores. */
  function underStubs<T>(body: () => T): { value: T | undefined; calls: string[] } {
    const saved = new Map<Slot, unknown>(slots.map((slot) => [slot, root[slot]]));
    const calls: string[] = [];

    const stub = (slot: Slot) => {
      const thrower = function (): never {
        calls.push(slot);
        throw new Error('the engine touched ' + slot);
      };

      (thrower as unknown as Record<string, unknown>)['now'] = thrower;

      return thrower;
    };

    let value: T | undefined;

    try {
      for (const slot of slots) {
        root[slot] = stub(slot);
      }

      value = body();
    } finally {
      for (const slot of slots) {
        root[slot] = saved.get(slot);
      }
    }

    return { value, calls };
  }

  it('canary: the stubs record and throw when something does touch a global', () => {
    const result = underStubs(() => {
      const touched: string[] = [];

      for (const attempt of [
        () => (root.fetch as (url: string) => unknown)('https://example.invalid'),
        () => new (root.XMLHttpRequest as new () => unknown)(),
        () => (root.Date as unknown as { now: () => number }).now(),
      ]) {
        try {
          attempt();
        } catch (error) {
          touched.push((error as Error).message);
        }
      }

      return touched;
    });

    expect(result.value).toHaveLength(3);
    expect(result.calls).toEqual(['fetch', 'XMLHttpRequest', 'Date']);
  });

  it('restores the globals after the stubbed run', () => {
    const before = slots.map((slot) => root[slot]);

    underStubs(() => 0);

    expect(slots.map((slot) => root[slot])).toEqual(before);
  });

  it('replays every fixture with fetch, XMLHttpRequest and Date stubbed, unchanged and silent', () => {
    const plain = cases().map(({ fixture }) => replay(fixture));
    const stubbed = underStubs(() => cases().map(({ fixture }) => replay(fixture)));

    // Asserted only after the globals are restored.
    expect(stubbed.calls).toEqual([]);
    expect(stubbed.value).toEqual(plain);
    expect(stubbed.value).toHaveLength(set.length);
  });
});

describe('determinism: no mutation, no state (AC-44)', () => {
  it('canary: a deep-frozen input rejects a write at any depth', () => {
    const frozen = deepFreeze({ list: [{ value: 'x' }] });

    expect(() => {
      frozen.list[0]!.value = 'y';
    }).toThrow(TypeError);
    expect(() => {
      frozen.list.push({ value: 'z' });
    }).toThrow(TypeError);
  });

  it.each(
    cases().map(({ file, fixture }) => [file.kind + '/' + file.name, file.kind, fixture] as const),
  )('%s: a frozen input is not mutated and two calls agree', (_label, kind, fixture) => {
    const input = deepFreeze(structuredClone(fixture['input']));
    const before = JSON.stringify(input);
    const operation = REPLAY[kind as FixtureKind];

    const run = (): unknown => {
      try {
        return operation(input);
      } catch (error) {
        if (error instanceof PricingRefused) {
          return { refusal: error.reason };
        }

        throw error;
      }
    };

    const first = run();
    const second = run();

    expect(second).toEqual(first);
    expect(JSON.stringify(input)).toBe(before);
  });
});

describe('determinism: synchronous and plain (AC-45)', () => {
  function assertPlain(value: unknown, at: string): void {
    if (Array.isArray(value)) {
      value.forEach((child: unknown, index) => assertPlain(child, at + '[' + String(index) + ']'));

      return;
    }

    if (typeof value === 'object' && value !== null) {
      expect(Object.getPrototypeOf(value), at).toBe(Object.prototype);
      expect('then' in value, at + ' has a then').toBe(false);

      for (const [key, child] of Object.entries(value)) {
        assertPlain(child, at + '.' + key);
      }
    }
  }

  it.each(
    cases().map(({ file, fixture }) => [file.kind + '/' + file.name, file.kind, fixture] as const),
  )('%s returns a plain value or throws, never a promise', (_label, kind, fixture) => {
    const operation = REPLAY[kind as FixtureKind];
    let result: unknown;
    let refusal: unknown;

    try {
      result = operation(fixture['input']);
    } catch (error) {
      refusal = error;
    }

    if (refusal !== undefined) {
      expect(refusal).toBeInstanceOf(PricingRefused);

      return;
    }

    expect(result).not.toBeInstanceOf(Promise);
    expect(typeof (result as { then?: unknown }).then).toBe('undefined');
    expect(Object.getPrototypeOf(result)).toBe(Object.prototype);
    assertPlain(result, '$');
  });

  it('canary: assertPlain bites on a class instance and on a thenable', () => {
    class Boxed {
      readonly x = 1;
    }

    expect(() => assertPlain({ nested: new Boxed() }, '$')).toThrow();
    expect(() => assertPlain({ then: () => 0 }, '$')).toThrow();
  });
});
