import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  Injector,
  afterNextRender,
  computed,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { PaymentMethod, compareDecimal } from '@flexipos/shared';
import { TillMessage, describeError } from '../../errors/refusal-catalogue';
import { MoneyPipe } from '../../format/money.pipe';
import { Connectivity } from '../../state/connectivity';
import { checkDecimalInput } from '../../state/decimal-input';
import { DialogHost } from '../../state/dialog-host';
import { PaymentOutcome, PaymentStore } from '../../state/payment-store';
import { SaleStore } from '../../state/sale-store';
import { ShiftStore } from '../../state/shift-store';
import { DialogFrame } from '../../ui/dialog-frame';

/** Why store credit cannot be picked (AC-40), and what the dialog says when the server agrees. */
export const CREDIT_NEEDS_CUSTOMER = 'Attach a customer to use store credit';
/** What the dialog says to an over-tender on a method that gives no change (AC-44). */
export const MORE_THAN_BALANCE = 'Amount is more than the balance';

/**
 * Takes the payment (T-037 AC-41 to AC-50). The methods are the server's list minus the types
 * the till cannot use. The amount defaults to the sale's `balance_due` string, copied and never
 * parsed; what is sent is the typed text and the method. The dialog shows what the server
 * answered (the tender, the paid and remaining figures, the change) and stays open after a part
 * payment. While anything is in flight its controls are off and Enter does nothing (AC-50).
 */
@Component({
  selector: 'pos-payment-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame, MoneyPipe],
  template: `
    <pos-dialog-frame heading="Payment">
      @if (sale(); as current) {
        <dl class="figures">
          <div>
            <dt>Total</dt>
            <dd data-testid="pay-total">{{ current.total | money: current.currency }}</dd>
          </div>
          <div>
            <dt>Paid</dt>
            <dd data-testid="pay-paid">{{ current.paid_total | money: current.currency }}</dd>
          </div>
          <div class="due">
            <dt>Balance due</dt>
            <dd data-testid="pay-balance">{{ current.balance_due | money: current.currency }}</dd>
          </div>
        </dl>

        @if (current.tenders.length > 0) {
          <ul class="tenders" aria-label="Payments on this sale" data-testid="tenders">
            @for (tender of current.tenders; track tender.id) {
              <li data-testid="tender">
                <span data-testid="tender-method">{{ tender.method_code }}</span>
                <span data-testid="tender-amount">{{
                  tender.amount | money: current.currency
                }}</span>
                @if (tender.reference) {
                  <span class="fp-muted">Ref {{ tender.reference }}</span>
                }
                @if (current.status === 'pending_payment') {
                  <button
                    type="button"
                    data-testid="tender-remove"
                    [disabled]="payments.busy() || !online()"
                    (click)="remove(tender.id)"
                  >
                    Remove
                  </button>
                }
              </li>
            }
          </ul>
        }

        @if (payments.change(); as change) {
          <p class="change" role="status" data-testid="payment-change">
            Change {{ change | money: current.currency }}
          </p>
        }

        @if (methodsProblem(); as why) {
          <p class="problem" role="alert" data-testid="payment-methods-problem">{{ why }}</p>
        }

        @if (!paidInFull()) {
          <form (submit)="onSubmit($event)" novalidate>
            <fieldset [disabled]="payments.busy()">
              <legend>Payment method</legend>
              <div class="methods">
                @for (method of payments.methods(); track method.id) {
                  <button
                    type="button"
                    class="method"
                    [attr.data-testid]="'method-' + method.code"
                    [attr.aria-pressed]="method.id === selected()?.id"
                    [disabled]="!usable(method)"
                    (click)="choose(method)"
                  >
                    {{ method.name }}
                  </button>
                }
              </div>
              @if (!hasCustomer() && hasCreditMethod()) {
                <p class="fp-muted" data-testid="credit-reason">{{ creditNeedsCustomer }}</p>
              }
            </fieldset>

            <label>
              Amount
              <input
                #amountField
                name="tendered"
                type="text"
                inputmode="decimal"
                autocomplete="off"
                data-autofocus
                data-testid="pay-amount"
                [value]="amount()"
                (input)="onAmount($event)"
              />
            </label>
            @if (selected()?.requires_reference) {
              <label>
                Reference
                <input
                  name="reference"
                  type="text"
                  autocomplete="off"
                  data-testid="pay-reference"
                  [value]="reference()"
                  (input)="onReference($event)"
                />
              </label>
            }
            <p class="problem" role="alert" data-testid="payment-problem">{{ problem() }}</p>
            <div class="actions">
              <button
                type="submit"
                class="primary"
                data-testid="pay-submit"
                [disabled]="payments.busy() || !online() || selected() === null"
              >
                Add payment
              </button>
              @if (!hasCustomer() && hasCreditMethod()) {
                <button type="button" data-testid="pay-customer" (click)="chooseCustomer()">
                  Choose a customer
                </button>
              }
              <button type="button" (click)="dialogs.close()">Close</button>
            </div>
          </form>
        } @else {
          <p class="problem" role="alert" data-testid="payment-problem">{{ problem() }}</p>
          <div class="actions">
            <button
              #completeButton
              type="button"
              class="primary"
              data-autofocus
              data-testid="pay-complete"
              [disabled]="payments.busy() || !online()"
              (click)="complete()"
            >
              Complete sale
            </button>
            <button type="button" (click)="dialogs.close()">Close</button>
          </div>
        }
      } @else {
        <p data-testid="pay-no-sale">There is no sale to pay.</p>
        <div class="actions">
          <button type="button" data-autofocus (click)="dialogs.close()">Close</button>
        </div>
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

    fieldset {
      margin: 0;
      padding: 8px;
      border: 1px solid var(--fp-border);
      border-radius: var(--fp-radius);
    }

    .figures {
      display: grid;
      gap: 4px;
      margin: 0;
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

    .due {
      font-size: 1.15rem;
      font-weight: 700;
    }

    .tenders {
      list-style: none;
      margin: 0;
      padding: 0;
      display: grid;
      gap: 4px;
    }

    .tenders li {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .methods,
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .method[aria-pressed='true'] {
      border: 3px solid var(--fp-primary);
      font-weight: 700;
    }

    .change {
      margin: 0;
      font-size: 1.4rem;
      font-weight: 700;
    }

    .problem {
      min-height: 1.4em;
      margin: 0;
      color: var(--fp-danger, #b00020);
    }

    button {
      min-height: 44px;
      padding: 0 16px;
    }
  `,
})
export class PaymentDialog {
  protected readonly dialogs = inject(DialogHost);
  protected readonly payments = inject(PaymentStore);
  private readonly cart = inject(SaleStore);
  private readonly shift = inject(ShiftStore);
  private readonly connectivity = inject(Connectivity);
  private readonly injector = inject(Injector);
  private readonly amountField = viewChild<ElementRef<HTMLInputElement>>('amountField');
  private readonly completeButton = viewChild<ElementRef<HTMLButtonElement>>('completeButton');

  /** What the opener passed to `DialogHost.open`. */
  readonly data = input<unknown>();

  protected readonly creditNeedsCustomer = CREDIT_NEEDS_CUSTOMER;
  protected readonly sale = this.cart.current;
  protected readonly online = this.connectivity.online;
  protected readonly problem = signal('');
  protected readonly methodsProblem = signal('');
  private readonly chosen = signal<string | null>(null);
  private readonly typedAmount = signal<string | null>(null);
  protected readonly reference = signal('');

  protected readonly hasCustomer = computed(() => this.sale()?.customer != null);
  protected readonly hasCreditMethod = computed(() =>
    this.payments.methods().some((m) => m.type === 'credit'),
  );
  /** The method the amount is for: the cashier's pick, else the first one that can be used. */
  protected readonly selected = computed<PaymentMethod | null>(() => {
    const methods = this.payments.methods();
    return (
      methods.find((m) => m.id === this.chosen() && this.usable(m)) ??
      methods.find((m) => this.usable(m)) ??
      null
    );
  });
  /** The amount shown: what the cashier typed, else the server's `balance_due` string, copied. */
  protected readonly amount = computed(() => this.typedAmount() ?? this.sale()?.balance_due ?? '');
  /** True when nothing more is owed, so the sale can be completed (AC-47). */
  protected readonly paidInFull = computed(() => {
    const sale = this.sale();
    return sale !== null && sale.lines.length > 0 && compareDecimal(sale.balance_due, '0') === 0;
  });

  constructor() {
    this.payments.clearChange();
    void this.payments.loadMethods().then((error) => {
      if (error !== null) {
        this.methodsProblem.set(this.textOf(describeError(error)));
      }
    });
  }

  protected usable(method: PaymentMethod): boolean {
    return method.type !== 'credit' || this.hasCustomer();
  }

  protected choose(method: PaymentMethod): void {
    this.chosen.set(method.id);
    this.problem.set('');
    this.focusAmount();
  }

  protected onAmount(event: Event): void {
    this.typedAmount.set((event.target as HTMLInputElement).value);
    this.problem.set('');
  }

  protected onReference(event: Event): void {
    this.reference.set((event.target as HTMLInputElement).value);
    this.problem.set('');
  }

  protected onSubmit(event: Event): void {
    event.preventDefault();
    void this.submit();
  }

  /** Sends the tender as typed, once the form's own checks pass (AC-42, AC-43). */
  protected async submit(): Promise<void> {
    const method = this.selected();
    if (method === null || this.payments.busy()) {
      return;
    }
    const tendered = this.amount().trim();
    const problem = checkDecimalInput(tendered, { label: 'Amount', allowZero: false });
    if (problem !== null) {
      this.problem.set(problem);
      return;
    }
    const reference = this.reference().trim();
    if (method.requires_reference && reference === '') {
      this.problem.set('Reference is required for this payment method');
      return;
    }
    this.problem.set('');
    const outcome = await this.payments.addTender({
      method,
      tendered,
      reference: method.requires_reference ? reference : null,
    });
    if (outcome.kind === 'done') {
      // A part payment leaves the dialog open on the amount, ready for the next tender (AC-45).
      this.typedAmount.set(null);
      this.reference.set('');
      this.focusAmount();
    } else {
      this.showFailure(outcome);
    }
  }

  protected async remove(tenderId: string): Promise<void> {
    this.problem.set('');
    const outcome = await this.payments.removeTender(tenderId);
    if (outcome.kind === 'done') {
      this.typedAmount.set(null);
    } else {
      this.showFailure(outcome);
    }
  }

  protected async complete(): Promise<void> {
    this.problem.set('');
    const outcome = await this.payments.complete();
    if (outcome.kind === 'done') {
      this.dialogs.close();
    } else {
      this.showFailure(outcome);
    }
  }

  /** Opens Customer, so a store credit payment can go ahead (AC-40). */
  protected async chooseCustomer(): Promise<void> {
    await this.dialogs.open('customer');
  }

  private showFailure(outcome: PaymentOutcome): void {
    if (outcome.kind !== 'failed') {
      return;
    }
    if (!this.shift.shiftOpen()) {
      // The shift is gone: the shift prompt takes over (AC-15), the cart stays as it was.
      this.dialogs.close();
      return;
    }
    this.problem.set(this.textOf(describeError(outcome.error)));
  }

  /** The words for a refusal: the catalogue's, with the two the payment spec words itself. */
  private textOf(message: TillMessage): string {
    if (message.code === 'exceeds_balance') {
      return MORE_THAN_BALANCE;
    }
    if (message.code === 'customer_required') {
      return CREDIT_NEEDS_CUSTOMER;
    }
    return message.detail ? `${message.text} (${message.detail})` : message.text;
  }

  /** Once the view has caught up: the amount for the next tender, or Complete when all is paid. */
  private focusAmount(): void {
    afterNextRender(() => (this.completeButton() ?? this.amountField())?.nativeElement.focus(), {
      injector: this.injector,
    });
  }
}
