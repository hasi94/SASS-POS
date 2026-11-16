import { InjectionToken, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { RefundQuote, RefundQuoteRequest } from './contract/refund';
import { ScanResolution } from './contract/catalog';
import { AddLineRequest, Sale, UpdateLineRequest } from './contract/sale';
import { ServerBarcodeResolver } from './server-barcode-resolver';
import { ServerLinePricer } from './server-line-pricer';

/**
 * The two seams the till depends on for pricing and scanning (T-037 D-5, AC-7, P-10). The till
 * performs no price, tax, discount or barcode-rule logic: it talks to these two ports, and this
 * task ships one adapter for each, which asks the server. A later client-side engine (T-035) or
 * barcode parser (FP-423) plugs in by providing a different value for the token; no component or
 * store names an adapter.
 */
export interface LinePricer {
  /** Adds a line; the sale that comes back carries the server's prices and totals. */
  addLine(saleId: string, request: AddLineRequest): Observable<Sale>;
  updateLine(saleId: string, lineId: string, request: UpdateLineRequest): Observable<Sale>;
  /** Prices refund lines without writing anything (P-7). */
  quoteRefund(request: RefundQuoteRequest): Observable<RefundQuote>;
}

export interface BarcodeResolver {
  /** Resolves a scanned code to a product, a unit and an optional quantity. */
  resolve(code: string): Observable<ScanResolution>;
}

/** Swap in a spec with `{ provide: LINE_PRICER, useValue: stub }`. */
export const LINE_PRICER = new InjectionToken<LinePricer>('LINE_PRICER', {
  providedIn: 'root',
  factory: () => inject(ServerLinePricer),
});

/** Swap in a spec with `{ provide: BARCODE_RESOLVER, useValue: stub }`. */
export const BARCODE_RESOLVER = new InjectionToken<BarcodeResolver>('BARCODE_RESOLVER', {
  providedIn: 'root',
  factory: () => inject(ServerBarcodeResolver),
});
