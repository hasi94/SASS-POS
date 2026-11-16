import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { RefundQuote, RefundQuoteRequest } from './contract/refund';
import { AddLineRequest, Sale, UpdateLineRequest } from './contract/sale';
import type { LinePricer } from './ports';
import { SalesApi } from './sales-api';

/** The shipped {@link LinePricer}: the server prices the line when it is added or changed. */
@Injectable({ providedIn: 'root' })
export class ServerLinePricer implements LinePricer {
  private readonly api = inject(SalesApi);

  addLine(saleId: string, request: AddLineRequest): Observable<Sale> {
    return this.api.addLine(saleId, request);
  }

  updateLine(saleId: string, lineId: string, request: UpdateLineRequest): Observable<Sale> {
    return this.api.updateLine(saleId, lineId, request);
  }

  quoteRefund(request: RefundQuoteRequest): Observable<RefundQuote> {
    return this.api.quoteRefund(request);
  }
}
