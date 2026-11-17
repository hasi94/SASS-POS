import { DatePipe } from '@angular/common';
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
import { SaleSummary, SalesApi } from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { describeError } from '../../errors/refusal-catalogue';
import { MoneyPipe } from '../../format/money.pipe';
import { Connectivity } from '../../state/connectivity';
import { DialogHost } from '../../state/dialog-host';
import { RECALL_PAGE_SIZE, SaleStore } from '../../state/sale-store';
import { ShiftStore } from '../../state/shift-store';
import { DialogFrame } from '../../ui/dialog-frame';
import { ConfirmDialogData } from '../confirm/confirm-dialog';

/**
 * Recall (T-037 AC-52 to AC-54): the held sales of the whole branch, from the server, a page at
 * a time. Up and Down select a row and Enter recalls it. When the cart already holds a sale the
 * cashier is asked to hold it first or keep it (a sale is never dropped silently); the recalled
 * sale then appears in the cart in the status the server reports. A refusal stays in the dialog
 * in words; one that says the shift is gone hands over to the shift prompt (AC-15).
 */
@Component({
  selector: 'pos-recall-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DatePipe, DialogFrame, MoneyPipe],
  template: `
    <pos-dialog-frame heading="Recall a held sale">
      @if (loading()) {
        <p data-testid="recall-loading">Loading held sales</p>
      }
      @if (loaded() && rows().length === 0 && !loadProblem()) {
        <p data-testid="recall-empty">There are no held sales at this branch.</p>
      }
      @if (loadProblem(); as why) {
        <p class="problem" role="alert" data-testid="recall-load-problem">{{ why }}</p>
      }
      @if (rows().length > 0) {
        <ul
          #list
          class="rows"
          role="listbox"
          tabindex="0"
          aria-label="Held sales"
          data-autofocus
          data-testid="recall-list"
          [attr.aria-activedescendant]="activeId()"
          (keydown)="onKeydown($event)"
        >
          @for (row of rows(); track row.id; let index = $index) {
            <li
              role="option"
              class="row"
              data-testid="recall-row"
              [id]="'pos-recall-row-' + row.id"
              [class.selected]="index === selectedIndex()"
              [attr.aria-selected]="index === selectedIndex()"
              (click)="pick(index)"
              (dblclick)="recall(row)"
            >
              <strong data-testid="recall-name">{{ row.reference ?? row.number }}</strong>
              @if (row.held_at) {
                <span data-testid="recall-time">{{ row.held_at | date: 'd MMM HH:mm' }}</span>
              }
              @if (row.held_by_name) {
                <span data-testid="recall-by">by {{ row.held_by_name }}</span>
              }
              @if (row.customer_name) {
                <span data-testid="recall-customer">{{ row.customer_name }}</span>
              }
              @if (row.held_from_status === 'pending_payment') {
                <span class="marker">part paid</span>
              }
              <span class="total" data-testid="recall-total">{{
                row.total | money: row.currency
              }}</span>
            </li>
          }
        </ul>
      }
      @if (more()) {
        <button type="button" data-testid="recall-more" [disabled]="loading()" (click)="loadMore()">
          Load more
        </button>
      }
      <p class="problem" role="alert" data-testid="recall-problem">{{ problem() }}</p>
      <div class="actions">
        <button
          type="button"
          class="primary"
          data-testid="recall-go"
          [disabled]="busy() || rows().length === 0"
          (click)="recallSelected()"
        >
          Recall
        </button>
        <button type="button" (click)="dialogs.close()">Cancel</button>
      </div>
    </pos-dialog-frame>
  `,
  styles: `
    .rows {
      list-style: none;
      margin: 0;
      padding: 0;
      max-height: 50vh;
      overflow: auto;
    }

    .row {
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      gap: 4px 12px;
      min-height: 44px;
      padding: 8px;
      border: 2px solid transparent;
      border-bottom-color: var(--fp-border);
      cursor: pointer;
    }

    .row.selected {
      border-color: var(--fp-primary);
      border-radius: var(--fp-radius);
      font-weight: 600;
    }

    .total {
      margin-left: auto;
    }

    .marker {
      padding: 0 6px;
      border: 1px solid var(--fp-border);
      border-radius: 4px;
      font-size: 0.8rem;
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
export class RecallDialog {
  protected readonly dialogs = inject(DialogHost);
  private readonly cart = inject(SaleStore);
  private readonly shift = inject(ShiftStore);
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  private readonly injector = inject(Injector);
  private readonly list = viewChild<ElementRef<HTMLElement>>('list');

  /** What the opener passed to `DialogHost.open`. */
  readonly data = input<unknown>();

  protected readonly rows = signal<SaleSummary[]>([]);
  protected readonly selectedIndex = signal(0);
  protected readonly loading = signal(false);
  protected readonly loaded = signal(false);
  protected readonly loadProblem = signal('');
  protected readonly problem = signal('');
  protected readonly busy = signal(false);
  private readonly cursor = signal<string | null>(null);
  protected readonly more = computed(() => this.cursor() !== null);
  protected readonly activeId = computed(() => {
    const row = this.rows()[this.selectedIndex()];
    return row ? `pos-recall-row-${row.id}` : null;
  });

  constructor() {
    void this.load(null);
  }

  /** Reads one page of held sales. `null` starts again from the first page. */
  private async load(cursor: string | null): Promise<void> {
    this.loading.set(true);
    this.loadProblem.set('');
    try {
      const page = await firstValueFrom(
        this.connectivity.track(
          this.api.listSales({
            status: ['held'],
            scope: 'branch',
            limit: RECALL_PAGE_SIZE,
            ...(cursor !== null ? { cursor } : {}),
          }),
        ),
      );
      this.rows.update((rows) => (cursor === null ? page.items : [...rows, ...page.items]));
      this.cursor.set(page.next_cursor);
      this.selectedIndex.update((i) => Math.min(i, Math.max(this.rows().length - 1, 0)));
      if (cursor === null) {
        // The list is not there when the dialog opens: the arrows work once it has the focus.
        afterNextRender(() => this.list()?.nativeElement.focus(), { injector: this.injector });
      }
    } catch (err) {
      this.loadProblem.set(describeError(err).text);
    } finally {
      this.loading.set(false);
      this.loaded.set(true);
    }
  }

  protected loadMore(): void {
    const cursor = this.cursor();
    if (cursor !== null && !this.loading()) {
      void this.load(cursor);
    }
  }

  protected pick(index: number): void {
    this.selectedIndex.set(index);
  }

  /** Up and Down move the selection, Enter recalls it (AC-52). */
  protected onKeydown(event: KeyboardEvent): void {
    const count = this.rows().length;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const step = event.key === 'ArrowDown' ? 1 : -1;
      this.selectedIndex.update((i) => Math.min(Math.max(i + step, 0), Math.max(count - 1, 0)));
    } else if (event.key === 'Enter') {
      event.preventDefault();
      this.recallSelected();
    }
  }

  protected recallSelected(): void {
    const row = this.rows()[this.selectedIndex()];
    if (row) {
      void this.recall(row);
    }
  }

  protected async recall(row: SaleSummary): Promise<void> {
    if (this.busy()) {
      return;
    }
    this.busy.set(true);
    this.problem.set('');
    try {
      if (!(await this.clearCart())) {
        return;
      }
      const outcome = await this.cart.recall(row.id);
      if (outcome.ok) {
        this.dialogs.close();
        return;
      }
      if (!this.shift.shiftOpen()) {
        // The shift is gone: the shift prompt takes over (AC-15).
        this.dialogs.close();
        return;
      }
      if (outcome.error !== undefined) {
        this.problem.set(describeError(outcome.error).text);
        // The list may be out of date (recalled elsewhere): read it again.
        await this.load(null);
      }
    } finally {
      this.busy.set(false);
    }
  }

  /**
   * A sale already in the cart is held first, when the cashier says so (AC-54). Resolves true
   * when the cart is free for the recalled sale.
   */
  private async clearCart(): Promise<boolean> {
    const inCart = this.cart.current();
    if (inCart === null || this.cart.lineCount() === 0) {
      return true;
    }
    const question: ConfirmDialogData = {
      heading: 'A sale is in the cart',
      message: `Hold the sale in the cart (${inCart.reference ?? 'no reference yet'}) before recalling another? Keeping it leaves it in the cart.`,
      confirmLabel: 'Hold it and recall',
      cancelLabel: 'Keep it',
    };
    if ((await this.dialogs.open('confirm', question)) !== true) {
      return false;
    }
    return (await this.cart.holdSale(inCart.id)).ok;
  }
}
