import { Injectable } from '@angular/core';

/**
 * Scanned codes, resolved strictly in arrival order, one call at a time, none dropped (T-037
 * AC-28, D-10). Body: step B3.
 */
@Injectable()
export class ScanQueue {}
