import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  Injector,
  afterNextRender,
  effect,
  inject,
  viewChild,
} from '@angular/core';
import { MoneyPipe } from '../../format/money.pipe';
import { PaymentStore } from '../../state/payment-store';

/**
 * What the till shows after a sale completes (T-037 AC-47): the number, the total, the tenders
 * and the change due, and "New sale". Every figure is the completed sale's, as the server sent
 * it. "New sale" has the focus when the panel appears, so Enter starts the next sale; it clears
 * the screen and starts no draft (D-9). The panel goes by itself when another sale is in the cart.
 */
@Component({
  selector: 'pos-completion-panel',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [MoneyPipe],
  template: `
    @if (payments.completed(); as sale) {
      <section aria-labelledby="pos-completion-title" data-testid="completion">
        <h2 id="pos-completion-title">Sale complete</h2>
        <p role="status" data-testid="completion-number">Sale {{ sale.number }} completed</p>
        <dl class="figures">
          <div>
            <dt>Total</dt>
            <dd data-testid="completion-total">{{ sale.total | money: sale.currency }}</dd>
          </div>
          <div>
            <dt>Change due</dt>
            <dd data-testid="completion-change">{{ sale.change_due | money: sale.currency }}</dd>
          </div>
        </dl>
        <ul aria-label="Payments" data-testid="completion-tenders">
          @for (tender of sale.tenders; track tender.id) {
            <li>
              {{ tender.method_code }} {{ tender.amount | money: sale.currency }}
              @if (tender.reference) {
                <span class="fp-muted">Ref {{ tender.reference }}</span>
              }
            </li>
          }
        </ul>
        <button
          #newSale
          type="button"
          class="fp-primary"
          data-testid="new-sale"
          (click)="payments.startNewSale()"
        >
          New sale (Enter)
        </button>
      </section>
    }
  `,
  styles: `
    h2 {
      margin: 0 0 8px;
      font-size: 1.1rem;
    }

    .figures {
      display: grid;
      gap: 4px;
      margin: 0 0 8px;
    }

    .figures div {
      display: flex;
      justify-content: space-between;
      gap: 12px;
    }

    .figures dt,
    .figures dd {
      margin: 0;
    }

    ul {
      margin: 0 0 8px;
      padding-left: 18px;
    }

    button {
      min-height: 44px;
    }
  `,
})
export class CompletionPanel {
  protected readonly payments = inject(PaymentStore);
  private readonly injector = inject(Injector);
  private readonly newSale = viewChild<ElementRef<HTMLButtonElement>>('newSale');
  private shownFor: string | null = null;

  constructor() {
    // The button takes the focus once, when a completed sale first appears.
    effect(() => {
      const id = this.payments.completed()?.id ?? null;
      if (id !== null && id !== this.shownFor) {
        afterNextRender(() => this.newSale()?.nativeElement.focus(), { injector: this.injector });
      }
      this.shownFor = id;
    });
  }
}
