/*
 * The tax of one amount under one tax group: a line-for-line twin of PHP's `RateResolver` and
 * `TaxCalculator`, plus the constructors of `TaxMember` and `RateRow` (the build phase).
 *
 * The fixed order, as in PHP: filter (deleted, inactive, applies_to), order by sequence then
 * byte-wise tax id, resolve each member's rate, then compute forward (exclusive) or back out the
 * net exactly (inclusive) with the rounding residue on the last non-zero percentage member.
 */

import { compareBytes, compareInt } from './byte-order';
import {
  Dec,
  ONE,
  ZERO,
  add,
  cmp,
  divideToScale,
  isNegative,
  isZero,
  movePointLeft,
  mul,
  percentOf,
  roundStep,
  sub,
  text,
} from './decimal';
import { PricingInputError, PricingRefused } from './refusal';
import { TAX_APPLIES, TAX_CONTEXTS, TAX_TYPES, assertKnownRules } from './rules';
import {
  bool,
  date as checkDate,
  enumOf,
  int,
  list,
  moneyDec,
  obj,
  rate as checkRate,
  rateDec,
  raw,
  str,
  strOrNull,
  ulid,
} from './shape';
import type { Obj } from './shape';
import type { TaxApplies, TaxBreakdown, TaxContext, TaxInput, TaxLine, TaxType } from './types';

export interface RateRow {
  readonly rate: string;
  readonly effectiveFrom: string;
  readonly effectiveTo: string | null;
}

export interface TaxMember {
  readonly taxId: string;
  readonly code: string;
  readonly taxCode: string | null;
  readonly type: TaxType;
  readonly isCompound: boolean;
  readonly appliesTo: TaxApplies;
  readonly isActive: boolean;
  readonly isDeleted: boolean;
  readonly sequence: bigint;
  readonly rates: readonly RateRow[];
}

// ------------------------------------------------------------ build phase

/** Twin of the `RateRow` constructor, after its arguments are read. */
function buildRateRow(node: Obj): RateRow {
  const rate = raw(node, 'rate');
  const effectiveFrom = str(node, 'effective_from');
  const effectiveTo = strOrNull(node, 'effective_to');

  const row = {
    rate: checkRate(rate, 'rate'),
    effectiveFrom: checkDate(effectiveFrom, 'effectiveFrom'),
    effectiveTo: effectiveTo === null ? null : checkDate(effectiveTo, 'effectiveTo'),
  };

  if (row.effectiveTo !== null && row.effectiveTo < row.effectiveFrom) {
    throw new PricingInputError('[effectiveTo] must not be before [effectiveFrom].');
  }

  return row;
}

/** Twin of `FixtureReplay::members` and the `TaxMember` constructor, member by member. */
export function buildMembers(nodes: unknown[]): TaxMember[] {
  return nodes.map((item): TaxMember => {
    const node = obj(item, 'member');

    const taxId = str(node, 'tax_id');
    const code = str(node, 'code');
    const taxCode = strOrNull(node, 'tax_code');
    const type = enumOf(node, 'type', TAX_TYPES);
    const isCompound = bool(node, 'is_compound');
    const appliesTo = enumOf(node, 'applies_to', TAX_APPLIES);
    const isActive = bool(node, 'is_active');
    const isDeleted = bool(node, 'is_deleted');
    const sequence = int(node, 'sequence');
    const rates = list(node, 'rates').map((rawRate) => buildRateRow(obj(rawRate, 'rate row')));

    if (taxId === '' || code === '') {
      throw new PricingInputError('[taxId] and [code] must not be blank.');
    }

    if (type === 'fixed' && isCompound) {
      throw new PricingInputError('[isCompound] a fixed tax cannot compound.');
    }

    if (sequence < 0n || sequence > 255n) {
      throw new PricingInputError('[sequence] must be 0 to 255.');
    }

    return {
      taxId,
      code,
      taxCode,
      type,
      isCompound,
      appliesTo,
      isActive,
      isDeleted,
      sequence,
      rates,
    };
  });
}

// ------------------------------------------------------------ rate resolution

/**
 * Twin of `RateResolver::resolve`. The winner is the row with the latest `effectiveFrom` on or
 * before the date whose `effectiveTo` is null or after the date. Two rows sharing an
 * `effectiveFrom` are refused `invalid_amount` before any winner is chosen, whether or not either
 * qualifies. No qualifying row is `no_effective_rate`, never a silent zero.
 */
export function resolveRate(rows: readonly RateRow[], onDate: string, label = 'tax'): RateRow {
  checkDate(onDate, 'date');

  const starts = new Set<string>();

  for (const row of rows) {
    if (starts.has(row.effectiveFrom)) {
      throw new PricingRefused(
        'invalid_amount',
        'Two rates of [' + label + '] share the effective_from ' + row.effectiveFrom + '.',
      );
    }

    starts.add(row.effectiveFrom);
  }

  let winner: RateRow | null = null;

  for (const row of rows) {
    if (row.effectiveFrom > onDate) {
      continue;
    }

    if (row.effectiveTo !== null && row.effectiveTo <= onDate) {
      continue;
    }

    if (winner === null || row.effectiveFrom > winner.effectiveFrom) {
      winner = row;
    }
  }

  if (winner === null) {
    throw new PricingRefused(
      'no_effective_rate',
      'No rate of [' + label + '] is effective on ' + onDate + '.',
    );
  }

  return winner;
}

// ------------------------------------------------------------ compute phase

interface Applied {
  readonly member: TaxMember;
  readonly rate: Dec;
}

interface Computed extends Applied {
  readonly base: Dec;
  amount: Dec;
}

function covers(appliesTo: TaxApplies, context: TaxContext): boolean {
  return appliesTo === 'both' || appliesTo === context;
}

function applicable(members: readonly TaxMember[], onDate: string, context: TaxContext): Applied[] {
  const surviving = members.filter(
    (member) => !member.isDeleted && member.isActive && covers(member.appliesTo, context),
  );

  // Sequence is unique within a group; the tax id is a deterministic tiebreak. The raw (not
  // upper-cased) id is compared, as `strcmp($a->taxId, $b->taxId)` does.
  surviving.sort((a, b) => compareInt(a.sequence, b.sequence) || compareBytes(a.taxId, b.taxId));

  return surviving.map((member) => {
    const row = resolveRate(member.rates, onDate, member.code);

    return { member, rate: rateDec(row.rate, 'rate') };
  });
}

function forward(applied: readonly Applied[], net: Dec, quantity: Dec): Computed[] {
  const computed: Computed[] = [];
  let running = ZERO;

  for (const entry of applied) {
    let base: Dec;
    let amount: Dec;

    if (entry.member.type === 'fixed') {
      base = net;
      amount = roundStep(mul(entry.rate, quantity));
    } else {
      base = entry.member.isCompound ? add(net, running) : net;
      amount = percentOf(base, entry.rate);
    }

    running = add(running, amount);
    computed.push({ member: entry.member, rate: entry.rate, base, amount });
  }

  return computed;
}

/** The tax-exclusive net of an inclusive gross: the exact linear rule, rounded once. */
function inclusiveNet(applied: readonly Applied[], gross: Dec, quantity: Dec): Dec {
  let fixedTotal = ZERO;
  let fixedBefore = ZERO;
  let slope = ZERO;
  let constant = ZERO;

  for (const entry of applied) {
    if (entry.member.type === 'fixed') {
      const amount = roundStep(mul(entry.rate, quantity));

      fixedTotal = add(fixedTotal, amount);
      fixedBefore = add(fixedBefore, amount);

      continue;
    }

    // A percentage as an exact fraction: at most six decimals.
    const fraction = movePointLeft(entry.rate, 2);

    if (entry.member.isCompound) {
      slope = add(slope, mul(fraction, add(ONE, slope)));
      constant = add(constant, mul(fraction, add(constant, fixedBefore)));
    } else {
      slope = add(slope, fraction);
    }
  }

  if (cmp(fixedTotal, gross) > 0) {
    throw new PricingRefused(
      'inclusive_amount_too_small',
      'The fixed taxes exceed the inclusive amount.',
    );
  }

  const remaining = sub(sub(gross, fixedTotal), constant);

  if (isNegative(remaining)) {
    throw new PricingRefused(
      'inclusive_amount_too_small',
      'The inclusive amount cannot cover the fixed taxes and the tax charged on them.',
    );
  }

  return roundStep(divideToScale(remaining, add(ONE, slope), 4));
}

function sum(computed: readonly Computed[]): Dec {
  let total = ZERO;

  for (const entry of computed) {
    total = add(total, entry.amount);
  }

  return total;
}

/** The last percentage member whose resolved rate is not zero, or null. */
function lastPercentage(computed: readonly Computed[]): number | null {
  let last: number | null = null;

  for (let index = 0; index < computed.length; index++) {
    const entry = computed[index];

    if (entry !== undefined && entry.member.type === 'percentage' && !isZero(entry.rate)) {
      last = index;
    }
  }

  return last;
}

function breakdown(inclusive: boolean, net: Dec, computed: readonly Computed[]): TaxBreakdown {
  const lines: TaxLine[] = [];

  for (const entry of computed) {
    const member = entry.member;
    const rate = text(entry.rate);
    const base = text(entry.base);
    const amount = text(entry.amount);

    lines.push({
      tax_id: ulid(member.taxId, 'taxId'),
      code: member.code,
      tax_code: member.taxCode,
      type: member.type,
      is_compound: member.isCompound,
      rate,
      base,
      amount,
    });
  }

  const netText = text(net);

  return { is_inclusive: inclusive, net: netText, lines, total: text(sum(computed)) };
}

/**
 * Twin of `TaxCalculator::compute`. The line calculation calls it too, with already-built members.
 */
export function computeTax(
  members: readonly TaxMember[],
  amount: unknown,
  baseQuantity: unknown,
  onDate: unknown,
  context: TaxContext,
  inclusive: boolean,
): TaxBreakdown {
  const gross = moneyDec(amount, 'amount');
  const quantity = moneyDec(baseQuantity, 'baseQuantity');
  const dateText = checkDate(onDate, 'date');

  const applied = applicable(members, dateText, context);

  if (applied.length === 0) {
    return { is_inclusive: inclusive, net: text(gross), lines: [], total: '0.0000' };
  }

  if (!inclusive) {
    return breakdown(false, gross, forward(applied, gross, quantity));
  }

  const net = inclusiveNet(applied, gross, quantity);
  const computed = forward(applied, net, quantity);
  const residue = sub(sub(gross, net), sum(computed));

  if (!isZero(residue)) {
    const last = lastPercentage(computed);

    // A non-zero residue always has a member to land on: with only fixed members, or only
    // zero-rate percentage members, the slope and the constant are zero, so the residue is zero.
    if (last !== null) {
      const target = computed[last];

      if (target !== undefined) {
        target.amount = add(target.amount, residue);
      }
    }
  }

  return breakdown(true, net, computed);
}

/** The tax of one amount under one tax group. Throws PricingRefused, PricingInputError or UnsupportedRuleError. */
export function calculateTax(input: TaxInput): TaxBreakdown {
  assertKnownRules('tax', input);

  const root = obj(input, 'input');
  const members = buildMembers(list(root, 'members'));
  const amount = raw(root, 'amount');
  const baseQuantity = raw(root, 'base_quantity');
  const onDate = str(root, 'date');
  const context = enumOf(root, 'context', TAX_CONTEXTS);
  const inclusive = bool(root, 'is_inclusive');

  return computeTax(members, amount, baseQuantity, onDate, context, inclusive);
}
