import { Injectable, InjectionToken, inject } from '@angular/core';
import { Observable, TimeoutError, catchError, throwError, timeout } from 'rxjs';
import { ApiClient } from '../api/api-client';
import { ApiError } from '../api/envelope';
import { Approval, DecideApprovalRequest, RequestApprovalRequest } from './contract/approval';
import { PathParams, SalesCallId, callById, callPath } from './contract/calls';
import {
  ApplicableDiscount,
  CustomerSummary,
  PaymentMethod,
  ProductQuery,
  ScanRequest,
  ScanResolution,
  TillCategory,
  TillProduct,
} from './contract/catalog';
import { Page } from './contract/common';
import { RefundQuote, RefundQuoteRequest, RefundRequest, RefundResult } from './contract/refund';
import {
  AddLineRequest,
  AddTenderRequest,
  AddTenderResponse,
  AttachCustomerRequest,
  CreateSaleRequest,
  ListSalesQuery,
  Sale,
  SaleSummary,
  UpdateLineRequest,
  VoidRequest,
} from './contract/sale';
import {
  CashMovement,
  CashMovementRequest,
  CashShift,
  CloseShiftRequest,
  ClosedShift,
  OpenShiftRequest,
} from './contract/shift';

/**
 * How long a write may take before the till treats the outcome as unknown (P-9). A timeout and a
 * dropped connection then take one path: both become `ApiError` status 0.
 */
export const SALES_WRITE_TIMEOUT_MS = new InjectionToken<number>('SALES_WRITE_TIMEOUT_MS', {
  providedIn: 'root',
  factory: () => 15000,
});

type Query = Record<string, string | number | boolean>;

/**
 * The only HTTP caller of the till (T-037 AC-1, P-6): one typed method per call in
 * {@link SALES_CALLS}, over the shared `ApiClient`. No request body or query carries a branch,
 * terminal, location or business date (AC-86); the server resolves those from the session.
 */
@Injectable({ providedIn: 'root' })
export class SalesApi {
  private readonly client = inject(ApiClient);
  private readonly writeTimeoutMs = inject(SALES_WRITE_TIMEOUT_MS);

  // Shifts

  currentShift(): Observable<CashShift | null> {
    return this.send('shifts.current');
  }

  openShift(body: OpenShiftRequest): Observable<CashShift> {
    return this.send('shifts.open', {}, body);
  }

  recordMovement(shiftId: string, body: CashMovementRequest): Observable<CashMovement> {
    return this.send('shifts.movement', { id: shiftId }, body);
  }

  closeShift(shiftId: string, body: CloseShiftRequest): Observable<ClosedShift> {
    return this.send('shifts.close', { id: shiftId }, body);
  }

  // Sales and lines

  createSale(body: CreateSaleRequest = {}): Observable<Sale> {
    return this.send('sales.create', {}, body);
  }

  getSale(saleId: string): Observable<Sale> {
    return this.send('sales.get', { id: saleId });
  }

  listSales(query: ListSalesQuery = {}): Observable<Page<SaleSummary>> {
    const params: Query = {};
    if (query.status && query.status.length > 0) {
      params['status'] = query.status.join(',');
    }
    if (query.scope) {
      params['scope'] = query.scope;
    }
    if (query.number) {
      params['number'] = query.number;
    }
    if (query.type) {
      params['type'] = query.type;
    }
    if (query.cursor) {
      params['cursor'] = query.cursor;
    }
    if (query.limit !== undefined) {
      params['limit'] = query.limit;
    }
    return this.send('sales.list', {}, undefined, params);
  }

  addLine(saleId: string, body: AddLineRequest): Observable<Sale> {
    return this.send('sales.add-line', { id: saleId }, body);
  }

  updateLine(saleId: string, lineId: string, body: UpdateLineRequest): Observable<Sale> {
    return this.send('sales.update-line', { id: saleId, lineId }, body);
  }

  removeLine(saleId: string, lineId: string): Observable<Sale> {
    return this.send('sales.remove-line', { id: saleId, lineId });
  }

  attachCustomer(saleId: string, body: AttachCustomerRequest): Observable<Sale> {
    return this.send('sales.attach-customer', { id: saleId }, body);
  }

  // Tenders and the sale lifecycle

  addTender(saleId: string, body: AddTenderRequest): Observable<AddTenderResponse> {
    return this.send('sales.add-tender', { id: saleId }, body);
  }

  removeTender(saleId: string, tenderId: string): Observable<Sale> {
    return this.send('sales.remove-tender', { id: saleId, tenderId });
  }

  completeSale(saleId: string): Observable<Sale> {
    return this.send('sales.complete', { id: saleId }, {});
  }

  holdSale(saleId: string): Observable<Sale> {
    return this.send('sales.hold', { id: saleId }, {});
  }

  recallSale(saleId: string): Observable<Sale> {
    return this.send('sales.recall', { id: saleId }, {});
  }

  voidSale(saleId: string, body: VoidRequest): Observable<Sale> {
    return this.send('sales.void', { id: saleId }, body);
  }

  // Approvals

  requestApproval(body: RequestApprovalRequest): Observable<Approval> {
    return this.send('approvals.request', {}, body);
  }

  decideApproval(approvalId: string, body: DecideApprovalRequest): Observable<Approval> {
    return this.send('approvals.decide', { id: approvalId }, body);
  }

  // Refunds

  quoteRefund(body: RefundQuoteRequest): Observable<RefundQuote> {
    return this.send('refunds.quote', {}, body);
  }

  createRefund(body: RefundRequest): Observable<RefundResult> {
    return this.send('refunds.create', {}, body);
  }

  // Reads for the till

  paymentMethods(): Observable<PaymentMethod[]> {
    return this.send('payment-methods.list');
  }

  categories(): Observable<TillCategory[]> {
    return this.send('till.categories');
  }

  products(query: ProductQuery): Observable<Page<TillProduct>> {
    const params: Query = { limit: query.limit };
    if (query.category_id) {
      params['category_id'] = query.category_id;
    }
    if (query.q) {
      params['q'] = query.q;
    }
    if (query.cursor) {
      params['cursor'] = query.cursor;
    }
    return this.send('till.products', {}, undefined, params);
  }

  scan(body: ScanRequest): Observable<ScanResolution> {
    return this.send('till.scan', {}, body);
  }

  applicableDiscounts(saleId: string, lineId: string): Observable<ApplicableDiscount[]> {
    return this.send('discounts.applicable', { id: saleId, lineId });
  }

  lookupCustomers(q: string): Observable<CustomerSummary[]> {
    return this.send('customers.lookup', {}, undefined, { q });
  }

  /**
   * Sends one call. Every call that is not a GET gets the write timeout (P-9): an unanswered
   * write is an unknown outcome, reported as status 0, never left hanging.
   */
  private send<T>(
    id: SalesCallId,
    params: PathParams = {},
    body?: unknown,
    query?: Query,
  ): Observable<T> {
    const call = callById(id);
    const path = callPath(id, params);
    let source: Observable<T>;
    switch (call.method) {
      case 'GET':
        return this.client.get<T>(path, query);
      case 'POST':
        source = this.client.post<T>(path, body);
        break;
      case 'PUT':
        source = this.client.put<T>(path, body);
        break;
      case 'DELETE':
        source = this.client.delete<T>(path);
        break;
    }
    return source.pipe(
      timeout(this.writeTimeoutMs),
      catchError((err: unknown) =>
        err instanceof TimeoutError
          ? throwError(() => new ApiError(0, 'The server did not answer in time.'))
          : throwError(() => err),
      ),
    );
  }
}
