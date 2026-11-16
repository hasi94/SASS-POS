import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Observable } from 'rxjs';
import { ApiError } from '../api/envelope';
import { SALES_CALLS, SalesCallId, callById } from './contract/calls';
import { asMoney, asQuantity } from './decimal-string';
import { BARCODE_RESOLVER, LINE_PRICER } from './ports';
import { SALES_WRITE_TIMEOUT_MS, SalesApi } from './sales-api';
import { ServerBarcodeResolver } from './server-barcode-resolver';
import { ServerLinePricer } from './server-line-pricer';

const ULID = '01JMOCKT000000000000000001';
const FORBIDDEN_KEYS = ['branch_id', 'terminal_id', 'location_id', 'business_date'];

interface Row {
  id: SalesCallId;
  url: string;
  call: (api: SalesApi) => Observable<unknown>;
  body?: unknown;
}

const money = asMoney('10.0000');
const qty = asQuantity('2.0000');

const rows: Row[] = [
  { id: 'shifts.current', url: '/api/v1/shifts/current', call: (a) => a.currentShift() },
  {
    id: 'shifts.open',
    url: '/api/v1/shifts',
    call: (a) => a.openShift({ opening_cash: money }),
    body: { opening_cash: '10.0000' },
  },
  {
    id: 'shifts.movement',
    url: '/api/v1/shifts/SH1/movements',
    call: (a) =>
      a.recordMovement('SH1', { id: ULID, type: 'cash_in', amount: money, reason: 'float' }),
    body: { id: ULID, type: 'cash_in', amount: '10.0000', reason: 'float' },
  },
  {
    id: 'shifts.close',
    url: '/api/v1/shifts/SH1/close',
    call: (a) => a.closeShift('SH1', { counted_cash: money }),
    body: { counted_cash: '10.0000' },
  },
  {
    id: 'sales.create',
    url: '/api/v1/sales',
    call: (a) => a.createSale({ customer_id: 'C1' }),
    body: { customer_id: 'C1' },
  },
  { id: 'sales.get', url: '/api/v1/sales/S1', call: (a) => a.getSale('S1') },
  {
    id: 'sales.add-line',
    url: '/api/v1/sales/S1/lines',
    call: (a) => a.addLine('S1', { product_id: 'P1', unit_id: 'U1', quantity: qty }),
    body: { product_id: 'P1', unit_id: 'U1', quantity: '2.0000' },
  },
  {
    id: 'sales.update-line',
    url: '/api/v1/sales/S1/lines/L1',
    call: (a) => a.updateLine('S1', 'L1', { quantity: qty, discount_id: null }),
    body: { quantity: '2.0000', discount_id: null },
  },
  {
    id: 'sales.remove-line',
    url: '/api/v1/sales/S1/lines/L1',
    call: (a) => a.removeLine('S1', 'L1'),
  },
  {
    id: 'sales.attach-customer',
    url: '/api/v1/sales/S1/customer',
    call: (a) => a.attachCustomer('S1', { customer_id: 'C1' }),
    body: { customer_id: 'C1' },
  },
  {
    id: 'sales.add-tender',
    url: '/api/v1/sales/S1/tenders',
    call: (a) => a.addTender('S1', { id: ULID, payment_method_id: 'M1', tendered: money }),
    body: { id: ULID, payment_method_id: 'M1', tendered: '10.0000' },
  },
  {
    id: 'sales.remove-tender',
    url: '/api/v1/sales/S1/tenders/T1',
    call: (a) => a.removeTender('S1', 'T1'),
  },
  {
    id: 'sales.complete',
    url: '/api/v1/sales/S1/complete',
    call: (a) => a.completeSale('S1'),
    body: {},
  },
  { id: 'sales.hold', url: '/api/v1/sales/S1/hold', call: (a) => a.holdSale('S1'), body: {} },
  { id: 'sales.recall', url: '/api/v1/sales/S1/recall', call: (a) => a.recallSale('S1'), body: {} },
  {
    id: 'sales.void',
    url: '/api/v1/sales/S1/void',
    call: (a) => a.voidSale('S1', { reason: 'mistake' }),
    body: { reason: 'mistake' },
  },
  {
    id: 'approvals.request',
    url: '/api/v1/approvals',
    call: (a) =>
      a.requestApproval({
        type: 'void',
        reference_type: 'sale',
        reference_id: 'S1',
        payload: null,
      }),
    body: { type: 'void', reference_type: 'sale', reference_id: 'S1', payload: null },
  },
  {
    id: 'approvals.decide',
    url: '/api/v1/approvals/A1/decide',
    call: (a) =>
      a.decideApproval('A1', {
        decision: 'approve',
        method: 'pin_local',
        employee_code: 'S100',
        pin: '2468',
      }),
    body: { decision: 'approve', method: 'pin_local', employee_code: 'S100', pin: '2468' },
  },
  {
    id: 'refunds.quote',
    url: '/api/v1/refunds/quote',
    call: (a) =>
      a.quoteRefund({
        reference_sale_id: 'S1',
        lines: [{ refund_of_item_id: 'L1', quantity: qty }],
      }),
    body: { reference_sale_id: 'S1', lines: [{ refund_of_item_id: 'L1', quantity: '2.0000' }] },
  },
  {
    id: 'refunds.create',
    url: '/api/v1/refunds',
    call: (a) =>
      a.createRefund({
        id: ULID,
        reference_sale_id: 'S1',
        approval_id: 'A1',
        lines: [{ refund_of_item_id: 'L1', quantity: qty }],
        tenders: [{ id: ULID, payment_method_id: 'M1', amount: money }],
      }),
    body: {
      id: ULID,
      reference_sale_id: 'S1',
      approval_id: 'A1',
      lines: [{ refund_of_item_id: 'L1', quantity: '2.0000' }],
      tenders: [{ id: ULID, payment_method_id: 'M1', amount: '10.0000' }],
    },
  },
  { id: 'payment-methods.list', url: '/api/v1/payment-methods', call: (a) => a.paymentMethods() },
  { id: 'till.categories', url: '/api/v1/till/categories', call: (a) => a.categories() },
  {
    id: 'till.scan',
    url: '/api/v1/till/scan',
    call: (a) => a.scan({ code: '12+34' }),
    body: { code: '12+34' },
  },
  {
    id: 'discounts.applicable',
    url: '/api/v1/sales/S1/lines/L1/discounts',
    call: (a) => a.applicableDiscounts('S1', 'L1'),
  },
];

const envelope = (data: unknown) => ({
  success: true,
  data,
  message: '',
  errors: [],
  meta: { request_id: 'r' },
});

describe('SalesApi', () => {
  let api: SalesApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(SalesApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it.each(rows.map((r) => [r.id, r] as const))(
    '%s uses the SALES_CALLS method and path and the exact body',
    (_id, row) => {
      let got: unknown = 'unset';
      row.call(api).subscribe((d) => (got = d));
      const req = http.expectOne(row.url);
      expect(req.request.method).toBe(callById(row.id).method);
      if (row.body !== undefined) {
        expect(req.request.body).toEqual(row.body);
      }
      const sent = JSON.stringify(req.request.body ?? {});
      for (const key of FORBIDDEN_KEYS) {
        expect(sent).not.toContain(`"${key}"`);
      }
      req.flush(envelope({ ok: true }));
      expect(got).toEqual({ ok: true });
    },
  );

  it('has a method for every call in the table', () => {
    const withQuery: SalesCallId[] = ['sales.list', 'till.products', 'customers.lookup'];
    const covered = [...rows.map((r) => r.id), ...withQuery].sort();
    expect(covered).toEqual(SALES_CALLS.map((c) => c.id).sort());
  });

  it('sends the sale list filters as query parameters, status comma separated', () => {
    api
      .listSales({
        status: ['draft', 'pending_payment'],
        scope: 'current_terminal',
        number: 'S-1',
        type: 'sale',
        cursor: 'c50',
        limit: 1,
      })
      .subscribe();
    const req = http.expectOne((r) => r.url === '/api/v1/sales');
    expect(req.request.method).toBe('GET');
    expect(req.request.params.get('status')).toBe('draft,pending_payment');
    expect(req.request.params.get('scope')).toBe('current_terminal');
    expect(req.request.params.get('number')).toBe('S-1');
    expect(req.request.params.get('type')).toBe('sale');
    expect(req.request.params.get('cursor')).toBe('c50');
    expect(req.request.params.get('limit')).toBe('1');
    req.flush(envelope({ items: [], next_cursor: null }));
  });

  it('sends only the filters it was given', () => {
    api.listSales().subscribe();
    const req = http.expectOne((r) => r.url === '/api/v1/sales');
    expect(req.request.params.keys()).toEqual([]);
    req.flush(envelope({ items: [], next_cursor: null }));
  });

  it('always sends the limit on a product page, and the rest only when given', () => {
    api.products({ limit: 50 }).subscribe();
    const first = http.expectOne((r) => r.url === '/api/v1/till/products');
    expect(first.request.params.keys()).toEqual(['limit']);
    expect(first.request.params.get('limit')).toBe('50');
    first.flush(envelope({ items: [], next_cursor: null }));

    api.products({ limit: 50, category_id: 'C1', q: 'rice', cursor: 'c50' }).subscribe();
    const second = http.expectOne((r) => r.url === '/api/v1/till/products');
    expect(second.request.params.get('category_id')).toBe('C1');
    expect(second.request.params.get('q')).toBe('rice');
    expect(second.request.params.get('cursor')).toBe('c50');
    second.flush(envelope({ items: [], next_cursor: null }));
  });

  it('looks customers up by the q parameter', () => {
    api.lookupCustomers('0771').subscribe();
    const req = http.expectOne((r) => r.url === '/api/v1/customers/lookup');
    expect(req.request.params.get('q')).toBe('0771');
    req.flush(envelope([]));
  });

  describe('write timeout (P-9)', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('turns a write that is not answered in time into an ApiError with status 0', () => {
      let err: unknown;
      api
        .addTender('S1', { id: ULID, payment_method_id: 'M1', tendered: money })
        .subscribe({ error: (e) => (err = e) });
      http.expectOne('/api/v1/sales/S1/tenders');
      vi.advanceTimersByTime(15001);
      expect(err).toBeInstanceOf(ApiError);
      expect((err as ApiError).status).toBe(0);
    });

    it('does not time out a write that is answered', () => {
      let got: unknown;
      api.holdSale('S1').subscribe((d) => (got = d));
      http.expectOne('/api/v1/sales/S1/hold').flush(envelope({ ok: 1 }));
      vi.advanceTimersByTime(60000);
      expect(got).toEqual({ ok: 1 });
    });

    it('leaves a read to the browser', () => {
      let err: unknown = null;
      api.getSale('S1').subscribe({ error: (e) => (err = e) });
      http.expectOne('/api/v1/sales/S1');
      vi.advanceTimersByTime(60000);
      expect(err).toBeNull();
    });

    it('takes the limit from SALES_WRITE_TIMEOUT_MS', () => {
      TestBed.resetTestingModule();
      TestBed.configureTestingModule({
        providers: [
          provideHttpClient(),
          provideHttpClientTesting(),
          { provide: SALES_WRITE_TIMEOUT_MS, useValue: 100 },
        ],
      });
      const shortApi = TestBed.inject(SalesApi);
      const shortHttp = TestBed.inject(HttpTestingController);
      let err: unknown;
      shortApi.completeSale('S1').subscribe({ error: (e) => (err = e) });
      shortHttp.expectOne('/api/v1/sales/S1/complete');
      vi.advanceTimersByTime(101);
      expect((err as ApiError).status).toBe(0);
    });
  });
});

describe('server adapters (P-10)', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('the tokens default to the server adapters', () => {
    expect(TestBed.inject(LINE_PRICER)).toBeInstanceOf(ServerLinePricer);
    expect(TestBed.inject(BARCODE_RESOLVER)).toBeInstanceOf(ServerBarcodeResolver);
  });

  it('ServerLinePricer.addLine calls sales.add-line', () => {
    const pricer = TestBed.inject(ServerLinePricer);
    pricer.addLine('S1', { product_id: 'P1', unit_id: 'U1', quantity: qty }).subscribe();
    const req = http.expectOne('/api/v1/sales/S1/lines');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ product_id: 'P1', unit_id: 'U1', quantity: '2.0000' });
    req.flush(envelope({}));
  });

  it('ServerLinePricer.updateLine calls sales.update-line', () => {
    TestBed.inject(ServerLinePricer).updateLine('S1', 'L1', { quantity: qty }).subscribe();
    const req = http.expectOne('/api/v1/sales/S1/lines/L1');
    expect(req.request.method).toBe('PUT');
    req.flush(envelope({}));
  });

  it('ServerLinePricer.quoteRefund calls refunds.quote', () => {
    TestBed.inject(ServerLinePricer)
      .quoteRefund({ reference_sale_id: null, lines: [] })
      .subscribe();
    const req = http.expectOne('/api/v1/refunds/quote');
    expect(req.request.method).toBe('POST');
    req.flush(envelope({}));
  });

  it('ServerBarcodeResolver trims the code and changes nothing else (AC-22)', () => {
    TestBed.inject(ServerBarcodeResolver).resolve('  12+34 ').subscribe();
    const req = http.expectOne('/api/v1/till/scan');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ code: '12+34' });
    req.flush(envelope({}));
  });

  it('keeps every other character of a code, including case and inner spaces', () => {
    TestBed.inject(ServerBarcodeResolver).resolve('a B/c 9').subscribe();
    const req = http.expectOne('/api/v1/till/scan');
    expect(req.request.body).toEqual({ code: 'a B/c 9' });
    req.flush(envelope({}));
  });
});
