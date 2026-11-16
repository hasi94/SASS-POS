/*
 * Test-only: the fixture lint, a line-for-line twin of PHP's `FixtureReplay::lint`, plus the
 * anti-vacuity checks on the whole set (AC-34, AC-35). The TS gate must not trust the PHP gate to
 * have run on the same files.
 */

import { PRICING_REFUSALS } from '../refusal';
import type { FixtureFile } from './fixture-files';

export const FIXTURE_KINDS = ['tax', 'price', 'discount', 'line', 'bill'] as const;

export type FixtureKind = (typeof FIXTURE_KINDS)[number];

/** The same floor as the PHP anti-vacuity check (D-15): never a larger hard-coded count. */
export const MINIMUM_FIXTURES = 30;

const REQUIRED_KEYS = ['name', 'kind', 'discriminates', 'input'];

function has(node: object, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(node, key);
}

function walk(node: unknown, at: string, problems: string[]): void {
  if (typeof node === 'number') {
    problems.push(at + ' is a JSON number; decimals and integers must be strings');

    return;
  }

  if (typeof node === 'string') {
    // A decimal-looking string must carry its full stored scale.
    if (/^\d+\.\d+$/.test(node) && !/^\d+\.(\d{4}|\d{6})$/.test(node)) {
      problems.push(
        at + ' is a decimal [' + node + '] without its full scale (4, or 6 for a factor)',
      );
    }

    return;
  }

  if (Array.isArray(node)) {
    node.forEach((child: unknown, index) => walk(child, at + '.' + String(index), problems));

    return;
  }

  if (typeof node === 'object' && node !== null) {
    for (const [key, child] of Object.entries(node)) {
      walk(child, at + '.' + key, problems);
    }
  }
}

/** Every reason this file is not a well-formed fixture. Empty means well-formed. */
export function lintFixture(path: string, decoded: unknown): string[] {
  if (typeof decoded !== 'object' || decoded === null || Array.isArray(decoded)) {
    return ['does not parse as a JSON object'];
  }

  const problems: string[] = [];
  const node = decoded as Record<string, unknown>;

  const hasExpect = has(node, 'expect');
  const hasRefusal = has(node, 'refusal');

  for (const key of REQUIRED_KEYS) {
    if (!has(node, key)) {
      problems.push('missing key [' + key + ']');
    }
  }

  if (hasExpect === hasRefusal) {
    problems.push('must hold exactly one of [expect] and [refusal]');
  }

  for (const key of Object.keys(node)) {
    if (![...REQUIRED_KEYS, 'expect', 'refusal'].includes(key)) {
      problems.push('unexpected top-level key [' + key + ']');
    }
  }

  const parts = path.split('/');
  const directory = parts[parts.length - 2] ?? '';
  const file = parts[parts.length - 1] ?? '';
  const kind = node['kind'];

  if (typeof kind !== 'string' || !(FIXTURE_KINDS as readonly string[]).includes(kind)) {
    problems.push('kind is not one of ' + FIXTURE_KINDS.join(', '));
  } else if (directory !== kind) {
    problems.push('kind [' + kind + '] does not match its directory');
  }

  if (node['name'] !== file.replace(/\.json$/, '')) {
    problems.push('name does not match the file name');
  }

  const refusal = node['refusal'];

  if (hasRefusal && (typeof refusal !== 'string' || !/^[a-z_]+$/.test(refusal))) {
    problems.push('refusal is not a refusal value string');
  }

  // Two TS-only additions.
  if (typeof refusal === 'string' && !(PRICING_REFUSALS as readonly string[]).includes(refusal)) {
    problems.push('refusal [' + refusal + '] is not a PricingRefusal value');
  }

  if (has(node, 'discriminates') && typeof node['discriminates'] !== 'string') {
    problems.push('discriminates is not a string');
  }

  walk(node, '$', problems);

  return problems;
}

/**
 * AC-34: the set must not be vacuous. Throws on the first failure, naming it.
 */
export function assertFixtureSet(files: readonly FixtureFile[]): void {
  if (files.length < MINIMUM_FIXTURES) {
    throw new Error(
      'fixture set holds ' +
        String(files.length) +
        ' files; the floor is ' +
        String(MINIMUM_FIXTURES),
    );
  }

  const names = new Set<string>();

  for (const file of files) {
    if (names.has(file.name)) {
      throw new Error('fixture name is not unique: ' + file.name);
    }

    names.add(file.name);
  }

  for (const kind of FIXTURE_KINDS) {
    const ofKind = files.filter((file) => file.kind === kind);

    if (ofKind.length === 0) {
      throw new Error('fixture set holds no file of kind [' + kind + ']');
    }

    const withKey = (key: string): number =>
      ofKind.filter(
        (file) =>
          typeof file.decoded === 'object' && file.decoded !== null && has(file.decoded, key),
      ).length;

    if (withKey('expect') === 0) {
      throw new Error('kind [' + kind + '] has no fixture that expects a result');
    }

    if (withKey('refusal') === 0) {
      throw new Error('kind [' + kind + '] has no fixture that expects a refusal');
    }
  }
}
