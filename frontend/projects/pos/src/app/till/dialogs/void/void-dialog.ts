import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { Sale, SaleSummary } from '@flexipos/shared';
import { describeError } from '../../errors/refusal-catalogue';
import { MoneyPipe } from '../../format/money.pipe';
import { DialogHost } from '../../state/dialog-host';
import { SaleStore } from '../../state/sale-store';
import {
  FindSaleDialogData,
  RefundDialogData,
  VoidActions,
  VoidDialogData,
  VoidTarget,
  isVoidable,
  saleLabel,
} from '../../state/void-actions';
import { DialogFrame } from '../../ui/dialog-frame';

/**
 * Voiding a sale (T-037 AC-55 to AC-58). The target is the sale the opener named, else the sale
 * in the cart, else one the cashier finds among this shift's completed sales. A reason is
 * required in the form before anything is sent. A completed sale asks a supervisor (through
 * `VoidActions`); a refusal is shown in words from the catalogue and the sale is unchanged, and a
 * closed shift (`shift_closed`) offers to start a refund for the sale instead.
 */
@Component({
  selector: 'pos-void-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame, MoneyPipe],
  template: `
    <pos-dialog-frame heading="Void a sale">
      @if (done(); as voided) {
        <p data-testid="void-result">
          Sale {{ label(voided) }} is voided<span> ({{ voided.void_reason }})</span>.
        </p>
        <div class="actions">
          <button type="button" class="primary" data-autofocus (click)="dialogs.close(voided)">
            Close
          </button>
        </div>
      } @else {
        @if (target(); as sale) {
          <p class="sale" data-testid="void-sale">
            Sale <strong>{{ label(sale) }}</strong> &middot;
            {{ sale.total | money: sale.currency }} &middot; {{ statusText(sale) }}
          </p>
          @if (sale.status === 'completed') {
            <p class="fp-muted">A supervisor must approve voiding a completed sale.</p>
          }
          <form (submit)="onSubmit($event)" novalidate>
            <label>
              Reason
              <input
                name="reason"
                type="text"
                autocomplete="off"
                data-autofocus
                [value]="reason()"
                (input)="onReason($event)"
                [attr.aria-invalid]="problem() ? 'true' : null"
              />
            </label>
            <p class="problem" role="alert" data-testid="void-problem">{{ problem() }}</p>
            @if (offerRefund()) {
              <p>
                <button type="button" data-testid="start-refund" (click)="startRefund(sale)">
                  Start a refund
                </button>
              </p>
            }
            <div class="actions">
              <button type="submit" class="primary" [disabled]="actions.busy()">Void sale</button>
              <button type="button" [disabled]="actions.busy()" (click)="find()">
                Find another sale
              </button>
              <button type="button" (click)="dialogs.close()">Cancel</button>
            </div>
          </form>
        } @else {
          <p data-testid="void-no-sale">
            There is no sale in the cart to void. Find a completed sale from this shift.
          </p>
          <div class="actions">
            <button type="button" class="primary" data-autofocus (click)="find()">Find sale</button>
            <button type="button" (click)="dialogs.close()">Cancel</button>
          </div>
        }
      }
    </pos-dialog-frame>
  `,
  styles: `
    form {
      display: grid;
      gap: 10px;
    }

    label {
      display: grid;
      gap: 4px;
    }

    input {
      min-height: 44px;
      padding: 0 10px;
      font: inherit;
    }

    .sale {
      margin: 0;
    }

    .problem {
      min-height: 1.4em;
      margin: 0;
      color: var(--fp-danger, #b00020);
    }

    .actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    button {
      min-height: 44px;
      padding: 0 16px;
    }
  `,
})
export class VoidDialog {
  protected readonly dialogs = inject(DialogHost);
  protected readonly actions = inject(VoidActions);
  private readonly cart = inject(SaleStore);

  /** What the opener passed to `DialogHost.open`: optionally the sale to void. */
  readonly data = input<unknown>();

  private readonly picked = signal<VoidTarget | null>(null);
  protected readonly reason = signal('');
  protected readonly problem = signal('');
  protected readonly done = signal<Sale | null>(null);
  private readonly refusedClosed = signal(false);

  /** The sale to void: the one picked here, the opener's, or the cart's if it can be voided. */
  protected readonly target = computed<VoidTarget | null>(() => {
    const picked = this.picked();
    if (picked) {
      return picked;
    }
    const given = (this.data() as VoidDialogData | undefined)?.sale;
    if (given) {
      return given;
    }
    const inCart = this.cart.current();
    return inCart && isVoidable(inCart.status) ? inCart : null;
  });

  protected readonly offerRefund = computed(() => this.refusedClosed());

  protected label(sale: Pick<VoidTarget, 'number' | 'reference'>): string {
    return saleLabel(sale);
  }

  protected statusText(sale: VoidTarget): string {
    return sale.status.replaceAll('_', ' ');
  }

  protected onReason(event: Event): void {
    this.reason.set((event.target as HTMLInputElement).value);
    this.problem.set('');
    this.refusedClosed.set(false);
  }

  protected onSubmit(event: Event): void {
    event.preventDefault();
    void this.submit();
  }

  protected async submit(): Promise<void> {
    const sale = this.target();
    if (!sale || this.actions.busy()) {
      return;
    }
    const reason = this.reason().trim();
    if (reason === '') {
      this.problem.set('Give a reason for the void');
      return;
    }
    this.problem.set('');
    this.refusedClosed.set(false);
    const outcome = await this.actions.voidSale(sale, reason);
    switch (outcome.kind) {
      case 'voided':
        this.done.set(outcome.sale);
        return;
      case 'refused': {
        const message = describeError(outcome.error);
        this.problem.set(message.text);
        this.refusedClosed.set(message.code === 'shift_closed');
        return;
      }
      case 'rejected':
        this.problem.set('Approval rejected: the sale is unchanged');
        return;
      case 'cancelled':
        return;
    }
  }

  /** Picks a completed sale of this shift to void. */
  protected async find(): Promise<void> {
    const data: FindSaleDialogData = { statuses: ['completed'] };
    const chosen = (await this.dialogs.open('find-sale', data)) as SaleSummary | undefined;
    if (chosen) {
      this.picked.set(chosen);
      this.problem.set('');
      this.refusedClosed.set(false);
    }
  }

  /** The sale's shift is closed: leave this dialog and refund the sale instead (AC-57). */
  protected startRefund(sale: VoidTarget): void {
    const data: RefundDialogData = { saleId: sale.id };
    this.dialogs.close();
    void this.dialogs.open('refund', data);
  }
}
