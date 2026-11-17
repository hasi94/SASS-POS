import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  Injector,
  afterNextRender,
  computed,
  effect,
  inject,
  viewChild,
} from '@angular/core';
import { SaleLine, compareDecimal } from '@flexipos/shared';
import { MoneyPipe } from '../../format/money.pipe';
import { QuantityPipe } from '../../format/quantity.pipe';
import { KEYMAP, KeymapContext, TillAction } from '../../keyboard/keymap';
import { Connectivity } from '../../state/connectivity';
import { DialogHost } from '../../state/dialog-host';
import { SaleStore } from '../../state/sale-store';
import { ScanQueue } from '../../state/scan-queue';
import { ShiftStore } from '../../state/shift-store';

const OVERRIDDEN_NO_DISCOUNT = 'A line with a price override cannot take a discount';
const OFFLINE = 'Cannot reach the server: sale actions are paused';

/**
 * The cart (T-037 AC-29 to AC-38). Every figure on it is the server's string (D-6): the lines
 * with their unit price, discount, tax and total, and the footer totals, with the paid and
 * balance figures once payment has started. It is a labelled list region, and the number of
 * lines is announced when it changes. Up and Down select a line (the page's key map); the actions
 * for the selected line sit under the list, and are disabled with their reason when payment has
 * started or the network is down.
 */
@Component({
  selector: 'pos-cart',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [MoneyPipe, QuantityPipe],
  template: `
    <section aria-labelledby="pos-cart-title">
      <h2 id="pos-cart-title">Cart</h2>
      <p class="sr-only" role="status" aria-live="polite" data-testid="cart-count">
        {{ countText() }}
      </p>
      @if (sale.current(); as current) {
        @if (current.reference) {
          <p class="fp-muted" data-testid="cart-reference">
            Sale {{ current.reference }} ({{ statusLabel() }})
          </p>
        }
      }
      @if (sale.lineCount() === 0 && scans.pending().length === 0) {
        <p class="fp-muted" data-testid="cart-empty">Scan or choose a product</p>
      }
      <ul class="lines" aria-label="Cart lines" data-testid="cart-lines">
        @for (line of sale.lines(); track line.id) {
          <li
            class="line"
            data-testid="cart-line"
            [class.selected]="line.id === sale.selectedLineId()"
            [attr.aria-current]="line.id === sale.selectedLineId() ? 'true' : null"
            [attr.data-line-id]="line.id"
          >
            <button
              type="button"
              class="pick"
              data-testid="line-name"
              (click)="sale.select(line.id)"
            >
              {{ line.product_name }}
            </button>
            <span class="qty" data-testid="line-quantity">
              {{ line.quantity | quantity: line.unit_code }}
            </span>
            <span class="price" data-testid="line-price">
              Price {{ line.unit_price | money: currency() }}
            </span>
            @if (isZero(line.unit_price)) {
              <span class="marker" data-testid="zero-price">Zero price</span>
            }
            @if (line.price_overridden) {
              <span class="marker" data-testid="price-overridden">Price changed</span>
            }
            @if (!isZero(line.discount_amount)) {
              <span class="discount" data-testid="line-discount">
                Discount {{ line.discount_amount | money: currency() }}
              </span>
            }
            <span class="tax" data-testid="line-tax"
              >Tax {{ line.tax_amount | money: currency() }}</span
            >
            <strong class="total" data-testid="line-total">
              {{ line.line_total | money: currency() }}
            </strong>
          </li>
        }
        @for (scan of scans.pending(); track scan.key) {
          <li class="line pending" data-testid="pending-scan">Adding {{ scan.code }}</li>
        }
      </ul>

      @if (sale.confirmingRemoval()) {
        <div
          class="confirm"
          role="group"
          aria-label="Confirm removal"
          data-testid="confirm-removal"
          (keydown.escape)="keepLine($event)"
        >
          <span>Remove {{ confirmingName() }}?</span>
          <button
            #confirm
            type="button"
            class="fp-primary"
            data-testid="confirm-remove"
            (click)="removeConfirmed()"
          >
            Remove (Enter)
          </button>
          <button type="button" data-testid="keep-line" (click)="keepLine($event)">
            Keep (Esc)
          </button>
        </div>
      }

      @if (selectedLine(); as line) {
        <div
          class="actions"
          role="group"
          aria-label="Actions for the selected line"
          data-testid="line-actions"
        >
          <span class="selected-name">Selected: {{ line.product_name }}</span>
          <button
            type="button"
            data-testid="action-edit"
            [disabled]="!!lineBlocked()"
            (click)="open('line-edit', line)"
          >
            Change quantity or unit
          </button>
          <button
            type="button"
            data-testid="action-override"
            [disabled]="!!lineBlocked()"
            (click)="open('price-override', line)"
          >
            Price override
          </button>
          <button
            type="button"
            data-testid="action-discount"
            [disabled]="!!lineBlocked() || line.price_overridden"
            (click)="open('discount-picker', line)"
          >
            Discount
          </button>
          <button
            type="button"
            data-testid="action-remove"
            [disabled]="!!lineBlocked()"
            (click)="sale.requestRemoval(line.id)"
          >
            Remove
          </button>
          @if (lineBlocked() ?? (line.price_overridden ? overriddenReason : null); as why) {
            <p class="reason" data-testid="line-reason">{{ why }}</p>
          }
        </div>
      }

      @if (sale.current(); as current) {
        <dl class="totals" data-testid="totals">
          <div>
            <dt>Subtotal</dt>
            <dd data-testid="total-subtotal">{{ current.subtotal | money: current.currency }}</dd>
          </div>
          <div>
            <dt>Discount</dt>
            <dd data-testid="total-discount">
              {{ current.discount_total | money: current.currency }}
            </dd>
          </div>
          <div>
            <dt>Tax</dt>
            <dd data-testid="total-tax">{{ current.tax_total | money: current.currency }}</dd>
          </div>
          <div class="grand">
            <dt>Total</dt>
            <dd data-testid="total-total">{{ current.total | money: current.currency }}</dd>
          </div>
          @if (paymentStarted()) {
            <div>
              <dt>Paid</dt>
              <dd data-testid="total-paid">{{ current.paid_total | money: current.currency }}</dd>
            </div>
            <div>
              <dt>Balance due</dt>
              <dd data-testid="total-balance">
                {{ current.balance_due | money: current.currency }}
              </dd>
            </div>
          }
        </dl>
      }

      <div class="buttons">
        <button
          type="button"
          class="fp-primary"
          data-testid="cart-pay"
          [disabled]="!!payReason()"
          [attr.aria-describedby]="payReason() ? 'pos-cart-reason' : null"
          (click)="pay()"
        >
          Pay (F2)
        </button>
        <button
          type="button"
          data-testid="cart-hold"
          [disabled]="!!holdReason()"
          [attr.aria-describedby]="holdReason() ? 'pos-cart-reason' : null"
          (click)="hold()"
        >
          Hold (F3)
        </button>
      </div>
      @if (payReason() ?? holdReason(); as why) {
        <p class="fp-muted" id="pos-cart-reason" data-testid="cart-reason">{{ why }}</p>
      }
    </section>
  `,
  styles: `
    h2 {
      margin: 0 0 8px;
      font-size: 1.1rem;
    }

    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip-path: inset(50%);
      white-space: nowrap;
    }

    .lines {
      list-style: none;
      margin: 0 0 8px;
      padding: 0;
      max-height: 45vh;
      overflow: auto;
    }

    .line {
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      gap: 4px 10px;
      padding: 6px 8px;
      border: 2px solid transparent;
      border-bottom-color: var(--fp-border);
    }

    .line.selected {
      border-color: var(--fp-primary);
      border-radius: var(--fp-radius);
      font-weight: 600;
    }

    .pick {
      flex: 1 1 100%;
      min-height: 44px;
      text-align: left;
      border: 0;
      background: none;
      font-weight: 600;
    }

    .total {
      margin-left: auto;
    }

    .marker {
      padding: 0 6px;
      border: 1px solid var(--fp-danger);
      border-radius: 4px;
      color: var(--fp-danger);
      font-size: 0.8rem;
      font-weight: 700;
    }

    .tax,
    .discount {
      color: var(--fp-muted);
      font-size: 0.85rem;
    }

    .pending {
      font-style: italic;
      color: var(--fp-muted);
    }

    .confirm,
    .actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
      margin-bottom: 8px;
      padding: 8px;
      border: 1px solid var(--fp-border);
      border-radius: var(--fp-radius);
    }

    .selected-name {
      flex: 1 1 100%;
      font-weight: 600;
    }

    .reason {
      flex: 1 1 100%;
      margin: 0;
      color: var(--fp-danger);
      font-size: 0.875rem;
    }

    .totals {
      margin: 0 0 8px;
      display: grid;
      gap: 4px;
    }

    .totals div {
      display: flex;
      justify-content: space-between;
      gap: 12px;
    }

    .totals dt,
    .totals dd {
      margin: 0;
    }

    .grand {
      font-size: 1.15rem;
      font-weight: 700;
    }

    .buttons {
      display: flex;
      gap: 8px;
    }

    button {
      min-height: 44px;
    }
  `,
})
export class Cart {
  protected readonly sale = inject(SaleStore);
  protected readonly scans = inject(ScanQueue);
  private readonly shift = inject(ShiftStore);
  private readonly dialogs = inject(DialogHost);
  private readonly connectivity = inject(Connectivity);
  private readonly injector = inject(Injector);
  private readonly confirmButton = viewChild<ElementRef<HTMLElement>>('confirm');
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);

  protected readonly overriddenReason = OVERRIDDEN_NO_DISCOUNT;
  protected readonly currency = computed(() => this.sale.current()?.currency ?? '');
  protected readonly selectedLine = computed<SaleLine | null>(
    () => this.sale.lines().find((l) => l.id === this.sale.selectedLineId()) ?? null,
  );
  protected readonly countText = computed(() => {
    const count = this.sale.lineCount();
    return `${count} ${count === 1 ? 'line' : 'lines'} in the cart`;
  });
  protected readonly statusLabel = computed(() => {
    const status = this.sale.status();
    return status === 'pending_payment' ? 'part paid' : (status ?? '');
  });
  protected readonly paymentStarted = computed(() => {
    const sale = this.sale.current();
    return (
      sale !== null &&
      (sale.status === 'pending_payment' ||
        (sale.status === 'held' && sale.held_from_status === 'pending_payment'))
    );
  });
  protected readonly confirmingName = computed(
    () => this.sale.lines().find((l) => l.id === this.sale.confirmingRemoval())?.product_name ?? '',
  );
  /** Why the line actions are off now (payment started, or no network), or null. */
  protected readonly lineBlocked = computed<string | null>(() => {
    if (!this.connectivity.online()) {
      return OFFLINE;
    }
    return this.sale.lockReason();
  });
  protected readonly payReason = computed(() => this.reasonFor('pay'));
  protected readonly holdReason = computed(() => this.reasonFor('hold'));

  constructor() {
    // The confirmation takes the focus, so Enter confirms and Esc keeps the line (AC-32).
    effect(() => {
      if (this.sale.confirmingRemoval() !== null) {
        afterNextRender(() => this.confirmButton()?.nativeElement.focus(), {
          injector: this.injector,
        });
      }
    });
    // The selected line stays in view when the arrows move through a long cart.
    effect(() => {
      if (this.sale.selectedLineId() !== null) {
        afterNextRender(
          () =>
            this.host.nativeElement
              .querySelector<HTMLElement>('.line[aria-current="true"]')
              ?.scrollIntoView?.({ block: 'nearest' }),
          { injector: this.injector },
        );
      }
    });
  }

  protected isZero(value: string): boolean {
    return compareDecimal(value, '0') === 0;
  }

  private reasonFor(action: TillAction): string | null {
    if (!this.connectivity.online()) {
      return OFFLINE;
    }
    const context: KeymapContext = {
      shiftOpen: this.shift.shiftOpen(),
      lineCount: this.sale.lineCount(),
      hasSelectedLine: this.sale.selectedLineId() !== null,
      saleStatus: this.sale.status(),
      dialogOpen: false,
    };
    return KEYMAP.find((entry) => entry.action === action)?.available(context) ?? null;
  }

  protected open(kind: 'line-edit' | 'price-override' | 'discount-picker', line: SaleLine): void {
    void this.dialogs.open(kind, { lineId: line.id }, null);
  }

  protected pay(): void {
    void this.dialogs.open('payment');
  }

  protected hold(): void {
    void this.sale.hold();
  }

  protected async removeConfirmed(): Promise<void> {
    await this.sale.confirmRemoval();
    this.dialogs.focusScanField();
  }

  protected keepLine(event: Event): void {
    event.preventDefault();
    this.sale.cancelRemoval();
    this.dialogs.focusScanField();
  }
}
