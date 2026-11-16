/**
 * Demonstration data of the sales mock, as plain literals (T-037 AC-91). Fictional throughout: the
 * employee codes and PINs here are made up for the mock and are not real credentials. Built by a
 * function so that nothing at module level runs (tree shaking, see `index.ts`).
 */

/** The id of a seeded record: ULID-shaped, deterministic and readable in a fixture. */
export function seedId(tag: string, n: number): string {
  return `01JMCK${tag}${String(n).padStart(19, '0')}`;
}

export interface SeedUnit {
  id: string;
  code: string;
}

export interface SeedProductUnit {
  unit_id: string;
  unit_code: string;
  /** Stock units per unit, six decimals (T-030 `unit_factor`). */
  factor: string;
  /** The list price in this unit, or null when the product has no price for it. */
  price: string | null;
}

export interface SeedBarcode {
  code: string;
  /** The unit the code stands for; null means the product's default unit. */
  unit_id: string | null;
  /** A pack barcode carries its quantity; null means the code gives none. */
  quantity: string | null;
}

export interface SeedProduct {
  id: string;
  name: string;
  sku: string;
  plu: string | null;
  category_id: string;
  type: 'standard' | 'variant_parent';
  is_active: boolean;
  is_sellable: boolean;
  is_weighable: boolean;
  /** Tax as a fraction (exclusive), for example `0.0800`. */
  tax_rate: string;
  allow_price_override: boolean;
  allow_discount: boolean;
  /** The first unit is the default one; a weighable product's first unit is its weighing unit. */
  units: SeedProductUnit[];
  barcodes: SeedBarcode[];
}

export interface SeedCategory {
  id: string;
  name: string;
}

export interface SeedPaymentMethod {
  id: string;
  code: string;
  name: string;
  type: string;
  opens_drawer: boolean;
  requires_reference: boolean;
  allows_change: boolean;
  allows_refund: boolean;
  allows_partial: boolean;
}

export interface SeedCustomer {
  id: string;
  code: string;
  name: string;
  phone: string | null;
  credit_balance: string;
}

export interface SeedDiscount {
  id: string;
  code: string;
  name: string;
  description: string;
  /** The discount as a fraction of the line subtotal, for example `0.1000`. */
  rate: string;
  requires_approval: boolean;
  is_active: boolean;
  /** Categories it applies to; empty means every category. */
  category_ids: string[];
  usage_limit: number | null;
  used_count: number;
}

export interface SeedEmployee {
  id: string;
  code: string;
  name: string;
  pin: string;
  can_approve: boolean;
}

export interface MockSeed {
  currency: string;
  units: SeedUnit[];
  categories: SeedCategory[];
  products: SeedProduct[];
  paymentMethods: SeedPaymentMethod[];
  customers: SeedCustomer[];
  discounts: SeedDiscount[];
  employees: SeedEmployee[];
}

/** A 13 digit scale label starting `21` carries an item code and a weight (kind `embedded_weight`). */
export const SCALE_LABEL_WEIGHT_PREFIX = '21';
/** A 13 digit scale label starting `22` carries an item code and a price (kind `embedded_price`). */
export const SCALE_LABEL_PRICE_PREFIX = '22';

export function buildSeed(): MockSeed {
  const ea = seedId('N', 1);
  const kg = seedId('N', 2);
  const box = seedId('N', 3);
  const pack100 = seedId('N', 4);
  const grocery = seedId('C', 1);
  const drinks = seedId('C', 2);
  const household = seedId('C', 3);

  const standard = (
    n: number,
    name: string,
    sku: string,
    plu: string | null,
    category_id: string,
    units: SeedProductUnit[],
    rest: Partial<SeedProduct> = {},
  ): SeedProduct => ({
    id: seedId('P', n),
    name,
    sku,
    plu,
    category_id,
    type: 'standard',
    is_active: true,
    is_sellable: true,
    is_weighable: false,
    tax_rate: '0.0000',
    allow_price_override: false,
    allow_discount: true,
    units,
    barcodes: [],
    ...rest,
  });
  const each = (price: string | null): SeedProductUnit => ({
    unit_id: ea,
    unit_code: 'ea',
    factor: '1.000000',
    price,
  });

  const products: SeedProduct[] = [
    standard(1, 'Rice 1kg', 'RICE-1KG', '101', grocery, [each('250.0000')], {
      barcodes: [{ code: '4790000000011', unit_id: null, quantity: null }],
    }),
    standard(
      2,
      'Mineral Water 500ml',
      'WATER-500',
      '102',
      drinks,
      [each('80.0000'), { unit_id: box, unit_code: 'box', factor: '6.000000', price: '450.0000' }],
      {
        tax_rate: '0.0800',
        barcodes: [
          { code: '4790000000028', unit_id: null, quantity: null },
          { code: '4790000000035', unit_id: box, quantity: '1.0000' },
        ],
      },
    ),
    standard(
      3,
      'Loose Tomatoes',
      'TOMATO-KG',
      '201',
      grocery,
      [{ unit_id: kg, unit_code: 'kg', factor: '1.000000', price: '320.0000' }],
      { is_weighable: true },
    ),
    standard(4, 'Sample Sachet', 'SAMPLE-1', '301', household, [each('0.0000')], {
      allow_discount: false,
    }),
    standard(5, 'Custom Poster', 'POSTER-A2', '401', household, [each('1500.0000')], {
      tax_rate: '0.0800',
      allow_price_override: true,
    }),
    standard(6, 'Laundry Soap', 'SOAP-250', '501', household, [each('120.0000')], {
      barcodes: [{ code: '4790000000042', unit_id: null, quantity: null }],
    }),
    {
      ...standard(7, 'Tee Shirt', 'TEE', null, household, [each('1800.0000')]),
      type: 'variant_parent',
    },
    standard(8, 'Tee Shirt S', 'TEE-S', null, household, [each('1800.0000')], {
      barcodes: [{ code: '4790000000059', unit_id: null, quantity: null }],
    }),
    standard(9, 'Tee Shirt M', 'TEE-M', null, household, [each('1800.0000')], {
      barcodes: [{ code: '4790000000066', unit_id: null, quantity: null }],
    }),
    standard(10, 'Retired Item', 'RETIRED-1', null, household, [each('99.0000')], {
      is_active: false,
    }),
    standard(11, 'Display Model', 'DISPLAY-1', null, household, [each('99.0000')], {
      is_sellable: false,
    }),
    standard(12, 'Loose Screws', 'SCREW-1', '601', household, [
      each('5.0000'),
      { unit_id: pack100, unit_code: 'pk100', factor: '100.000000', price: null },
    ]),
  ];

  // Enough extra products that the grid has a second page (page size 50).
  for (let i = 1; i <= 60; i++) {
    const label = String(i).padStart(2, '0');
    products.push(
      standard(12 + i, `Bulk Item ${label}`, `BULK-${label}`, null, grocery, [
        each(`${10 + i}.0000`),
      ]),
    );
  }

  return {
    currency: 'LKR',
    units: [
      { id: ea, code: 'ea' },
      { id: kg, code: 'kg' },
      { id: box, code: 'box' },
      { id: pack100, code: 'pk100' },
    ],
    categories: [
      { id: grocery, name: 'Grocery' },
      { id: drinks, name: 'Drinks' },
      { id: household, name: 'Household' },
    ],
    products,
    paymentMethods: [
      {
        id: seedId('M', 1),
        code: 'CASH',
        name: 'Cash',
        type: 'cash',
        opens_drawer: true,
        requires_reference: false,
        allows_change: true,
        allows_refund: true,
        allows_partial: true,
      },
      {
        id: seedId('M', 2),
        code: 'CARD',
        name: 'Card',
        type: 'card',
        opens_drawer: false,
        requires_reference: true,
        allows_change: false,
        allows_refund: true,
        allows_partial: true,
      },
      {
        id: seedId('M', 3),
        code: 'CREDIT',
        name: 'Store credit',
        type: 'credit',
        opens_drawer: false,
        requires_reference: false,
        allows_change: false,
        allows_refund: true,
        allows_partial: true,
      },
      {
        id: seedId('M', 4),
        code: 'LOYALTY',
        name: 'Loyalty points',
        type: 'loyalty',
        opens_drawer: false,
        requires_reference: false,
        allows_change: false,
        allows_refund: false,
        allows_partial: true,
      },
      {
        id: seedId('M', 5),
        code: 'VOUCHER',
        name: 'Voucher',
        type: 'voucher',
        opens_drawer: false,
        requires_reference: true,
        allows_change: false,
        allows_refund: false,
        allows_partial: false,
      },
    ],
    customers: [
      {
        id: seedId('K', 1),
        code: 'CUS-0001',
        name: 'Nimal Perera',
        phone: '0771234567',
        credit_balance: '2500.0000',
      },
      {
        id: seedId('K', 2),
        code: 'CUS-0002',
        name: 'Kamala Silva',
        phone: '0712223344',
        credit_balance: '0.0000',
      },
    ],
    discounts: [
      {
        id: seedId('D', 1),
        code: 'SAVE10',
        name: '10% off',
        description: '10% off the line',
        rate: '0.1000',
        requires_approval: false,
        is_active: true,
        category_ids: [],
        usage_limit: null,
        used_count: 0,
      },
      {
        id: seedId('D', 2),
        code: 'MGR20',
        name: 'Manager 20% off',
        description: '20% off the line, a supervisor must approve',
        rate: '0.2000',
        requires_approval: true,
        is_active: true,
        category_ids: [],
        usage_limit: null,
        used_count: 0,
      },
      {
        id: seedId('D', 3),
        code: 'HOME5',
        name: 'Household 5% off',
        description: '5% off household goods',
        rate: '0.0500',
        requires_approval: false,
        is_active: true,
        category_ids: [household],
        usage_limit: null,
        used_count: 0,
      },
      {
        id: seedId('D', 4),
        code: 'USED0',
        name: 'Used-up 15% off',
        description: '15% off, already used up',
        rate: '0.1500',
        requires_approval: false,
        is_active: true,
        category_ids: [],
        usage_limit: 1,
        used_count: 1,
      },
      {
        id: seedId('D', 5),
        code: 'OLD10',
        name: 'Retired 10% off',
        description: '10% off, no longer active',
        rate: '0.1000',
        requires_approval: false,
        is_active: false,
        category_ids: [],
        usage_limit: null,
        used_count: 0,
      },
    ],
    employees: [
      { id: seedId('E', 1), code: 'D001', name: 'Demo Owner', pin: '1111', can_approve: false },
      {
        id: seedId('E', 2),
        code: 'S100',
        name: 'Supervisor Silva',
        pin: '2468',
        can_approve: true,
      },
      { id: seedId('E', 3), code: 'C200', name: 'Cashier Perera', pin: '1357', can_approve: false },
    ],
  };
}
