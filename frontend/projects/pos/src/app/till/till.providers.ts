import { Provider } from '@angular/core';
import { ApprovalFlow } from './state/approval-flow';
import { Connectivity } from './state/connectivity';
import { DialogHost } from './state/dialog-host';
import { Notifier } from './state/notifier';
import { PaymentStore } from './state/payment-store';
import { RefundStore } from './state/refund-store';
import { SaleStore } from './state/sale-store';
import { ScanQueue } from './state/scan-queue';
import { ShiftStore } from './state/shift-store';
import { TillContext } from './state/till-context';
import { VoidActions } from './state/void-actions';

/**
 * Every till store, provided on `TillPage` and nowhere else (T-037 AC-87): sale data lives
 * exactly as long as the page, so nothing survives a sign-out or a change of branch. Frozen after
 * Chunk 0; a later step fills in a store's body, it does not add to this list.
 */
export function provideTill(): Provider[] {
  return [
    TillContext,
    Notifier,
    Connectivity,
    DialogHost,
    ShiftStore,
    SaleStore,
    ScanQueue,
    PaymentStore,
    ApprovalFlow,
    VoidActions,
    RefundStore,
  ];
}
