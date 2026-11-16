/*
 * The six-step price ladder: a line-for-line twin of PHP's `PriceLadder`, plus the constructors of
 * `LadderInput`, `LadderList`, `LadderAssignment` and `LadderItem` (the build phase).
 *
 * Steps, the first that prices the line wins: 1 customer lists, 2 customer-group lists,
 * 3 promotion lists, 4 branch lists, 5 the branch selling price, 6 the product selling price. Steps
 * 5 and 6 derive by the unit factor, as a list item with no unit does.
 */

import { cmpDesc, compareBytes } from './byte-order';
import { Dec, HUNDRED, cmp, mul, percentOf, roundStep, sub, text } from './decimal';
import { PricingInputError, PricingRefused } from './refusal';
import { LIST_TYPES, SCOPE_TYPES, assertKnownRules } from './rules';
import {
  bool,
  currency as checkCurrency,
  enumOf,
  factor,
  factorDec,
  instant as checkInstant,
  int,
  list,
  money,
  moneyDec,
  obj,
  rate as checkRate,
  rateDec,
  raw,
  rawOrNull,
  str,
  strOrNull,
  ulid,
} from './shape';
import type { Obj } from './shape';
import type { ListType, PriceBasis, PriceInput, PriceOutcome, ScopeType } from './types';

interface LadderAssignment {
  readonly scopeType: ScopeType;
  readonly scopeId: string;
  readonly priority: bigint;
}

interface LadderItem {
  readonly unitId: string | null;
  readonly minQty: string;
  readonly price: string;
  readonly discountPercent: string | null;
}

interface LadderList {
  readonly listId: string;
  readonly code: string;
  readonly type: ListType;
  readonly currency: string;
  readonly priority: bigint;
  readonly startsAt: string | null;
  readonly endsAt: string | null;
  readonly isActive: boolean;
  readonly isDeleted: boolean;
  readonly assignments: readonly LadderAssignment[];
  readonly items: readonly LadderItem[];
}

export interface LadderInput {
  readonly unitId: string;
  readonly unitFactor: string;
  readonly baseQuantity: string;
  readonly at: string;
  readonly currency: string;
  readonly customerId: string | null;
  readonly customerGroupId: string | null;
  readonly branchId: string | null;
  readonly lists: readonly LadderList[];
  readonly branchSellingPrice: string | null;
  readonly sellingPrice: string | null;
}

type Step = 1 | 2 | 3 | 4 | 5 | 6;

// ------------------------------------------------------------ build phase

function buildAssignment(node: Obj): LadderAssignment {
  const scopeType = enumOf(node, 'scope_type', SCOPE_TYPES);
  const scopeId = str(node, 'scope_id');
  const priority = int(node, 'priority');

  if (scopeId === '') {
    throw new PricingInputError('[scopeId] must not be blank.');
  }

  return { scopeType, scopeId, priority };
}

function buildItem(node: Obj): LadderItem {
  const unitId = strOrNull(node, 'unit_id');
  const minQty = raw(node, 'min_qty');
  const price = raw(node, 'price');
  const discountPercent = rawOrNull(node, 'discount_percent');

  const item = {
    unitId,
    minQty: money(minQty, 'minQty'),
    price: money(price, 'price'),
    discountPercent:
      discountPercent === null ? null : checkRate(discountPercent, 'discountPercent'),
  };

  if (item.discountPercent !== null && cmp(rateDec(item.discountPercent, 'x'), HUNDRED) > 0) {
    throw new PricingInputError('[discountPercent] must not exceed 100.');
  }

  return item;
}

function buildList(node: Obj): LadderList {
  const listId = str(node, 'list_id');
  const code = str(node, 'code');
  const type = enumOf(node, 'type', LIST_TYPES);
  const listCurrency = str(node, 'currency');
  const priority = int(node, 'priority');
  const startsAt = strOrNull(node, 'starts_at');
  const endsAt = strOrNull(node, 'ends_at');
  const isActive = bool(node, 'is_active');
  const isDeleted = bool(node, 'is_deleted');
  const assignments = list(node, 'assignments').map((item) =>
    buildAssignment(obj(item, 'assignment')),
  );
  const items = list(node, 'items').map((item) => buildItem(obj(item, 'item')));

  return {
    listId,
    code,
    type,
    currency: checkCurrency(listCurrency, 'currency'),
    priority,
    startsAt: startsAt === null ? null : checkInstant(startsAt, 'startsAt'),
    endsAt: endsAt === null ? null : checkInstant(endsAt, 'endsAt'),
    isActive,
    isDeleted,
    assignments,
    items,
  };
}

/** Twin of `FixtureReplay::ladder` and the `LadderInput` constructor. */
export function buildLadder(node: Obj): LadderInput {
  const unitId = str(node, 'unit_id');
  const unitFactor = raw(node, 'unit_factor');
  const baseQuantity = raw(node, 'base_quantity');
  const at = str(node, 'at');
  const currency = str(node, 'currency');
  const customerId = strOrNull(node, 'customer_id');
  const customerGroupId = strOrNull(node, 'customer_group_id');
  const branchId = strOrNull(node, 'branch_id');
  const lists = list(node, 'lists').map((item) => buildList(obj(item, 'price list')));
  const branchSellingPrice = rawOrNull(node, 'branch_selling_price');
  const sellingPrice = rawOrNull(node, 'selling_price');

  const checkedFactor = factor(unitFactor, 'unitFactor');
  const checkedQuantity = money(baseQuantity, 'baseQuantity');
  const checkedAt = checkInstant(at, 'at');
  const checkedCurrency = checkCurrency(currency, 'currency');

  return {
    unitId,
    unitFactor: checkedFactor,
    baseQuantity: checkedQuantity,
    at: checkedAt,
    currency: checkedCurrency,
    customerId,
    customerGroupId,
    branchId,
    lists,
    branchSellingPrice:
      branchSellingPrice === null ? null : money(branchSellingPrice, 'branchSellingPrice'),
    sellingPrice: sellingPrice === null ? null : money(sellingPrice, 'sellingPrice'),
  };
}

// ------------------------------------------------------------ compute phase

/** The one place a stock-unit price is multiplied by a unit factor. */
function derive(stockUnitPrice: string, input: LadderInput): Dec {
  return roundStep(
    mul(moneyDec(stockUnitPrice, 'price'), factorDec(input.unitFactor, 'unitFactor')),
  );
}

function qualifies(entry: LadderList, input: LadderInput): boolean {
  if (!entry.isActive || entry.isDeleted || entry.currency !== input.currency) {
    return false;
  }

  // Instants are fixed-width UTC strings, so string order is time order.
  if (entry.startsAt !== null && input.at < entry.startsAt) {
    return false;
  }

  return entry.endsAt === null || input.at <= entry.endsAt;
}

/** The non-promotion lists assigned to one scope, best first (steps 1, 2 and 4). */
function assigned(
  lists: readonly LadderList[],
  scope: ScopeType,
  scopeId: string | null,
): LadderList[] {
  if (scopeId === null) {
    return [];
  }

  const matches: { list: LadderList; assignmentPriority: bigint }[] = [];

  for (const entry of lists) {
    if (entry.type === 'promotion') {
      continue;
    }

    for (const assignment of entry.assignments) {
      if (assignment.scopeType === scope && assignment.scopeId === scopeId) {
        matches.push({ list: entry, assignmentPriority: assignment.priority });

        break;
      }
    }
  }

  // Assignment priority descending, list priority descending, code ascending bytewise.
  matches.sort(
    (a, b) =>
      cmpDesc(a.assignmentPriority, b.assignmentPriority) ||
      cmpDesc(a.list.priority, b.list.priority) ||
      compareBytes(a.list.code, b.list.code),
  );

  return matches.map((match) => match.list);
}

function lineScopeId(scope: ScopeType, input: LadderInput): string | null {
  switch (scope) {
    case 'customer':
      return input.customerId;
    case 'customer_group':
      return input.customerGroupId;
    case 'branch':
      return input.branchId;
  }
}

/** The promotion lists that apply to this line, best first (step 3). */
function promotions(lists: readonly LadderList[], input: LadderInput): LadderList[] {
  const applicable = lists.filter((entry) => {
    if (entry.type !== 'promotion') {
      return false;
    }

    if (entry.assignments.length === 0) {
      return true;
    }

    return entry.assignments.some((assignment) => {
      const line = lineScopeId(assignment.scopeType, input);

      return line !== null && line === assignment.scopeId;
    });
  });

  applicable.sort((a, b) => cmpDesc(a.priority, b.priority) || compareBytes(a.code, b.code));

  return applicable;
}

/**
 * Of the items for one unit (null: no unit) whose `minQty` is at or below the base quantity, the
 * one with the greatest `minQty`. Two items at one `minQty` for one unit are refused whether or not
 * either qualifies, so the outcome never depends on row order. Other units are not inspected.
 */
function greatestQualifying(
  entry: LadderList,
  unitId: string | null,
  baseQuantity: Dec,
): LadderItem | null {
  let best: LadderItem | null = null;
  let bestMin: Dec | null = null;
  const seen: Dec[] = [];

  for (const item of entry.items) {
    if (item.unitId !== unitId) {
      continue;
    }

    const min = moneyDec(item.minQty, 'minQty');

    for (const earlier of seen) {
      if (cmp(min, earlier) === 0) {
        throw new PricingRefused(
          'invalid_amount',
          'Price list [' + entry.code + '] holds two items at one minQty for one unit.',
        );
      }
    }

    seen.push(min);

    if (cmp(min, baseQuantity) > 0) {
      continue;
    }

    if (bestMin === null || cmp(min, bestMin) > 0) {
      best = item;
      bestMin = min;
    }
  }

  return best;
}

interface Hit {
  readonly price: Dec;
  readonly discountPercent: string | null;
  readonly basis: PriceBasis;
}

/** What one list says about the line, or null when it prices nothing. */
function fromList(entry: LadderList, input: LadderInput): Hit | null {
  const baseQuantity = moneyDec(input.baseQuantity, 'baseQuantity');

  const unitMatched = greatestQualifying(entry, input.unitId, baseQuantity);

  if (unitMatched !== null) {
    return {
      price: moneyDec(unitMatched.price, 'price'),
      discountPercent: unitMatched.discountPercent,
      basis: 'unit_matched',
    };
  }

  const stock = greatestQualifying(entry, null, baseQuantity);

  if (stock !== null) {
    return {
      price: derive(stock.price, input),
      discountPercent: stock.discountPercent,
      basis: 'derived',
    };
  }

  return null;
}

function outcome(
  listPrice: Dec,
  discountPercent: string | null,
  source: PriceOutcome['source'],
): PriceOutcome {
  const unitPrice =
    discountPercent === null
      ? listPrice
      : percentOf(listPrice, sub(HUNDRED, rateDec(discountPercent, 'discountPercent')));

  return {
    list_price: text(listPrice),
    discount_percent: discountPercent,
    unit_price: text(unitPrice),
    source,
  };
}

/** Twin of `PriceLadder::resolve`. The line calculation calls it with an already-built input. */
export function resolveLadder(input: LadderInput): PriceOutcome {
  const lists = input.lists.filter((entry) => qualifies(entry, input));

  const steps: [Step, LadderList[]][] = [
    [1, assigned(lists, 'customer', input.customerId)],
    [2, assigned(lists, 'customer_group', input.customerGroupId)],
    [3, promotions(lists, input)],
    [4, assigned(lists, 'branch', input.branchId)],
  ];

  for (const [step, ordered] of steps) {
    for (const entry of ordered) {
      const hit = fromList(entry, input);

      if (hit !== null) {
        // The source is built (and its id checked) before the outcome is computed, as in PHP.
        const source = { step, price_list_id: ulid(entry.listId, 'priceListId'), basis: hit.basis };

        return outcome(hit.price, hit.discountPercent, source);
      }
    }
  }

  if (input.branchSellingPrice !== null) {
    const price = derive(input.branchSellingPrice, input);

    return outcome(price, null, { step: 5, price_list_id: null, basis: 'product' });
  }

  if (input.sellingPrice !== null) {
    const price = derive(input.sellingPrice, input);

    return outcome(price, null, { step: 6, price_list_id: null, basis: 'product' });
  }

  throw new PricingRefused(
    'no_price_resolved',
    'No price list, branch price or product price prices this line.',
  );
}

/** The unit price of one line from the six-step ladder. */
export function resolvePrice(input: PriceInput): PriceOutcome {
  assertKnownRules('price', input);

  return resolveLadder(buildLadder(obj(input, 'input')));
}
