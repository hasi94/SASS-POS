import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ScanResolution } from './contract/catalog';
import type { BarcodeResolver } from './ports';
import { SalesApi } from './sales-api';

/**
 * The shipped {@link BarcodeResolver}: the server resolves the code (exact barcode, PLU, then
 * barcode rules by priority). The code is sent unchanged apart from trimming surrounding
 * whitespace (AC-22): a `+` or any other character inside it is the server's to interpret.
 */
@Injectable({ providedIn: 'root' })
export class ServerBarcodeResolver implements BarcodeResolver {
  private readonly api = inject(SalesApi);

  resolve(code: string): Observable<ScanResolution> {
    return this.api.scan({ code: code.trim() });
  }
}
