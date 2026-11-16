import { ApiError } from '../../api/envelope';
import { asMoney } from '../decimal-string';
import { SALES_CALLS, buildPath, callPath, matchCall } from './calls';
import { AddTenderRequest } from './sale';
import {
  REFUSAL_FACT,
  REFUSAL_STATUS,
  SALES_REFUSAL_CODES,
  refusalFact,
  refusalOf,
} from './refusals';

/** AC-3, written out a second time on purpose so a drift in either copy fails this spec. */
const AC3_CODES = [
  'no_open_shift',
  'not_found',
  'unit_unresolvable',
  'unresolvable_price',
  'price_override_not_allowed',
  'discount_with_override',
  'approval_required',
  'approval_not_pending',
  'approval_already_used',
  'approval_not_approved',
  'approval_mismatch',
  'self_approval_not_allowed',
  'pin_invalid',
  'not_authorised',
  'sale_not_editable',
  'customer_required',
  'unsupported_method_type',
  'balance_due',
  'exceeds_balance',
  'credit_refused',
  'usage_limit_reached',
  'numbering_refused',
  'shift_closed',
  'shift_already_open',
  'shift_not_open',
  'shift_has_open_sales',
  'invalid_transition',
  'over_refund',
  'fully_refunded',
  'inherited_unit_mismatch',
  'not_refundable',
  'product_not_sellable',
  'replay_conflict',
  'in_use',
  'out_of_range',
  'validation',
  'unknown_product',
  'no_effective_rate',
  'not_applicable',
  'inactive',
  'unknown_discount',
  'discount_not_allowed',
];

function refusal(code: string, field: string | null = null, detail = ''): ApiError {
  return new ApiError(403, 'Refused.', [{ code, field, detail }], 'req-1');
}

describe('SALES_REFUSAL_CODES', () => {
  it('is exactly the 42 codes of AC-3, in any order, with no extras', () => {
    expect(AC3_CODES.length).toBe(42);
    expect([...SALES_REFUSAL_CODES].sort()).toEqual([...AC3_CODES].sort());
  });

  it('has no duplicate', () => {
    expect(new Set(SALES_REFUSAL_CODES).size).toBe(SALES_REFUSAL_CODES.length);
  });

  it('has a status and a fact row for every code, and nothing else', () => {
    expect(Object.keys(REFUSAL_STATUS).sort()).toEqual([...AC3_CODES].sort());
    expect(Object.keys(REFUSAL_FACT).sort()).toEqual([...AC3_CODES].sort());
  });

  it('uses 422 for validation, 404 for not_found and 403 for every other refusal (P-5)', () => {
    for (const code of SALES_REFUSAL_CODES) {
      const expected = code === 'validation' ? 422 : code === 'not_found' ? 404 : 403;
      expect(REFUSAL_STATUS[code]).toBe(expected);
    }
  });
});

describe('refusalOf', () => {
  it('strips the module prefix', () => {
    expect(refusalOf(refusal('sales.over_refund'))).toBe('over_refund');
  });

  it('maps kernel.validation_failed to validation', () => {
    expect(refusalOf(refusal('kernel.validation_failed'))).toBe('validation');
  });

  it('reads not_found from the kernel and from the catalogue', () => {
    expect(refusalOf(refusal('kernel.not_found'))).toBe('not_found');
    expect(refusalOf(refusal('catalog.not_found'))).toBe('not_found');
  });

  it('reads a bare code too', () => {
    expect(refusalOf(refusal('no_open_shift'))).toBe('no_open_shift');
  });

  it('is null for a code that is not in the union', () => {
    expect(refusalOf(refusal('sales.something_new'))).toBeNull();
  });

  it('is null for an error with no errors, and for a network error', () => {
    expect(refusalOf(new ApiError(500, 'Boom.'))).toBeNull();
    expect(refusalOf(new ApiError(0, 'Cannot reach the server.'))).toBeNull();
  });

  it('takes the first error only', () => {
    const err = new ApiError(403, 'x', [
      { code: 'sales.something_new', field: null, detail: '' },
      { code: 'sales.over_refund', field: null, detail: '' },
    ]);
    expect(refusalOf(err)).toBeNull();
  });
});

describe('refusalFact', () => {
  it('reads the fact named in the table (P-4)', () => {
    const err = refusal('sales.balance_due', 'balance_due', '1250.0000');
    expect(refusalFact(err, 'balance_due')).toEqual({ name: 'balance_due', value: '1250.0000' });
  });

  it('tries each fact of over_refund', () => {
    expect(
      refusalFact(refusal('sales.over_refund', 'refundable_amount', '10.0000'), 'over_refund'),
    ).toEqual({ name: 'refundable_amount', value: '10.0000' });
    expect(
      refusalFact(refusal('sales.over_refund', 'refundable_quantity', '2.0000'), 'over_refund'),
    ).toEqual({ name: 'refundable_quantity', value: '2.0000' });
  });

  it('is null when the refusal carries no fact', () => {
    expect(refusalFact(refusal('sales.balance_due'), 'balance_due')).toBeNull();
    expect(refusalFact(refusal('sales.no_open_shift'), 'no_open_shift')).toBeNull();
  });

  it('reads a validation refusal as the input name and the server message', () => {
    const err = refusal('kernel.validation_failed', 'opening_cash', 'Must be a number.');
    expect(refusalFact(err, 'validation')).toEqual({
      name: 'opening_cash',
      value: 'Must be a number.',
    });
  });
});

describe('the call table', () => {
  it('has one entry per id, and no two calls with the same method and pattern', () => {
    const ids = SALES_CALLS.map((c) => c.id);
    expect(new Set(ids).size).toBe(ids.length);
    const routes = SALES_CALLS.map((c) => `${c.method} ${c.pattern}`);
    expect(new Set(routes).size).toBe(routes.length);
  });

  it('builds a path and matches it back to the same call', () => {
    expect(callPath('sales.remove-line', { id: 'S1', lineId: 'L1' })).toBe('sales/S1/lines/L1');
    const matched = matchCall('DELETE', 'sales/S1/lines/L1');
    expect(matched?.call.id).toBe('sales.remove-line');
    expect(matched?.params).toEqual({ id: 'S1', lineId: 'L1' });
  });

  it('separates calls by method and by shape', () => {
    expect(matchCall('GET', 'sales')?.call.id).toBe('sales.list');
    expect(matchCall('POST', 'sales')?.call.id).toBe('sales.create');
    expect(matchCall('GET', 'sales/S1')?.call.id).toBe('sales.get');
    expect(matchCall('GET', 'shifts/current')?.call.id).toBe('shifts.current');
    expect(matchCall('GET', 'unknown/path')).toBeNull();
    expect(matchCall('PATCH', 'sales/S1')).toBeNull();
  });

  it('percent-encodes a parameter and decodes it again', () => {
    expect(buildPath('sales/{id}', { id: 'a/b' })).toBe('sales/a%2Fb');
    expect(matchCall('GET', 'sales/a%2Fb')?.params['id']).toBe('a/b');
  });

  it('refuses a missing parameter', () => {
    expect(() => buildPath('sales/{id}')).toThrow();
  });
});

describe('type-level rules (checked by the compiler)', () => {
  it('rejects an amount or a change_given key on a tender request (P-1)', () => {
    const ok: AddTenderRequest = {
      id: '01JMOCKT000000000000000001',
      payment_method_id: '01JMOCKM000000000000000001',
      tendered: asMoney('2000.0000'),
    };
    const withAmount: AddTenderRequest = {
      ...ok,
      // @ts-expect-error the till cannot send an amount: the type has no such key
      amount: asMoney('1250.0000'),
    };
    const withChange: AddTenderRequest = {
      ...ok,
      // @ts-expect-error the till cannot send change_given either
      change_given: asMoney('750.0000'),
    };
    expect(ok.tendered).toBe('2000.0000');
    expect(Object.keys(withAmount)).toContain('amount');
    expect(Object.keys(withChange)).toContain('change_given');
  });

  it('rejects arithmetic on money', () => {
    const price = asMoney('10.0000');
    // @ts-expect-error money is a string, not a number
    const doubled = price * 2;
    // @ts-expect-error a plain string cannot stand in for a money value
    const notMoney: typeof price = '10.0000';
    expect(typeof doubled).toBe('number');
    expect(notMoney).toBe('10.0000');
  });
});
