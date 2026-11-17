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
} from '@angular/core';
import {
  CASH_MOVEMENT_TYPES,
  CashMovementType,
  ClosedShift,
  SaleSummary,
  SalesApi,
} from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { MoneyPipe } from '../../format/money.pipe';
import { Connectivity } from '../../state/connectivity';
import { checkDecimalInput } from '../../state/decimal-input';
import { DialogHost } from '../../state/dialog-host';
import { Notifier } from '../../state/notifier';
import { SaleStore } from '../../state/sale-store';
import { ShiftStore } from '../../state/shift-store';
import { DialogFrame } from '../../ui/dialog-frame';

type View = 'menu' | 'movement' | 'close' | 'closed' | 'open-sales';

interface MovementKind {
  type: CashMovementType;
  label: string;
  /** What the type does to the drawer: its sign (AC-12). */
  hint: string;
}

const ADDS = 'Adds to the drawer';
const TAKES = 'Takes from the drawer';

const MOVEMENT_KINDS: readonly MovementKind[] = CASH_MOVEMENT_TYPES.map((type) => ({
  type,
  label: {
    cash_in: 'Cash in',
    cash_out: 'Cash out',
    float_in: 'Float in',
    float_out: 'Float out',
    drop: 'Drop',
    petty_cash: 'Petty cash',
    bank_deposit: 'Bank deposit',
  }[type],
  hint: type === 'cash_in' || type === 'float_in' ? ADDS : TAKES,
}));

/**
 * The shift menu (T-037 AC-12 to AC-14): record a cash movement, close the shift with a blind
 * count and read the server's figures, and deal with the sales that keep a shift from closing.
 * Everything the dialog shows after a close is the server's string, as received.
 */
@Component({
  selector: 'pos-shift-menu-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame, MoneyPipe],
  template: `
    <pos-dialog-frame heading="Shift">
      @switch (view()) {
        @case ('menu') {
          @if (shift.current(); as current) {
            <p class="fp-muted" data-testid="shift-summary">
              {{ current.number }}, opened with {{ current.opening_cash | money }}
            </p>
          } @else {
            <p class="fp-muted">There is no open shift.</p>
          }
          <div class="actions">
            <button
              type="button"
              data-autofocus
              data-testid="menu-movement"
              (click)="go('movement')"
              [disabled]="!shift.shiftOpen()"
            >
              Record a cash movement
            </button>
            <button
              type="button"
              data-testid="menu-close"
              (click)="go('close')"
              [disabled]="!shift.shiftOpen()"
            >
              Close the shift
            </button>
            <button type="button" (click)="dialogs.close()">Done</button>
          </div>
        }
        @case ('movement') {
          <form (submit)="submitMovement($event)" novalidate>
            <label class="field">
              <span>Type</span>
              <select
                data-autofocus
                data-testid="movement-type"
                (change)="type.set(selectedType($event))"
              >
                @for (kind of kinds; track kind.type) {
                  <option [value]="kind.type" [selected]="kind.type === type()">
                    {{ kind.label }}
                  </option>
                }
              </select>
            </label>
            <p class="fp-muted" data-testid="movement-hint">{{ hint() }}</p>
            <label class="field">
              <span>Amount</span>
              <input
                type="text"
                inputmode="decimal"
                autocomplete="off"
                data-testid="movement-amount"
                (input)="error.set(null)"
              />
            </label>
            <label class="field">
              <span>Reason</span>
              <input
                type="text"
                autocomplete="off"
                data-testid="movement-reason"
                (input)="error.set(null)"
              />
            </label>
            @if (error(); as message) {
              <p class="fp-error" role="alert" data-testid="shift-form-error">{{ message }}</p>
            }
            <div class="actions">
              <button type="submit" class="fp-primary" [disabled]="shift.busy() || !online()">
                Record
              </button>
              <button type="button" (click)="go('menu')">Back</button>
            </div>
          </form>
        }
        @case ('close') {
          <form (submit)="submitClose($event)" novalidate>
            <p class="fp-muted">
              Count the cash in the drawer. The expected figure is shown after you close.
            </p>
            <label class="field">
              <span>Counted cash</span>
              <input
                data-autofocus
                type="text"
                inputmode="decimal"
                autocomplete="off"
                data-testid="counted-cash"
                (input)="error.set(null)"
              />
            </label>
            @if (error(); as message) {
              <p class="fp-error" role="alert" data-testid="shift-form-error">{{ message }}</p>
            }
            <div class="actions">
              <button type="submit" class="fp-primary" [disabled]="shift.busy() || !online()">
                Close the shift
              </button>
              <button type="button" (click)="go('menu')">Back</button>
            </div>
          </form>
        }
        @case ('closed') {
          @if (closed(); as result) {
            <dl class="figures" data-testid="closed-figures">
              <div>
                <dt>Expected cash</dt>
                <dd data-testid="expected-cash">{{ result.expected_cash | money }}</dd>
              </div>
              <div>
                <dt>Counted cash</dt>
                <dd data-testid="counted-figure">{{ result.counted_cash | money }}</dd>
              </div>
              <div>
                <dt>Variance</dt>
                <dd data-testid="variance">{{ result.variance | money }}</dd>
              </div>
              <div>
                <dt>Sales</dt>
                <dd data-testid="sales-count">{{ result.sales_count }}</dd>
              </div>
              <div>
                <dt>Refunds</dt>
                <dd data-testid="refunds-count">{{ result.refunds_count }}</dd>
              </div>
              <div>
                <dt>Net</dt>
                <dd data-testid="net">{{ result.net | money }}</dd>
              </div>
              @for (method of methods(); track method.code) {
                <div>
                  <dt>{{ method.code }}</dt>
                  <dd [attr.data-testid]="'method-' + method.code">{{ method.amount | money }}</dd>
                </div>
              }
            </dl>
          }
          <div class="actions">
            <button type="button" data-autofocus (click)="dialogs.close()">Done</button>
          </div>
        }
        @case ('open-sales') {
          <p>These sales are still open. Finish or void each one, then close the shift again.</p>
          @if (shift.openSales().length === 0) {
            <p class="fp-muted" data-testid="no-open-sales">No open sales are left.</p>
          }
          <ul class="sales" data-testid="open-sales">
            @for (sale of shift.openSales(); track sale.id) {
              <li [attr.data-testid]="'open-sale-' + sale.id">
                <span class="what">
                  {{ sale.reference ?? sale.number }} ({{
                    sale.status === 'draft' ? 'Draft' : 'Part paid'
                  }}, {{ sale.line_count }} lines, {{ sale.total | money: sale.currency }})
                </span>
                <span class="row-actions">
                  <button type="button" data-testid="sale-open" (click)="openSale(sale)">
                    Open
                  </button>
                  <button type="button" data-testid="sale-complete" (click)="completeSale(sale)">
                    Complete
                  </button>
                  <button type="button" data-testid="sale-hold" (click)="holdSale(sale)">
                    Hold
                  </button>
                  <button type="button" data-testid="sale-void" (click)="voidSale(sale)">
                    Void
                  </button>
                </span>
              </li>
            }
          </ul>
          <div class="actions">
            <button type="button" data-autofocus (click)="go('close')">Back to closing</button>
          </div>
        }
      }
    </pos-dialog-frame>
  `,
  styles: `
    form,
    .field {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .field {
      gap: 4px;
    }

    input,
    select {
      font: inherit;
      min-height: 44px;
      padding: 8px 12px;
      border: 1px solid var(--fp-border);
      border-radius: var(--fp-radius);
      background: var(--fp-bg);
      color: var(--fp-text);
    }

    .actions,
    .row-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    button {
      min-height: 44px;
    }

    .figures {
      margin: 0;
      display: grid;
      gap: 6px;
    }

    .figures div {
      display: flex;
      justify-content: space-between;
      gap: 12px;
    }

    dt,
    dd {
      margin: 0;
    }

    .sales {
      list-style: none;
      margin: 0;
      padding: 0;
      display: grid;
      gap: 8px;
    }

    .sales li {
      display: grid;
      gap: 6px;
      padding: 8px;
      border: 1px solid var(--fp-border);
      border-radius: var(--fp-radius);
    }
  `,
})
export class ShiftMenuDialog {
  protected readonly dialogs = inject(DialogHost);
  protected readonly shift = inject(ShiftStore);
  private readonly sale = inject(SaleStore);
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  protected readonly online = this.connectivity.online;
  private readonly notifier = inject(Notifier);
  private readonly injector = inject(Injector);
  private readonly element = inject<ElementRef<HTMLElement>>(ElementRef);

  /** What the opener passed to `DialogHost.open`. */
  readonly data = input<unknown>();

  protected readonly kinds = MOVEMENT_KINDS;
  protected readonly view = signal<View>('menu');
  protected readonly type = signal<CashMovementType>('cash_in');
  protected readonly error = signal<string | null>(null);
  protected readonly closed = signal<ClosedShift | null>(null);
  protected readonly hint = computed(
    () => MOVEMENT_KINDS.find((k) => k.type === this.type())?.hint ?? '',
  );
  protected readonly methods = computed(() =>
    Object.entries(this.closed()?.totals_by_method ?? {}).map(([code, amount]) => ({
      code,
      amount,
    })),
  );

  protected go(view: View): void {
    this.error.set(null);
    this.view.set(view);
    // The frame focuses the first control once, when it opens; each view focuses its own.
    afterNextRender(
      () => this.element.nativeElement.querySelector<HTMLElement>('[data-autofocus]')?.focus(),
      { injector: this.injector },
    );
  }

  protected selectedType(event: Event): CashMovementType {
    const value = (event.target as HTMLSelectElement).value;
    return CASH_MOVEMENT_TYPES.find((t) => t === value) ?? 'cash_in';
  }

  private field(testId: string): string {
    const input = this.element.nativeElement.querySelector<HTMLInputElement>(
      `[data-testid="${testId}"]`,
    );
    return input?.value ?? '';
  }

  protected async submitMovement(event: Event): Promise<void> {
    event.preventDefault();
    if (this.shift.busy()) {
      return;
    }
    const amount = this.field('movement-amount').trim();
    const reason = this.field('movement-reason').trim();
    const problem =
      checkDecimalInput(amount, { label: 'Amount', allowZero: false }) ??
      (reason === '' ? 'Reason is required' : null);
    if (problem !== null) {
      this.error.set(problem);
      return;
    }
    if (await this.shift.recordMovement(this.type(), amount, reason)) {
      const label = MOVEMENT_KINDS.find((k) => k.type === this.type())?.label ?? '';
      this.notifier.success(`${label} recorded`);
      this.go('menu');
    }
  }

  protected async submitClose(event: Event): Promise<void> {
    event.preventDefault();
    if (this.shift.busy()) {
      return;
    }
    const counted = this.field('counted-cash').trim();
    const problem = checkDecimalInput(counted, { label: 'Counted cash', allowZero: true });
    if (problem !== null) {
      this.error.set(problem);
      return;
    }
    const result = await this.shift.closeShift(counted);
    if (result.kind === 'closed') {
      this.closed.set(result.shift);
      this.go('closed');
    } else if (result.kind === 'open-sales') {
      this.go('open-sales');
    }
  }

  /**
   * Puts the sale in the cart. `mine` is this dialog's id: when another dialog has the screen once
   * the sale has been read, the cart is left alone and nothing is done (false).
   */
  private async bringToCart(summary: SaleSummary, mine: number | undefined): Promise<boolean> {
    try {
      const sale = await firstValueFrom(this.connectivity.track(this.api.getSale(summary.id)));
      if (this.dialogs.currentId() !== mine) {
        return false;
      }
      this.sale.load(sale);
      return true;
    } catch (err) {
      if (this.dialogs.currentId() === mine) {
        this.notifier.fail(err);
      }
      return false;
    }
  }

  protected async openSale(summary: SaleSummary): Promise<void> {
    const mine = this.dialogs.currentId();
    if (await this.bringToCart(summary, mine)) {
      this.dialogs.closeIf(mine);
    }
  }

  protected async completeSale(summary: SaleSummary): Promise<void> {
    const mine = this.dialogs.currentId();
    if (await this.bringToCart(summary, mine)) {
      if (this.dialogs.currentId() !== mine) {
        // Another dialog took the screen while the sale was loading: open nothing on top of it.
        return;
      }
      this.dialogs.closeIf(mine);
      void this.dialogs.open('payment', undefined, null);
    }
  }

  protected async voidSale(summary: SaleSummary): Promise<void> {
    const mine = this.dialogs.currentId();
    if (await this.bringToCart(summary, mine)) {
      if (this.dialogs.currentId() !== mine) {
        // Another dialog took the screen while the sale was loading: open nothing on top of it.
        return;
      }
      this.dialogs.closeIf(mine);
      void this.dialogs.open('void', undefined, null);
    }
  }

  protected async holdSale(summary: SaleSummary): Promise<void> {
    if ((await this.sale.holdSale(summary.id)).ok) {
      await this.shift.loadOpenSales();
    }
  }
}
