import { TestBed } from '@angular/core/testing';
import {
  BARCODE_RESOLVER,
  BarcodeResolver,
  LINE_PRICER,
  LinePricer,
  Quantity,
  Sale,
  SaleLine,
  SalesApi,
  ScanResolution,
  TillProduct,
  asMoney,
  asQuantity,
} from '@flexipos/shared';
import { of } from 'rxjs';
import { SaleStore } from '../../state/sale-store';
import { allById, byId, scan, settleFully, storeOf } from '../../testing/sale-screen-helpers';
import { TillHarness, setupTill } from '../../testing/till-harness';

/**
 * AC-7: the till depends only on the two ports. These specs replace both with stubs that know
 * nothing of the server's catalogue or pricing, and show that the unchanged components (scan
 * field, queue, store, cart) add a line from a scan using the stubs alone. The sale itself is
 * still created through the server (that is not one of the two ports); every line figure the
 * cart shows comes from the stub pricer.
 */

const STUB_PRODUCT: TillProduct = {
  id: 'STUB-PRODUCT-1',
  name: 'Stub Widget',
  sku: 'STUB-1',
  plu: null,
  category_id: 'STUB-CATEGORY',
  list_price: asMoney('7.7700'),
  currency: 'LKR',
  is_weighable: false,
  unit_id: 'STUB-UNIT',
  unit_code: 'stub',
  weigh_unit_id: null,
  weigh_unit_code: null,
  units: [{ unit_id: 'STUB-UNIT', unit_code: 'stub' }],
  allow_price_override: false,
};

function stubLine(quantity: Quantity): SaleLine {
  return {
    id: 'STUB-LINE-1',
    line_no: 1,
    product_id: STUB_PRODUCT.id,
    product_name: STUB_PRODUCT.name,
    sku: STUB_PRODUCT.sku,
    unit_id: STUB_PRODUCT.unit_id,
    unit_code: STUB_PRODUCT.unit_code,
    quantity,
    unit_factor: '1.000000',
    base_quantity: quantity,
    base_unit_code: 'stub',
    list_price: asMoney('7.7700'),
    unit_price: asMoney('7.7700'),
    subtotal: asMoney('23.3100'),
    discount_id: null,
    discount_amount: asMoney('0.0000'),
    tax_amount: asMoney('0.0000'),
    line_total: asMoney('23.3100'),
    price_overridden: false,
    refund_of_item_id: null,
    refunded_quantity: asQuantity('0.0000'),
    refundable_quantity: quantity,
  };
}

interface Stubbed {
  harness: TillHarness;
  resolved: string[];
  priced: { saleId: string; quantity: string }[];
}

async function setupWithStubs(): Promise<Stubbed> {
  const resolved: string[] = [];
  const priced: { saleId: string; quantity: string }[] = [];
  let store: SaleStore | null = null;
  const resolver: BarcodeResolver = {
    resolve: (code) => {
      resolved.push(code);
      const resolution: ScanResolution = {
        kind: 'product',
        product: STUB_PRODUCT,
        unit_id: STUB_PRODUCT.unit_id,
        unit_code: STUB_PRODUCT.unit_code,
        quantity: null,
        source: 'barcode',
      };
      return of(resolution);
    },
  };
  const pricer: LinePricer = {
    addLine: (saleId, request) => {
      priced.push({ saleId, quantity: request.quantity });
      const created = store?.current();
      if (!created) {
        throw new Error('The sale should have been created before the line is priced.');
      }
      const sale: Sale = {
        ...created,
        lines: [stubLine(request.quantity)],
        subtotal: asMoney('23.3100'),
        total: asMoney('23.3100'),
      };
      return of(sale);
    },
    updateLine: () => {
      throw new Error('not used here');
    },
    quoteRefund: () => {
      throw new Error('not used here');
    },
  };
  const harness = await setupTill({
    given: ['shift_open'],
    providers: [
      { provide: BARCODE_RESOLVER, useValue: resolver },
      { provide: LINE_PRICER, useValue: pricer },
    ],
  });
  store = storeOf(harness, SaleStore);
  return { harness, resolved, priced };
}

describe('the pricing and barcode ports (AC-7)', () => {
  it('adds a line from a scan through stub adapters only: neither server call is made', async () => {
    const { harness: h, resolved, priced } = await setupWithStubs();
    const api = TestBed.inject(SalesApi);
    const serverScan = vi.spyOn(api, 'scan');
    const serverAddLine = vi.spyOn(api, 'addLine');
    await scan(h, 'STUB-CODE-1');
    await settleFully(h);

    expect(resolved).toEqual(['STUB-CODE-1']);
    expect(priced.map((p) => p.quantity)).toEqual(['1']);
    expect(serverScan).not.toHaveBeenCalled();
    expect(serverAddLine).not.toHaveBeenCalled();
    expect(
      storeOf(h, SaleStore)
        .lines()
        .map((l) => l.product_name),
    ).toEqual(['Stub Widget']);
    expect(h.text('line-name')).toBe('Stub Widget');
    expect(h.text('line-price')).toBe('Price 7.77 LKR');
    expect(h.text('line-total')).toBe('23.31 LKR');
    expect(h.text('polite-region')).toBe('Added Stub Widget');
  });

  it('passes a multiplier to the stub pricer as typed', async () => {
    const { harness: h, priced } = await setupWithStubs();
    await scan(h, '3*');
    await scan(h, 'STUB-CODE-1');
    await settleFully(h);
    expect(priced.map((p) => p.quantity)).toEqual(['3']);
    expect(allById(h, 'cart-line')).toHaveLength(1);
    expect(byId(h, 'line-quantity').textContent?.trim()).toBe('3 stub');
  });
});
