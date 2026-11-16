import { UnsupportedRuleError } from './refusal';
import { loadFixtureSet, locateRepoRoot } from './testing/fixture-files';
import type { FixtureFile } from './testing/fixture-files';
import {
  FIXTURE_KINDS,
  assertFixtureSet,
  lintFixture,
  MINIMUM_FIXTURES,
} from './testing/fixture-lint';
import { compareCase, expected, replay } from './testing/fixture-replay';

// The set is read in place from packages/pricing/fixtures, at collection time. A missing or empty
// directory throws here, which fails the spec file: it never skips and never passes vacuously.
const set: FixtureFile[] = loadFixtureSet();

assertFixtureSet(set);

type Json = Record<string, unknown>;

function fixtureNamed(name: string): FixtureFile {
  const found = set.find((file) => file.name === name);

  if (found === undefined) {
    throw new Error('control fixture not found: ' + name);
  }

  return found;
}

function cloneOf(name: string): Json {
  return structuredClone(fixtureNamed(name).decoded) as Json;
}

/** `24.1650` becomes `24.1651`. */
function plusOneUnit(decimal: string): string {
  const digits = (BigInt(decimal.replace('.', '')) + 1n).toString().padStart(5, '0');

  return digits.slice(0, digits.length - 4) + '.' + digits.slice(digits.length - 4);
}

describe('the shared fixture set', () => {
  it('is read in place and found by glob', () => {
    console.info('pricing fixtures: ' + String(set.length) + ' files from ' + locateRepoRoot());
    expect(set.length).toBeGreaterThanOrEqual(MINIMUM_FIXTURES);
    expect(new Set(set.map((file) => file.kind))).toEqual(new Set(FIXTURE_KINDS));
    expect(set.every((file) => file.path.includes('/packages/pricing/fixtures/'))).toBe(true);
  });

  describe.each(set.map((file) => [file.kind + '/' + file.name, file] as const))(
    '%s',
    (_label, file) => {
      it('lints clean', () => {
        expect(lintFixture(file.path, file.decoded)).toEqual([]);
      });

      it('replays to exactly what the fixture says', () => {
        expect(file.parseError).toBeNull();
        expect(compareCase(file.decoded as Json)).toEqual([]);
      });
    },
  );
});

describe('fixture lint and replay controls', () => {
  it('a wrong expected amount makes compareCase report a difference (AC-36a)', () => {
    const control = cloneOf('tax-exclusive-single-15');
    const expect_ = control['expect'] as { total: string };

    expect(compareCase(control)).toEqual([]);

    expect_.total = plusOneUnit(expect_.total);

    expect(compareCase(control)).not.toEqual([]);
  });

  it('a bare JSON number is a lint problem (AC-36b)', () => {
    const control = cloneOf('price-step-1-customer-list-wins');
    const input = control['input'] as { lists: { priority: unknown }[] };

    expect(lintFixture(fixtureNamed('price-step-1-customer-list-wins').path, control)).toEqual([]);

    input.lists[0]!.priority = 0;

    const problems = lintFixture(fixtureNamed('price-step-1-customer-list-wins').path, control);

    expect(problems.length).toBeGreaterThan(0);
    expect(problems.join(' ')).toContain('JSON number');
  });

  it('a decimal without its full scale is a lint problem', () => {
    const control = cloneOf('tax-exclusive-single-15');

    (control['input'] as { amount: string }).amount = '100.00';

    expect(lintFixture(fixtureNamed('tax-exclusive-single-15').path, control).join(' ')).toContain(
      'full scale',
    );
  });

  it('a refusal fixture naming a different reason makes compareCase report a difference (AC-36c)', () => {
    const refusing = set.find(
      (file) =>
        file.kind === 'tax' &&
        typeof file.decoded === 'object' &&
        file.decoded !== null &&
        'refusal' in file.decoded &&
        (file.decoded as Json)['refusal'] !== 'expired',
    );

    expect(refusing).toBeDefined();

    const control = structuredClone(refusing!.decoded) as Json;

    expect(compareCase(control)).toEqual([]);

    control['refusal'] = 'expired';

    expect(compareCase(control)).not.toEqual([]);
  });

  it('an unrecognised rule value surfaces as UnsupportedRuleError, never as a refusal (AC-38)', () => {
    const control = cloneOf('tax-exclusive-single-15');
    const members = (control['input'] as { members: { type: string }[] }).members;

    members[0]!.type = 'surcharge';

    expect(() => replay(control)).toThrow(UnsupportedRuleError);
    expect(() => compareCase(control)).toThrow(UnsupportedRuleError);
  });

  it('expected() reads the refusal or the expectation', () => {
    expect(expected({ refusal: 'expired' })).toEqual({ refusal: 'expired' });
    expect(expected({ expect: { a: '1' } })).toEqual({ expect: { a: '1' } });
  });

  it('a file that is not a JSON object fails the lint', () => {
    expect(lintFixture('/x/tax/a.json', undefined)).toEqual(['does not parse as a JSON object']);
  });

  it('a kind that does not match its directory, and a name that does not match its file, fail', () => {
    const control = cloneOf('tax-exclusive-single-15');
    const wrongDirectory = lintFixture('/x/price/tax-exclusive-single-15.json', control);
    const wrongName = lintFixture('/x/tax/other-name.json', control);

    expect(wrongDirectory.join(' ')).toContain('does not match its directory');
    expect(wrongName.join(' ')).toContain('name does not match the file name');
  });

  it('both or neither of expect and refusal, and an extra key, fail the lint', () => {
    const path = fixtureNamed('tax-exclusive-single-15').path;
    const both = cloneOf('tax-exclusive-single-15');
    const neither = cloneOf('tax-exclusive-single-15');
    const extra = cloneOf('tax-exclusive-single-15');

    both['refusal'] = 'expired';
    delete neither['expect'];
    extra['note'] = 'x';

    expect(lintFixture(path, both).join(' ')).toContain('exactly one of');
    expect(lintFixture(path, neither).join(' ')).toContain('exactly one of');
    expect(lintFixture(path, extra).join(' ')).toContain('unexpected top-level key [note]');
  });

  it('a refusal that is not a PricingRefusal value fails the lint (TS-only)', () => {
    const control = cloneOf('tax-no-effective-rate');

    control['refusal'] = 'made_up';

    expect(lintFixture(fixtureNamed('tax-no-effective-rate').path, control).join(' ')).toContain(
      'not a PricingRefusal value',
    );
  });
});

describe('anti-vacuity controls (AC-34)', () => {
  it('passes for the real set', () => {
    expect(() => assertFixtureSet(set)).not.toThrow();
  });

  it('throws on a set one file below the floor', () => {
    expect(() => assertFixtureSet(set.slice(0, MINIMUM_FIXTURES - 1))).toThrow(/floor/);
  });

  it('throws on a set with the bill files removed', () => {
    expect(() => assertFixtureSet(set.filter((file) => file.kind !== 'bill'))).toThrow(
      /kind \[bill\]/,
    );
  });

  it('throws on a set with no refusal fixture for tax', () => {
    const withoutTaxRefusals = set.filter(
      (file) =>
        !(
          file.kind === 'tax' &&
          typeof file.decoded === 'object' &&
          file.decoded !== null &&
          'refusal' in file.decoded
        ),
    );

    expect(() => assertFixtureSet(withoutTaxRefusals)).toThrow(
      /kind \[tax\] has no fixture that expects a refusal/,
    );
  });

  it('throws on a set with no result fixture for a kind', () => {
    const withoutBillResults = set.filter(
      (file) =>
        !(
          file.kind === 'bill' &&
          typeof file.decoded === 'object' &&
          file.decoded !== null &&
          'expect' in file.decoded
        ),
    );

    expect(() => assertFixtureSet(withoutBillResults)).toThrow(
      /kind \[bill\] has no fixture that expects a result/,
    );
  });

  it('throws on a duplicated name', () => {
    expect(() => assertFixtureSet([...set, set[0]!])).toThrow(/not unique/);
  });

  it('throws when the directory is absent', () => {
    expect(() => loadFixtureSet('/nonexistent-root')).toThrow(/directory not found/);
  });
});
