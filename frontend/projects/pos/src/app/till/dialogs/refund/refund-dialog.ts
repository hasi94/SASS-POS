import {
  ChangeDetectionStrategy,
  Component,
  OnDestroy,
  OnInit,
  inject,
  input,
} from '@angular/core';
import { SaleLine, SaleSummary, formatQuantity } from '@flexipos/shared';
import { MoneyPipe } from '../../format/money.pipe';
import { ProductSearch } from '../../panels/product-search/product-search';
import { Connectivity } from '../../state/connectivity';
import { DialogHost } from '../../state/dialog-host';
import {
  REFUND_WAITING,
  RefundOpenData,
  RefundStore,
  isFullyRefunded,
} from '../../state/refund-store';
import { FindSaleDialogData } from '../../state/void-actions';
import { DialogFrame } from '../../ui/dialog-frame';

/**
 * Refunds (T-037 AC-59 to AC-64): a referenced refund of a loaded sale, or a goodwill refund of
 * picked products. The dialog is the form; everything it decides lives in `RefundStore`, and every
 * price on screen is one the server quoted.
 */
@Component({
  selector: 'pos-refund-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame, MoneyPipe, ProductSearch],
  template: `
    <pos-dialog-frame heading="Refund">
      @if (store.result(); as done) {
        <div data-testid="refund-result">
          <p>
            Refund <strong data-testid="refund-number">{{ done.refund.number }}</strong> recorded
          </p>
          <ul class="plain" data-testid="refund-result-lines">
            @for (line of done.refund.lines; track line.id) {
              <li>
                {{ line.product_name }} &middot; {{ qty(line.quantity) }}
                {{ line.unit_code }} &middot; {{ line.line_total | money: done.refund.currency }}
              </li>
            }
          </ul>
          <p>
            Total
            <strong data-testid="refund-result-total">{{
              done.refund.total | money: done.refund.currency
            }}</strong>
          </p>
          <p data-testid="refund-original-status">
            @if (done.original; as original) {
              Sale {{ original.number }} is now {{ statusText(original.status) }}
            } @else {
              Goodwill refund: no original sale
            }
          </p>
          <div class="actions">
            <button type="button" class="primary" data-autofocus (click)="dialogs.close(done)">
              Close
            </button>
          </div>
        </div>
      } @else if (store.pending(); as waiting) {
        <div data-testid="refund-pending">
          <p class="fp-muted" data-testid="refund-waiting" role="status">{{ waitingText }}</p>
          <p data-testid="refund-pending-what">
            @if (waiting.view.saleLabel; as label) {
              Refund of sale {{ label }}
            } @else {
              Goodwill refund
            }
          </p>
          <ul class="plain" data-testid="refund-pending-lines">
            @for (line of waiting.view.quote.lines; track $index) {
              <li>
                {{ line.product_name }} &middot; {{ qty(line.quantity) }}
                {{ line.unit_code }} &middot;
                {{ line.line_total | money: waiting.view.quote.currency }}
              </li>
            }
          </ul>
          <p>
            Total
            <strong data-testid="refund-pending-total">{{
              waiting.view.quote.total | money: waiting.view.quote.currency
            }}</strong>
            @if (waiting.view.methodName; as method) {
              <span data-testid="refund-pending-method"> &middot; {{ method }}</span>
            }
          </p>
          <p class="problem" role="alert" data-testid="refund-pending-problem">
            {{ store.problem() }}
          </p>
          <div class="actions">
            <button
              type="button"
              class="primary"
              data-autofocus
              data-testid="refund-send-again"
              [disabled]="store.busy() || !online()"
              (click)="store.sendAgain()"
            >
              Send again
            </button>
            <button type="button" data-testid="refund-pending-close" (click)="dialogs.close()">
              Close
            </button>
          </div>
        </div>
      } @else {
        <div class="modes" role="group" aria-label="Kind of refund">
          <button
            type="button"
            data-testid="mode-referenced"
            [attr.aria-pressed]="store.mode() === 'referenced'"
            [disabled]="store.busy()"
            (click)="store.setMode('referenced')"
          >
            Refund a sale
          </button>
          <button
            type="button"
            data-testid="mode-goodwill"
            [attr.aria-pressed]="store.mode() === 'goodwill'"
            [disabled]="store.busy()"
            (click)="store.setMode('goodwill')"
          >
            Goodwill refund
          </button>
        </div>
        @if (store.mode() === 'goodwill') {
          <p class="fp-muted">Pick the products to refund. A supervisor approves the refund.</p>
          <pos-product-search mode="pick" (picked)="store.addGoodwill($event)" />
        } @else if (store.sale() === null) {
          <form class="search" (submit)="onFind($event)" novalidate>
            <label>
              Sale number
              <input name="number" type="text" autocomplete="off" data-autofocus />
            </label>
            <button type="submit" [disabled]="store.busy()">Find</button>
            <button type="button" [disabled]="store.busy()" (click)="pickFromShift()">
              Pick from this shift
            </button>
          </form>
        }

        <form class="refund" (submit)="onSubmit($event)" novalidate>
          @if (store.mode() === 'referenced' && store.sale(); as sale) {
            <p class="sale" data-testid="refund-sale">
              Sale <strong>{{ sale.number ?? sale.reference }}</strong> &middot;
              {{ sale.total | money: sale.currency }} &middot; {{ statusText(sale.status) }}
            </p>
            <ul class="plain" data-testid="refund-lines">
              @for (line of sale.lines; track line.id; let i = $index) {
                <li class="line" [attr.data-testid]="'refund-line-' + i">
                  <label class="pick">
                    <input
                      type="checkbox"
                      [attr.data-testid]="'refund-select-' + i"
                      [attr.aria-label]="'Refund ' + line.product_name"
                      [checked]="store.entries()[line.id]?.selected ?? false"
                      [disabled]="fully(line) || store.busy()"
                      (change)="onSelect(line, $event)"
                    />
                    {{ line.product_name }}
                  </label>
                  <span class="fp-muted" [attr.data-testid]="'refund-figures-' + i">
                    Sold {{ qty(line.quantity) }} {{ line.unit_code }} &middot; refunded
                    {{ qty(line.refunded_quantity) }} {{ line.base_unit_code }} &middot; left
                    {{ qty(line.refundable_quantity) }} {{ line.unit_code }}
                  </span>
                  @if (fully(line)) {
                    <span class="fully" [attr.data-testid]="'refund-fully-' + i">
                      Fully refunded
                    </span>
                  } @else if (store.entries()[line.id]?.selected) {
                    <label class="qty">
                      Quantity ({{ line.unit_code }})
                      <input
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        [attr.data-testid]="'refund-qty-' + i"
                        [value]="store.entries()[line.id]?.quantity ?? ''"
                        [disabled]="store.busy()"
                        [attr.aria-invalid]="store.lineProblems()[line.id] ? 'true' : null"
                        (input)="onQuantity(line, $event)"
                      />
                    </label>
                    @if (store.showProblems() && store.lineProblems()[line.id]; as why) {
                      <span
                        class="problem"
                        role="alert"
                        [attr.data-testid]="'refund-line-problem-' + i"
                      >
                        {{ why }}
                      </span>
                    }
                  }
                </li>
              }
            </ul>
          }

          @if (store.mode() === 'goodwill') {
            <ul class="plain" data-testid="refund-goodwill-lines">
              @for (line of store.goodwillLines(); track line.key; let i = $index) {
                <li class="line" [attr.data-testid]="'goodwill-line-' + i">
                  <span class="pick">{{ line.product.name }}</span>
                  <label class="qty">
                    Quantity ({{ line.product.unit_code }})
                    <input
                      type="text"
                      inputmode="decimal"
                      autocomplete="off"
                      [attr.data-testid]="'goodwill-qty-' + i"
                      [value]="line.quantity"
                      [disabled]="store.busy()"
                      [attr.aria-invalid]="store.goodwillProblems()[line.key] ? 'true' : null"
                      (input)="onGoodwillQuantity(line.key, $event)"
                    />
                  </label>
                  <button
                    type="button"
                    [attr.aria-label]="'Remove ' + line.product.name"
                    [disabled]="store.busy()"
                    (click)="store.removeGoodwill(line.key)"
                  >
                    Remove
                  </button>
                  @if (store.showProblems() && store.goodwillProblems()[line.key]; as why) {
                    <span class="problem" role="alert" [attr.data-testid]="'goodwill-problem-' + i">
                      {{ why }}
                    </span>
                  }
                </li>
              }
            </ul>
          }

          @if (store.quote(); as quote) {
            <section aria-label="Refund quote" data-testid="refund-quote">
              <ul class="plain">
                @for (line of quote.lines; track $index) {
                  <li>
                    {{ line.product_name }} &middot; {{ qty(line.quantity) }}
                    {{ line.unit_code }} &middot; {{ line.line_total | money: quote.currency }}
                  </li>
                }
              </ul>
              <p>
                Refund total
                <strong data-testid="refund-quote-total">{{
                  quote.total | money: quote.currency
                }}</strong>
              </p>
              @if (store.zeroTotal()) {
                <p data-testid="refund-no-tender">Nothing is owed back: no payout is needed.</p>
              } @else {
                <label>
                  Pay out by
                  <select
                    name="method"
                    data-testid="refund-method"
                    [disabled]="store.busy()"
                    (change)="onMethod($event)"
                  >
                    @for (method of store.refundMethods(); track method.id) {
                      <option [value]="method.id" [selected]="method.id === store.methodId()">
                        {{ method.name }}
                      </option>
                    }
                  </select>
                </label>
                <label>
                  Amount
                  <input
                    name="amount"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    data-testid="refund-amount"
                    [value]="store.amount()"
                    [disabled]="store.busy()"
                    (input)="onAmount($event)"
                  />
                </label>
                @if (store.method()?.requires_reference) {
                  <label>
                    Reference
                    <input
                      name="reference"
                      type="text"
                      autocomplete="off"
                      data-testid="refund-reference"
                      [value]="store.reference()"
                      [disabled]="store.busy()"
                      (input)="onReference($event)"
                    />
                  </label>
                }
              }
            </section>
          }

          <p class="problem" role="alert" data-testid="refund-problem">{{ store.problem() }}</p>

          <div class="actions">
            @if (hasSomethingToRefund()) {
              <button
                type="submit"
                class="primary"
                data-testid="refund-go"
                [disabled]="store.busy() || !online()"
              >
                {{ store.quote() ? 'Refund' : 'Price the refund' }}
              </button>
            }
            <button type="button" (click)="dialogs.close()">Cancel</button>
          </div>
        </form>
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

    input[type='text'],
    select {
      min-height: 44px;
      padding: 0 10px;
      font: inherit;
    }

    input[type='checkbox'] {
      width: 24px;
      height: 24px;
    }

    .search {
      display: flex;
      gap: 8px;
      align-items: end;
    }

    .search label {
      flex: 1;
    }

    .modes,
    .actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .modes button[aria-pressed='true'] {
      font-weight: 700;
      outline: 2px solid var(--fp-primary, #0b57d0);
    }

    .plain {
      list-style: none;
      margin: 0;
      padding: 0;
      display: grid;
      gap: 8px;
    }

    .line {
      display: grid;
      gap: 4px;
    }

    .pick {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .fully {
      font-weight: 600;
    }

    .problem {
      min-height: 1.4em;
      margin: 0;
      color: var(--fp-danger, #b00020);
    }

    .sale {
      margin: 0;
    }

    button {
      min-height: 44px;
      padding: 0 16px;
    }
  `,
})
export class RefundDialog implements OnInit, OnDestroy {
  protected readonly store = inject(RefundStore);
  protected readonly online = inject(Connectivity).online;
  protected readonly dialogs = inject(DialogHost);

  /** What the opener passed to `DialogHost.open`: optionally the sale to refund, or the mode. */
  readonly data = input<unknown>();

  protected readonly waitingText = REFUND_WAITING;

  ngOnInit(): void {
    void this.store.start(this.data() as RefundOpenData | undefined);
  }

  /** Closing the dialog (also by `closeAll` on sign-out or a branch switch) ends its session. */
  ngOnDestroy(): void {
    this.store.end();
  }

  protected qty(text: string): string {
    return formatQuantity(text);
  }

  protected statusText(status: string): string {
    return status.replaceAll('_', ' ');
  }

  protected fully(line: SaleLine): boolean {
    return isFullyRefunded(line);
  }

  /** There is something to price or send: a sale with a line chosen, or goodwill products. */
  protected hasSomethingToRefund(): boolean {
    return this.store.mode() === 'goodwill'
      ? this.store.goodwillLines().length > 0
      : this.store.sale() !== null;
  }

  protected onFind(event: Event): void {
    event.preventDefault();
    const field = (event.target as HTMLFormElement).elements.namedItem('number');
    void this.store.findByNumber(field instanceof HTMLInputElement ? field.value : '');
  }

  /** Picks one of this shift's sales that still has something to refund. */
  protected async pickFromShift(): Promise<void> {
    const data: FindSaleDialogData = { statuses: ['completed', 'partially_refunded'] };
    const chosen = (await this.dialogs.open('find-sale', data)) as SaleSummary | undefined;
    if (chosen) {
      await this.store.loadSale(chosen.id);
    }
  }

  protected onSelect(line: SaleLine, event: Event): void {
    this.store.selectLine(line, (event.target as HTMLInputElement).checked);
  }

  protected onQuantity(line: SaleLine, event: Event): void {
    this.store.setQuantity(line, (event.target as HTMLInputElement).value);
  }

  protected onGoodwillQuantity(key: number, event: Event): void {
    this.store.setGoodwillQuantity(key, (event.target as HTMLInputElement).value);
  }

  protected onMethod(event: Event): void {
    this.store.setMethod((event.target as HTMLSelectElement).value);
  }

  protected onAmount(event: Event): void {
    this.store.setAmount((event.target as HTMLInputElement).value);
  }

  protected onReference(event: Event): void {
    this.store.setReference((event.target as HTMLInputElement).value);
  }

  /** Enter or the button: price the refund first, then send it. */
  protected onSubmit(event: Event): void {
    event.preventDefault();
    if (this.store.quote()) {
      void this.store.submit();
    } else {
      void this.store.requestQuote();
    }
  }
}
