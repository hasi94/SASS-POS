import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { ApplicableDiscount, SaleLine, SalesApi } from '@flexipos/shared';
import { describeError } from '../../errors/refusal-catalogue';
import {
  ApprovalFlow,
  STALE_APPROVAL_CODES,
  isStaleApproval,
  withApproval,
} from '../../state/approval-flow';
import { Connectivity } from '../../state/connectivity';
import { DialogHost } from '../../state/dialog-host';
import { Notifier } from '../../state/notifier';
import { LineOutcome, SaleStore } from '../../state/sale-store';
import { DialogFrame } from '../../ui/dialog-frame';
import { isLineDialogData } from '../line-edit/line-edit-dialog';

/**
 * A discount on one line (T-037 AC-35, D-30). The list is the server's: only discounts it will
 * accept on this line. One that needs a supervisor asks for an approval bound to the discount
 * first; a discount the till did not know needed one (the server answers `approval_required`) is
 * retried once with an approval. A line with a price override cannot take a discount, and says
 * why instead of offering the list.
 */
@Component({
  selector: 'pos-discount-picker-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame],
  template: `
    <pos-dialog-frame heading="Discount">
      @if (line(); as current) {
        <p data-testid="discount-product">{{ current.product_name }}</p>
        @if (current.price_overridden) {
          <p class="fp-error" data-testid="discount-blocked">
            A line with a price override cannot take a discount
          </p>
          <button type="button" data-autofocus (click)="dialogs.close()">Close</button>
        } @else {
          @if (failure(); as message) {
            <p class="fp-error" role="alert" data-testid="discount-error">{{ message }}</p>
          }
          @if (loading()) {
            <p class="fp-muted" role="status">Loading discounts</p>
          } @else if (discounts().length === 0 && !failure()) {
            <p class="fp-muted" data-testid="discount-none">No discount applies to this line</p>
          }
          <ul class="choices" data-testid="discount-list">
            @for (discount of discounts(); track discount.id; let first = $first) {
              <li>
                <button
                  type="button"
                  class="choice"
                  [attr.data-autofocus]="first ? '' : null"
                  [attr.data-testid]="'discount-' + discount.code"
                  [disabled]="busy() || !online()"
                  (click)="choose(discount)"
                >
                  <span class="name">{{ discount.name }}</span>
                  <span class="fp-muted">{{ discount.description }}</span>
                  @if (discount.requires_approval) {
                    <span class="needs">Needs a supervisor</span>
                  }
                </button>
              </li>
            }
          </ul>
          <div class="actions">
            @if (current.discount_id) {
              <button
                type="button"
                data-testid="discount-remove"
                [disabled]="busy() || !online()"
                (click)="remove()"
              >
                Remove the discount
              </button>
            }
            <button type="button" (click)="dialogs.close()">Cancel</button>
          </div>
        }
      } @else {
        <p class="fp-muted">That line is no longer in the cart.</p>
        <button type="button" data-autofocus (click)="dialogs.close()">Close</button>
      }
    </pos-dialog-frame>
  `,
  styles: `
    p {
      margin: 0;
    }

    .choices {
      list-style: none;
      margin: 0;
      padding: 0;
      display: grid;
      gap: 6px;
    }

    .choice {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 2px;
      width: 100%;
      min-height: 44px;
      text-align: left;
    }

    .name {
      font-weight: 600;
    }

    .needs {
      font-size: 0.8rem;
      font-weight: 700;
    }

    .actions {
      display: flex;
      gap: 8px;
    }

    button {
      min-height: 44px;
    }
  `,
})
export class DiscountPickerDialog {
  protected readonly dialogs = inject(DialogHost);
  private readonly store = inject(SaleStore);
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  protected readonly online = this.connectivity.online;
  private readonly approvals = inject(ApprovalFlow);
  private readonly notifier = inject(Notifier);
  /** What the opener passed to `DialogHost.open`. */
  readonly data = input<unknown>();

  protected readonly discounts = signal<ApplicableDiscount[]>([]);
  protected readonly loading = signal(true);
  protected readonly failure = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly line = computed<SaleLine | null>(() => {
    const data = this.data();
    return isLineDialogData(data)
      ? (this.store.lines().find((l) => l.id === data.lineId) ?? null)
      : null;
  });

  constructor() {
    let started = false;
    effect(() => {
      const line = this.line();
      const sale = this.store.current();
      if (started || !line || !sale) {
        return;
      }
      started = true;
      if (line.price_overridden) {
        this.loading.set(false);
        return;
      }
      untracked(() =>
        this.connectivity.track(this.api.applicableDiscounts(sale.id, line.id)).subscribe({
          next: (list) => {
            this.discounts.set(list);
            this.loading.set(false);
          },
          error: (err: unknown) => {
            this.failure.set(describeError(err).text);
            this.loading.set(false);
          },
        }),
      );
    });
  }

  /** Puts the discount on the line, asking a supervisor first when the server says it must. */
  protected async choose(discount: ApplicableDiscount): Promise<void> {
    await this.apply(discount.id, discount.requires_approval, discount.name);
  }

  protected async remove(): Promise<void> {
    await this.apply(null, false, '');
  }

  private async apply(
    discountId: string | null,
    needsApproval: boolean,
    name: string,
  ): Promise<void> {
    const line = this.line();
    if (!line || this.busy()) {
      return;
    }
    this.busy.set(true);
    const mine = this.dialogs.currentId();
    try {
      let outcome: LineOutcome | null;
      if (needsApproval && discountId !== null) {
        outcome = await this.approved(line, discountId, name, mine);
      } else {
        outcome = await this.store.updateLine(
          line.id,
          { discount_id: discountId },
          discountId !== null ? ['approval_required'] : [],
        );
        if (!outcome.ok && outcome.code === 'approval_required' && discountId !== null) {
          // The list did not say this discount needed approval, but the server does: ask once.
          outcome = await this.approved(line, discountId, name, mine);
        }
      }
      if (outcome?.ok) {
        this.dialogs.closeIf(mine);
      }
    } finally {
      this.busy.set(false);
    }
  }

  /**
   * Puts the discount on the line with a supervisor's approval bound to it. A stale approval
   * starts the request again (AC-70); null means nothing was applied (rejected or cancelled).
   */
  private async approved(
    line: SaleLine,
    discountId: string,
    name: string,
    mine: number | undefined,
  ): Promise<LineOutcome | null> {
    const sale = this.store.current();
    if (!sale) {
      return null;
    }
    const result = await withApproval(
      this.approvals,
      () => ({
        request: {
          type: 'discount',
          reference_type: 'sale',
          reference_id: sale.id,
          payload: { discount_id: discountId },
        },
        summary: { title: 'Discount', lines: [line.product_name, name] },
      }),
      async (approvalId) => {
        const outcome = await this.store.updateLine(
          line.id,
          { discount_id: discountId, approval_id: approvalId },
          STALE_APPROVAL_CODES,
        );
        if (!outcome.ok && isStaleApproval(outcome.error)) {
          throw outcome.error;
        }
        return outcome;
      },
      (err) => this.notifier.fail(err),
    );
    if (result.kind === 'rejected') {
      this.notifier.info('Approval rejected');
      this.dialogs.closeIf(mine);
    }
    return result.kind === 'done' ? result.value : null;
  }
}
