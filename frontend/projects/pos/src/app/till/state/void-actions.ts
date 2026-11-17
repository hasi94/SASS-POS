import { Injectable, inject, signal } from '@angular/core';
import { Sale, SaleStatus, SalesApi, formatMoney } from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { ApprovalFlow } from './approval-flow';
import { Connectivity } from './connectivity';
import { Notifier } from './notifier';
import { SaleStore } from './sale-store';

/**
 * The sale a void is about. A full `Sale` (the cart) and a `SaleSummary` (a list row) both fit,
 * so the shift's open-sales list can hand either one to the void dialog.
 */
export interface VoidTarget {
  id: string;
  number: string | null;
  reference: string | null;
  status: SaleStatus;
  currency: string;
  total: string;
}

/** What the void dialog is opened with (`DialogHost.open('void', data)`). Both parts optional. */
export interface VoidDialogData {
  /** The sale to void. Without it the dialog uses the cart's sale, or asks for one to be found. */
  sale?: VoidTarget;
}

/** What the find-sale dialog is opened with, and closes with a `SaleSummary` (or nothing). */
export interface FindSaleDialogData {
  /** The statuses to list. Default: completed sales only. */
  statuses?: SaleStatus[];
}

/** What the refund dialog is opened with when a void sends the cashier there (AC-57). */
export interface RefundDialogData {
  saleId: string;
}

/** How a void ended. A refusal carries the error exactly as it came, for the catalogue. */
export type VoidOutcome =
  | { kind: 'voided'; sale: Sale }
  | { kind: 'refused'; error: unknown }
  | { kind: 'rejected' }
  | { kind: 'cancelled' };

/** The statuses a void without approval applies to (T-030 AC-21). */
const UNFINISHED: readonly SaleStatus[] = ['draft', 'held', 'pending_payment'];

/** True when a sale in this status can be voided at all. */
export function isVoidable(status: SaleStatus): boolean {
  return UNFINISHED.includes(status) || status === 'completed';
}

/** The name a sale goes by on screen: its number, or the reference it has before one is given. */
export function saleLabel(sale: Pick<VoidTarget, 'number' | 'reference'>): string {
  return sale.number ?? sale.reference ?? 'this sale';
}

/**
 * Voiding a sale (T-037 AC-55 to AC-58). A draft, held or part-paid sale is voided with a
 * reason alone and the cart is cleared; a completed sale needs a supervisor's `void` approval
 * bound to it, which goes through `ApprovalFlow.withApproval`, and is voided with that approval's
 * id. The server decides everything else: a refusal comes back as it is and the sale is
 * unchanged, so the dialog can show it through the refusal catalogue.
 */
@Injectable()
export class VoidActions {
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  private readonly flow = inject(ApprovalFlow);
  private readonly notifier = inject(Notifier);
  private readonly cart = inject(SaleStore);
  private readonly working = signal(false);

  /** True while a void (or its approval) is in progress. */
  readonly busy = this.working.asReadonly();

  async voidSale(target: VoidTarget, reason: string): Promise<VoidOutcome> {
    if (this.working()) {
      return { kind: 'cancelled' };
    }
    this.working.set(true);
    try {
      const sale =
        target.status === 'completed'
          ? await this.voidCompleted(target, reason)
          : await firstValueFrom(this.connectivity.track(this.api.voidSale(target.id, { reason })));
      if ('kind' in sale) {
        return sale;
      }
      if (this.cart.current()?.id === sale.id) {
        this.cart.clear();
      }
      this.notifier.success(`Sale ${saleLabel(sale)} voided`);
      return { kind: 'voided', sale };
    } catch (err) {
      return { kind: 'refused', error: err };
    } finally {
      this.working.set(false);
    }
  }

  /** The approved path: `void` bound to this sale, then the void with the approval's id. */
  private async voidCompleted(
    target: VoidTarget,
    reason: string,
  ): Promise<Sale | { kind: 'rejected' } | { kind: 'cancelled' }> {
    const result = await this.flow.withApproval(
      () => ({
        request: {
          type: 'void',
          reference_type: 'sale',
          reference_id: target.id,
          payload: null,
        },
        summary: {
          title: `Void sale ${saleLabel(target)}`,
          lines: [`Total ${formatMoney(target.total)} ${target.currency}`, `Reason: ${reason}`],
        },
      }),
      (approvalId) =>
        firstValueFrom(
          this.connectivity.track(
            this.api.voidSale(target.id, { reason, approval_id: approvalId }),
          ),
        ),
    );
    return result.kind === 'done' ? result.value : result;
  }
}
