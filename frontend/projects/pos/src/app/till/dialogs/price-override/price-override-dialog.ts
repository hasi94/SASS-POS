import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { SaleLine, asMoney } from '@flexipos/shared';
import { ApprovalFlow } from '../../state/approval-flow';
import { checkDecimalInput } from '../../state/decimal-input';
import { DialogHost } from '../../state/dialog-host';
import { Notifier } from '../../state/notifier';
import { SaleStore } from '../../state/sale-store';
import { DialogFrame } from '../../ui/dialog-frame';
import { isLineDialogData } from '../line-edit/line-edit-dialog';

/**
 * A price override on one line (T-037 AC-34, D-30). The cashier types the new unit price as a
 * string; a supervisor approves an approval bound to `{product_id, unit_id, unit_price}` on this
 * sale; the update then carries the approval id. The till never works out a price: the server
 * answers with the repriced line, or refuses (`price_override_not_allowed`,
 * `discount_with_override`, a stale approval), and the refusal is shown with the sale unchanged.
 */
@Component({
  selector: 'pos-price-override-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame],
  template: `
    <pos-dialog-frame heading="Price override">
      @if (line(); as current) {
        <form (submit)="submit($event)" novalidate>
          <p data-testid="override-product">{{ current.product_name }}</p>
          <p class="fp-muted">A supervisor must approve a different price.</p>
          <label class="field">
            <span>New unit price</span>
            <input
              #price
              data-autofocus
              type="text"
              inputmode="decimal"
              autocomplete="off"
              data-testid="override-price"
              [attr.aria-invalid]="error() ? 'true' : null"
              (input)="error.set(null)"
              (keydown.enter)="submit($event)"
            />
          </label>
          @if (error(); as message) {
            <p class="fp-error" role="alert" data-testid="override-error">{{ message }}</p>
          }
          <div class="actions">
            <button type="submit" class="fp-primary" data-testid="override-ask" [disabled]="busy()">
              Ask a supervisor
            </button>
            <button type="button" (click)="dialogs.close()">Cancel</button>
          </div>
        </form>
      } @else {
        <p class="fp-muted">That line is no longer in the cart.</p>
        <button type="button" data-autofocus (click)="dialogs.close()">Close</button>
      }
    </pos-dialog-frame>
  `,
  styles: `
    form {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    p {
      margin: 0;
    }

    .field {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    input {
      font: inherit;
      min-height: 44px;
      padding: 8px 12px;
      border: 1px solid var(--fp-border);
      border-radius: var(--fp-radius);
      background: var(--fp-bg);
      color: var(--fp-text);
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
export class PriceOverrideDialog {
  protected readonly dialogs = inject(DialogHost);
  private readonly store = inject(SaleStore);
  private readonly approvals = inject(ApprovalFlow);
  private readonly notifier = inject(Notifier);
  private readonly price = viewChild<ElementRef<HTMLInputElement>>('price');
  /** What the opener passed to `DialogHost.open`. */
  readonly data = input<unknown>();

  protected readonly error = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly line = computed<SaleLine | null>(() => {
    const data = this.data();
    return isLineDialogData(data)
      ? (this.store.lines().find((l) => l.id === data.lineId) ?? null)
      : null;
  });

  protected async submit(event: Event): Promise<void> {
    event.preventDefault();
    const line = this.line();
    const sale = this.store.current();
    const box = this.price();
    if (!line || !sale || !box || this.busy()) {
      return;
    }
    const text = box.nativeElement.value.trim();
    const problem = checkDecimalInput(text, { label: 'Unit price', allowZero: true });
    if (problem !== null) {
      this.error.set(problem);
      return;
    }
    const unitPrice = asMoney(text);
    this.busy.set(true);
    try {
      const outcome = await this.approvals.requestAndDecide(
        {
          type: 'price_override',
          reference_type: 'sale',
          reference_id: sale.id,
          payload: { product_id: line.product_id, unit_id: line.unit_id, unit_price: unitPrice },
        },
        {
          title: 'Price override',
          lines: [line.product_name, `New unit price ${text}`],
        },
      );
      if (outcome.kind === 'rejected') {
        this.notifier.info('Approval rejected');
        this.dialogs.close();
      } else if (outcome.kind === 'approved') {
        const result = await this.store.updateLine(line.id, {
          unit_price: unitPrice,
          approval_id: outcome.approvalId,
        });
        if (result.ok) {
          this.dialogs.close();
        }
      }
    } finally {
      this.busy.set(false);
    }
  }
}
