import {
  ApplicableDiscount,
  CustomerSummary,
  PaymentMethod,
  ScanResolution,
  TillCategory,
  TillProduct,
} from '../../sales/contract/catalog';
import { Money, Quantity } from '../../sales/decimal-string';
import { format4 } from '../mock-decimal';
import { HandlerTable, MockContext } from '../mock-context';
import { MockResult, invalid, ok, refuse } from '../mock-envelope';
import { findProduct, tillProductOf, unsellableFact } from '../mock-pricing';
import {
  MockSeed,
  SCALE_LABEL_PRICE_PREFIX,
  SCALE_LABEL_WEIGHT_PREFIX,
  SeedCustomer,
  SeedProduct,
} from '../mock-seed';

const PAGE_LIMIT_MAX = 50;
const CURSOR = /^c(\d+)$/;

export function customerSummaryOf(customer: SeedCustomer): CustomerSummary {
  return {
    id: customer.id,
    code: customer.code,
    name: customer.name,
    phone: customer.phone,
    credit_balance: customer.credit_balance as Money,
  };
}

function categories(ctx: MockContext): MockResult {
  const list: TillCategory[] = ctx.state.seed.categories.map((c) => ({ id: c.id, name: c.name }));
  return ok(list);
}

function paymentMethods(ctx: MockContext): MockResult {
  const list: PaymentMethod[] = ctx.state.seed.paymentMethods.map((m) => ({
    id: m.id,
    code: m.code,
    name: m.name,
    type: m.type as PaymentMethod['type'],
    opens_drawer: m.opens_drawer,
    requires_reference: m.requires_reference,
    allows_change: m.allows_change,
    allows_refund: m.allows_refund,
    allows_partial: m.allows_partial,
  }));
  return ok(list);
}

function matchesText(product: SeedProduct, needle: string): boolean {
  const fields = [
    product.name,
    product.sku,
    product.plu ?? '',
    ...product.barcodes.map((b) => b.code),
  ];
  return fields.some((f) => f.toLowerCase().includes(needle));
}

function products(ctx: MockContext): MockResult {
  const rawLimit = ctx.query('limit');
  if (
    rawLimit === null ||
    !/^[1-9]\d{0,2}$/.test(rawLimit) ||
    parseInt(rawLimit, 10) > PAGE_LIMIT_MAX
  ) {
    return invalid(
      'limit',
      `The limit is required and must be a whole number from 1 to ${PAGE_LIMIT_MAX}.`,
    );
  }
  const limit = parseInt(rawLimit, 10);
  const rawCursor = ctx.query('cursor');
  let offset = 0;
  if (rawCursor !== null) {
    const m = CURSOR.exec(rawCursor);
    if (!m) {
      return invalid('cursor', 'The cursor is not one this server issued.');
    }
    offset = parseInt(m[1] ?? '0', 10);
  }
  const category = ctx.query('category_id');
  const needle = (ctx.query('q') ?? '').trim().toLowerCase();
  const sellable = ctx.state.seed.products.filter((p) => unsellableFact(p) === null);
  const matching = sellable.filter(
    (p) =>
      (category === null || p.category_id === category) &&
      (needle === '' || matchesText(p, needle)),
  );
  const items: TillProduct[] = matching
    .slice(offset, offset + limit)
    .map((p) => tillProductOf(ctx.state.seed, p));
  const next = offset + limit < matching.length ? `c${offset + limit}` : null;
  return ok({ items, next_cursor: next });
}

interface Found {
  product: SeedProduct;
  unitId: string | null;
  quantity: string | null;
  kind: ScanResolution['kind'];
  source: ScanResolution['source'];
}

/** The server's resolution order: exact barcode, then PLU, then scale-label rules. */
function resolveCode(seed: MockSeed, code: string): Found | null {
  for (const product of seed.products) {
    const barcode = product.barcodes.find((b) => b.code === code);
    if (barcode) {
      return {
        product,
        unitId: barcode.unit_id,
        quantity: barcode.quantity,
        kind: 'product',
        source: 'barcode',
      };
    }
  }
  const byPlu = seed.products.find((p) => p.plu !== null && p.plu === code);
  if (byPlu) {
    return { product: byPlu, unitId: null, quantity: null, kind: 'product', source: 'plu' };
  }
  const label = /^(\d{2})(\d{5})(\d{5})\d{1}$/.exec(code.length === 13 ? code : '');
  if (label) {
    const prefix = label[1];
    const item = (label[2] ?? '').replace(/^0+(?=\d)/, '');
    const product = seed.products.find((p) => p.plu === item);
    if (product && prefix === SCALE_LABEL_WEIGHT_PREFIX) {
      const grams = label[3] ?? '';
      const whole = grams.slice(0, 2).replace(/^0+(?=\d)/, '');
      const quantity = format4(`${whole}.${grams.slice(2)}`);
      return { product, unitId: null, quantity, kind: 'embedded_weight', source: 'rule' };
    }
    if (product && prefix === SCALE_LABEL_PRICE_PREFIX) {
      return { product, unitId: null, quantity: null, kind: 'embedded_price', source: 'rule' };
    }
  }
  return null;
}

function scan(ctx: MockContext): MockResult {
  const unknown = Object.keys(ctx.body).filter((k) => k !== 'code');
  if (unknown.length > 0) {
    return invalid(unknown[0] ?? '', 'This field is not accepted.');
  }
  const code = ctx.body['code'];
  if (typeof code !== 'string' || code.trim() === '') {
    return invalid('code', 'The code is required.');
  }
  const found = resolveCode(ctx.state.seed, code);
  if (!found) {
    return refuse('not_found', 'Code not found.', { module: 'catalog' });
  }
  const failed = unsellableFact(found.product);
  if (failed) {
    return refuse('product_not_sellable', 'This product cannot be sold.', {
      field: 'failed_fact',
      detail: failed,
    });
  }
  const till = tillProductOf(ctx.state.seed, found.product);
  const unit =
    found.product.units.find((u) => u.unit_id === found.unitId) ?? found.product.units[0];
  const resolution: ScanResolution = {
    kind: found.kind,
    product: till,
    unit_id: unit?.unit_id ?? till.unit_id,
    unit_code: unit?.unit_code ?? till.unit_code,
    quantity: found.quantity as Quantity | null,
    source: found.source,
  };
  return ok(resolution);
}

function applicableDiscounts(ctx: MockContext): MockResult {
  const sale = ctx.branch.sales.find((s) => s.data.id === ctx.params['id']);
  if (!sale) {
    return refuse('not_found', 'Sale not found.');
  }
  const line = sale.data.lines.find((l) => l.id === ctx.params['lineId']);
  if (!line) {
    return refuse('not_found', 'Line not found.');
  }
  const product = findProduct(ctx.state.seed, line.product_id);
  const list: ApplicableDiscount[] = ctx.state.seed.discounts
    .filter(
      (d) =>
        d.is_active &&
        !!product &&
        product.allow_discount &&
        (d.category_ids.length === 0 || d.category_ids.includes(product.category_id)),
    )
    .map((d) => ({
      id: d.id,
      code: d.code,
      name: d.name,
      requires_approval: d.requires_approval,
      description: d.description,
    }));
  return ok(list);
}

function lookupCustomers(ctx: MockContext): MockResult {
  const q = (ctx.query('q') ?? '').trim().toLowerCase();
  if (q === '') {
    return invalid('q', 'The search text is required.');
  }
  const found = ctx.state.seed.customers
    .filter((c) => [c.code, c.name, c.phone ?? ''].some((f) => f.toLowerCase().includes(q)))
    .slice(0, 20)
    .map(customerSummaryOf);
  return ok(found);
}

export function catalogHandlers(): HandlerTable {
  return {
    'till.categories': categories,
    'till.products': products,
    'till.scan': scan,
    'payment-methods.list': paymentMethods,
    'discounts.applicable': applicableDiscounts,
    'customers.lookup': lookupCustomers,
  };
}
