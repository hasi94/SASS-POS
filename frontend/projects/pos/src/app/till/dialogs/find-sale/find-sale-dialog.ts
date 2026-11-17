import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { SaleStatus, SaleSummary, SalesApi } from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { describeError } from '../../errors/refusal-catalogue';
import { MoneyPipe } from '../../format/money.pipe';
import { Connectivity } from '../../state/connectivity';
import { DialogHost } from '../../state/dialog-host';
import { FindSaleDialogData } from '../../state/void-actions';
import { DialogFrame } from '../../ui/dialog-frame';

/** How many of the shift's sales are listed when no number is given (AC-56). */
export const FIND_SALE_LIMIT = 50;

/**
 * Finding a sale of this shift (T-037 AC-56): the latest {@link FIND_SALE_LIMIT} completed
 * sales, or the one with the number typed in. Choosing a row closes the dialog with that
 * `SaleSummary`; `Esc` closes it with nothing. Up and Down move between rows.
 */
@Component({
  selector: 'pos-find-sale-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame, MoneyPipe],
  template: `
    <pos-dialog-frame heading="Find a sale">
      <form class="search" (submit)="onSearch($event)" novalidate>
        <label>
          Sale number
          <input
            name="number"
            type="text"
            autocomplete="off"
            data-autofocus
            placeholder="Latest sales of this shift"
          />
        </label>
        <button type="submit" [disabled]="loading()">Find</button>
      </form>
      <p class="problem" role="alert" data-testid="find-sale-problem">{{ problem() }}</p>
      @if (loaded() && rows().length === 0 && !problem()) {
        <p data-testid="find-sale-empty">No sales found</p>
      }
      <ul class="rows" data-testid="find-sale-list" (keydown)="onRowKey($event)">
        @for (row of rows(); track row.id) {
          <li>
            <button type="button" class="row" (click)="choose(row)">
              <span class="number">{{ row.number ?? row.reference }}</span>
              <span>{{ row.total | money: row.currency }}</span>
              <span>{{ row.customer_name ?? 'No customer' }}</span>
              <span>{{ row.completed_at }}</span>
            </button>
          </li>
        }
      </ul>
      <div class="actions">
        <button type="button" (click)="dialogs.close()">Cancel</button>
      </div>
    </pos-dialog-frame>
  `,
  styles: `
    .search {
      display: flex;
      gap: 8px;
      align-items: end;
    }

    label {
      display: grid;
      gap: 4px;
      flex: 1;
    }

    input {
      min-height: 44px;
      padding: 0 10px;
      font: inherit;
    }

    .problem {
      min-height: 1.4em;
      margin: 0;
      color: var(--fp-danger, #b00020);
    }

    .rows {
      list-style: none;
      margin: 0;
      padding: 0;
      display: grid;
      gap: 4px;
      max-height: 40vh;
      overflow: auto;
    }

    .row {
      width: 100%;
      min-height: 44px;
      display: grid;
      grid-template-columns: 1.4fr 1fr 1.4fr 1.6fr;
      gap: 8px;
      text-align: left;
      padding: 0 10px;
    }

    .number {
      font-weight: 600;
    }

    button {
      min-height: 44px;
      padding: 0 16px;
    }
  `,
})
export class FindSaleDialog implements OnInit {
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  protected readonly dialogs = inject(DialogHost);

  /** What the opener passed to `DialogHost.open`: optionally the statuses to list. */
  readonly data = input<unknown>();

  protected readonly rows = signal<SaleSummary[]>([]);
  protected readonly loading = signal(false);
  protected readonly loaded = signal(false);
  protected readonly problem = signal('');

  ngOnInit(): void {
    void this.search(null);
  }

  protected onSearch(event: Event): void {
    event.preventDefault();
    const field = (event.target as HTMLFormElement).elements.namedItem('number');
    const typed = field instanceof HTMLInputElement ? field.value.trim() : '';
    void this.search(typed === '' ? null : typed);
  }

  protected choose(row: SaleSummary): void {
    this.dialogs.close(row);
  }

  /** Up and Down move the focus between rows. */
  protected onRowKey(event: KeyboardEvent): void {
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
      return;
    }
    const rows = Array.from(
      (event.currentTarget as HTMLElement).querySelectorAll<HTMLButtonElement>('button.row'),
    );
    const at = rows.indexOf(event.target as HTMLButtonElement);
    const next = event.key === 'ArrowDown' ? at + 1 : at - 1;
    const row = rows[next];
    if (row) {
      event.preventDefault();
      row.focus();
    }
  }

  private statuses(): SaleStatus[] {
    return (this.data() as FindSaleDialogData | undefined)?.statuses ?? ['completed'];
  }

  private async search(number: string | null): Promise<void> {
    this.loading.set(true);
    this.problem.set('');
    try {
      const page = await firstValueFrom(
        this.connectivity.track(
          this.api.listSales({
            status: this.statuses(),
            scope: 'current_shift',
            limit: FIND_SALE_LIMIT,
            ...(number === null ? {} : { number }),
          }),
        ),
      );
      this.rows.set(page.items);
    } catch (err) {
      this.rows.set([]);
      this.problem.set(describeError(err).text);
    } finally {
      this.loaded.set(true);
      this.loading.set(false);
    }
  }
}
