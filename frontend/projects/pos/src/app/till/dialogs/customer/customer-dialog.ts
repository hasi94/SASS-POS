import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { CustomerSummary, SalesApi } from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { describeError } from '../../errors/refusal-catalogue';
import { MoneyPipe } from '../../format/money.pipe';
import { Connectivity } from '../../state/connectivity';
import { DialogHost } from '../../state/dialog-host';
import { Notifier } from '../../state/notifier';
import { SaleStore } from '../../state/sale-store';
import { DialogFrame } from '../../ui/dialog-frame';

/** What the cashier is told when the server has no such customer (AC-39). */
export const CUSTOMER_NOT_FOUND = 'Customer not found';

/**
 * Customer on the sale (T-037 AC-39). The cashier types a code, a phone number or a name; the
 * server's lookup answers and nothing is filtered here. Choosing a customer puts it on the sale,
 * creating the sale first when the cart has none (`SaleStore.attachCustomer`). A customer the
 * server does not know, or no longer has (deleted, merged), is "Customer not found".
 */
@Component({
  selector: 'pos-customer-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame, MoneyPipe],
  template: `
    <pos-dialog-frame heading="Customer">
      @if (sale.current()?.customer; as attached) {
        <p class="current" data-testid="customer-current">
          On this sale: <strong>{{ attached.name }}</strong> ({{ attached.code }})
        </p>
      }
      <form (submit)="onSubmit($event)" novalidate>
        <label>
          Code, phone or name
          <input
            name="q"
            type="text"
            autocomplete="off"
            data-autofocus
            data-testid="customer-query"
            [value]="query()"
            (input)="onQuery($event)"
          />
        </label>
        <div class="actions">
          <button type="submit" class="primary" [disabled]="searching()">Find</button>
          <button type="button" (click)="dialogs.close()">Cancel</button>
        </div>
      </form>
      <p class="problem" role="alert" data-testid="customer-problem">{{ problem() }}</p>
      @if (results().length > 0) {
        <ul class="results" aria-label="Customers found" data-testid="customer-results">
          @for (customer of results(); track customer.id) {
            <li>
              <button
                type="button"
                class="row"
                data-testid="customer-result"
                [disabled]="attaching() || !online()"
                (click)="choose(customer)"
              >
                <strong>{{ customer.name }}</strong>
                <span>{{ customer.code }}</span>
                @if (customer.phone) {
                  <span>{{ customer.phone }}</span>
                }
                <span class="fp-muted">Store credit {{ customer.credit_balance | money }}</span>
              </button>
            </li>
          }
        </ul>
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

    .current {
      margin: 0;
    }

    .problem {
      min-height: 1.4em;
      margin: 0;
      color: var(--fp-danger, #b00020);
    }

    .results {
      list-style: none;
      margin: 0;
      padding: 0;
      display: grid;
      gap: 6px;
    }

    .row {
      width: 100%;
      display: flex;
      flex-wrap: wrap;
      gap: 4px 12px;
      text-align: left;
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
export class CustomerDialog {
  protected readonly dialogs = inject(DialogHost);
  protected readonly sale = inject(SaleStore);
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  protected readonly online = this.connectivity.online;
  private readonly notifier = inject(Notifier);

  /** What the opener passed to `DialogHost.open`. */
  readonly data = input<unknown>();

  protected readonly query = signal('');
  protected readonly results = signal<CustomerSummary[]>([]);
  protected readonly problem = signal('');
  protected readonly searching = signal(false);
  protected readonly attaching = signal(false);
  /** Which search the answer belongs to, so a slow earlier answer never overwrites a later one. */
  private searchNumber = 0;

  protected onQuery(event: Event): void {
    this.query.set((event.target as HTMLInputElement).value);
    this.problem.set('');
  }

  protected onSubmit(event: Event): void {
    event.preventDefault();
    void this.search();
  }

  protected async search(): Promise<void> {
    const text = this.query().trim();
    if (text === '') {
      this.problem.set('Enter a code, phone number or name');
      return;
    }
    if (this.searching()) {
      return;
    }
    this.searching.set(true);
    this.problem.set('');
    const mine = (this.searchNumber += 1);
    try {
      const found = await firstValueFrom(this.connectivity.track(this.api.lookupCustomers(text)));
      if (mine !== this.searchNumber) {
        return;
      }
      this.results.set(found);
      if (found.length === 0) {
        this.problem.set(CUSTOMER_NOT_FOUND);
      }
    } catch (err) {
      this.results.set([]);
      this.problem.set(describeError(err).text);
    } finally {
      this.searching.set(false);
    }
  }

  protected async choose(customer: CustomerSummary): Promise<void> {
    if (this.attaching()) {
      return;
    }
    this.attaching.set(true);
    const mine = this.dialogs.currentId();
    try {
      const outcome = await this.sale.attachCustomer(customer.id);
      if (outcome.ok) {
        this.notifier.success(`Customer ${customer.name} on the sale`);
        this.dialogs.closeIf(mine, customer);
      } else if (outcome.code === 'not_found') {
        this.results.set([]);
        this.problem.set(CUSTOMER_NOT_FOUND);
      }
    } finally {
      this.attaching.set(false);
    }
  }
}
