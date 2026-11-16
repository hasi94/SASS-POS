import { Injectable } from '@angular/core';

/**
 * Tenders on the sale in the cart, with one idempotency id per submission kept for retries
 * (T-037 AC-43 to AC-50, D-11, D-12). Body: step B5.
 */
@Injectable()
export class PaymentStore {}
