import { PricingRefused } from './refusal';
import { calculateTax, resolveRate } from './tax';
import { failureOf, id, rateRow, taxInput, taxMember } from './testing/builders';
import type { TaxInput } from './types';

const A = 'A'.repeat(26);
const LOWER_A = 'a'.repeat(26);
const B = 'B'.repeat(26);

describe('calculateTax: the happy path and the shape of the answer', () => {
  it('computes an exclusive 15 % tax', () => {
    expect(calculateTax(taxInput())).toEqual({
      is_inclusive: false,
      net: '100.0000',
      lines: [
        {
          tax_id: id('01TAXVAT'),
          code: 'VAT',
          tax_code: null,
          type: 'percentage',
          is_compound: false,
          rate: '15.0000',
          base: '100.0000',
          amount: '15.0000',
        },
      ],
      total: '15.0000',
    });
  });

  it('gives an empty group no lines and a zero total', () => {
    expect(calculateTax(taxInput({ members: [] }))).toEqual({
      is_inclusive: false,
      net: '100.0000',
      lines: [],
      total: '0.0000',
    });
  });

  it('upper-cases a lower-case tax id in the output', () => {
    const result = calculateTax(
      taxInput({ members: [taxMember({ tax_id: 'abcdefghijklmnopqrstuvwxyz' })] }),
    );

    expect(result.lines[0]?.tax_id).toBe('ABCDEFGHIJKLMNOPQRSTUVWXYZ');
  });

  it('refuses an applied member whose id is not a ULID, as a programmer error', () => {
    expect(
      failureOf(() => calculateTax(taxInput({ members: [taxMember({ tax_id: 'short' })] }))),
    ).toBe('input-error');
  });

  it('does not check the id of a member that never applies', () => {
    const skipped = taxMember({ tax_id: 'short', is_deleted: true });

    expect(calculateTax(taxInput({ members: [skipped, taxMember()] })).lines.length).toBe(1);
  });
});

describe('calculateTax: order', () => {
  const first = taxMember({ tax_id: id('01TAXAAA'), code: 'AAA', sequence: '1' });
  const second = taxMember({
    tax_id: id('01TAXBBB'),
    code: 'BBB',
    sequence: '2',
    is_compound: true,
    rates: [rateRow({ rate: '5.0000' })],
  });

  it('gives the same output however the members arrive', () => {
    const forwardOrder = calculateTax(taxInput({ members: [first, second] }));
    const reverseOrder = calculateTax(taxInput({ members: [second, first] }));

    expect(reverseOrder).toEqual(forwardOrder);
    expect(forwardOrder.lines.map((line) => line.code)).toEqual(['AAA', 'BBB']);
    // The compound base is the amount plus the earlier member's tax.
    expect(forwardOrder.lines[1]?.base).toBe('115.0000');
  });

  it('breaks an equal sequence by byte order of the raw tax id', () => {
    const upper = taxMember({ tax_id: B, code: 'UP', sequence: '1' });
    const lower = taxMember({ tax_id: LOWER_A, code: 'LOW', sequence: '1' });

    const result = calculateTax(taxInput({ members: [lower, upper] }));

    // Raw order: 'B' (0x42) before 'a' (0x61). Upper-cased, 'AAA...' would sort first instead.
    expect(result.lines.map((line) => line.code)).toEqual(['UP', 'LOW']);
    expect(result.lines.map((line) => line.tax_id)).toEqual([B, A]);
  });

  it('orders sequences as integers, so 9 comes before 10', () => {
    const nine = taxMember({ tax_id: id('01TAXNINE'), code: 'NINE', sequence: '9' });
    const ten = taxMember({ tax_id: id('01TAXTEN'), code: 'TEN', sequence: '10' });

    expect(calculateTax(taxInput({ members: [ten, nine] })).lines.map((line) => line.code)).toEqual(
      ['NINE', 'TEN'],
    );
  });
});

describe('calculateTax: rate windows and duplicates', () => {
  const future = (from: string) => rateRow({ effective_from: from, rate: '9.0000' });

  it('refuses a duplicate effective_from on a surviving member even when neither row qualifies', () => {
    const member = taxMember({ rates: [future('2030-01-01'), future('2030-01-01')] });

    expect(failureOf(() => calculateTax(taxInput({ members: [member] })))).toBe(
      'refused invalid_amount',
    );
  });

  it('accepts the same duplicate on a deleted member and on an inactive member', () => {
    const duplicated = [future('2030-01-01'), future('2030-01-01')];

    for (const flags of [{ is_deleted: true }, { is_active: false }]) {
      const skipped = taxMember({
        tax_id: id('01TAXSKIP'),
        code: 'SKIP',
        rates: duplicated,
        ...flags,
      });
      const result = calculateTax(taxInput({ members: [skipped, taxMember()] }));

      expect(result.lines.length).toBe(1);
    }
  });

  it('refuses a member with no effective rate, never a zero', () => {
    const member = taxMember({ rates: [future('2030-01-01')] });

    expect(failureOf(() => calculateTax(taxInput({ members: [member] })))).toBe(
      'refused no_effective_rate',
    );
  });

  it('refuses a malformed rate on a deleted member, because a rate row validates when built', () => {
    const member = taxMember({ is_deleted: true, rates: [rateRow({ rate: '1.00001' })] });

    expect(failureOf(() => calculateTax(taxInput({ members: [member] })))).toBe(
      'refused invalid_amount',
    );
  });

  it('refuses a rate row that ends before it starts, as a programmer error', () => {
    const member = taxMember({
      rates: [rateRow({ effective_from: '2025-02-01', effective_to: '2025-01-01' })],
    });

    expect(failureOf(() => calculateTax(taxInput({ members: [member] })))).toBe('input-error');
  });

  it('resolves the latest row on or before the date, ending strictly after it', () => {
    const rows = [
      { rate: '5.0000', effectiveFrom: '2020-01-01', effectiveTo: '2025-01-01' },
      { rate: '7.0000', effectiveFrom: '2025-01-01', effectiveTo: null },
    ];

    expect(resolveRate(rows, '2025-01-01').rate).toBe('7.0000');
    expect(resolveRate(rows, '2024-12-31').rate).toBe('5.0000');
  });
});

describe('calculateTax: unknown rule values and bad input', () => {
  it('throws UnsupportedRuleError for an unknown context before it notices an invalid amount', () => {
    const input = {
      ...taxInput(),
      context: 'transfer',
      amount: 'not a number',
    } as unknown as TaxInput;

    expect(failureOf(() => calculateTax(input))).toBe('unsupported context=transfer');
  });

  it('throws UnsupportedRuleError for an unknown tax type, even in a deleted member', () => {
    const member = {
      ...taxMember({ is_deleted: true }),
      type: 'surcharge',
    } as unknown as TaxInput['members'][0];

    expect(failureOf(() => calculateTax(taxInput({ members: [member] })))).toBe(
      'unsupported members[].type=surcharge',
    );
  });

  it('refuses an amount that is not a string as invalid_amount (AC-5)', () => {
    const numeric = { ...taxInput(), amount: 100 } as unknown as TaxInput;
    const missing = { ...taxInput(), amount: undefined } as unknown as TaxInput;

    expect(failureOf(() => calculateTax(numeric))).toBe('refused invalid_amount');
    expect(failureOf(() => calculateTax(missing))).toBe('refused invalid_amount');
  });

  it('treats a missing nullable key as a programmer error', () => {
    const member = taxMember();
    delete (member as unknown as Record<string, unknown>)['tax_code'];

    expect(failureOf(() => calculateTax(taxInput({ members: [member] })))).toBe('input-error');
  });

  it('treats a non-boolean flag as a programmer error', () => {
    const input = { ...taxInput(), is_inclusive: 'no' } as unknown as TaxInput;

    expect(failureOf(() => calculateTax(input))).toBe('input-error');
  });

  it('refuses a sequence outside 0 to 255, a fixed compound, and a blank code', () => {
    expect(
      failureOf(() => calculateTax(taxInput({ members: [taxMember({ sequence: '256' })] }))),
    ).toBe('input-error');
    expect(
      failureOf(() =>
        calculateTax(taxInput({ members: [taxMember({ type: 'fixed', is_compound: true })] })),
      ),
    ).toBe('input-error');
    expect(failureOf(() => calculateTax(taxInput({ members: [taxMember({ code: '' })] })))).toBe(
      'input-error',
    );
  });
});

describe('calculateTax: fixed and inclusive arithmetic', () => {
  it('charges a fixed tax by the base quantity and shows the line amount as its base', () => {
    const fixed = taxMember({ type: 'fixed', rates: [rateRow({ rate: '0.2500' })] });
    const result = calculateTax(taxInput({ members: [fixed], base_quantity: '24.0000' }));

    expect(result.lines[0]).toMatchObject({ base: '100.0000', amount: '6.0000', rate: '0.2500' });
  });

  it('backs a net out of an inclusive amount so that net plus tax is the gross', () => {
    const result = calculateTax(taxInput({ is_inclusive: true, amount: '115.0000' }));

    expect(result.net).toBe('100.0000');
    expect(result.total).toBe('15.0000');
  });

  it('refuses an inclusive amount smaller than the fixed taxes', () => {
    const fixed = taxMember({ type: 'fixed', rates: [rateRow({ rate: '5.0000' })] });

    expect(
      failureOf(() =>
        calculateTax(taxInput({ members: [fixed], is_inclusive: true, amount: '4.0000' })),
      ),
    ).toBe('refused inclusive_amount_too_small');
  });

  it('refuses a negative residue on a tiny-rate last member as out_of_range, as PHP does (D-17)', () => {
    // Net 0.0010, the 15 % tax rounds to 0.0002, the 0.0001 % tax to 0.0000: the residue is -0.0001
    // and lands on the last non-zero member.
    const big = taxMember({ tax_id: A, code: 'BIG', sequence: '1' });
    const tiny = taxMember({
      tax_id: B,
      code: 'TINY',
      sequence: '2',
      rates: [rateRow({ rate: '0.0001' })],
    });
    const input = taxInput({ members: [big, tiny], is_inclusive: true, amount: '0.0011' });

    try {
      calculateTax(input);
      expect.unreachable('the negative residue must be refused');
    } catch (error) {
      expect(error).toBeInstanceOf(PricingRefused);
      expect((error as PricingRefused).reason).toBe('out_of_range');
    }
  });
});
