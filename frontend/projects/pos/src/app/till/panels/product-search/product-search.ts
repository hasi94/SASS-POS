import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { SalesApi, TillProduct } from '@flexipos/shared';
import { EMPTY, Subject, catchError, debounceTime, map, of, switchMap, tap } from 'rxjs';
import { TillMessage, describeError } from '../../errors/refusal-catalogue';
import { MoneyPipe } from '../../format/money.pipe';
import { Connectivity } from '../../state/connectivity';
import { DialogHost } from '../../state/dialog-host';
import { SaleStore } from '../../state/sale-store';
import { PRODUCT_PAGE_SIZE } from '../product-grid/product-grid';

/** How long the cashier must stop typing before a search is sent (AC-18). */
export const SEARCH_DEBOUNCE_MS = 250;

/**
 * What picking a result does. In `sale` mode (the till) the product goes into the cart; in `pick`
 * mode (the goodwill refund, step A4, which reuses this component) it only reports the pick
 * through `(picked)` and touches no cart.
 */
export type SearchMode = 'sale' | 'pick';

/**
 * Product search (T-037 AC-18). The input carries `data-till-search-field` so the `/` and
 * `Ctrl+K` keys can focus it. Typing is debounced 250 ms and a newer search supersedes (and
 * cancels) the one in flight; Up and Down move the highlight, Enter picks the highlighted result.
 * Every request carries `limit=50`; the server does the matching on name, SKU, PLU and barcode.
 */
@Component({
  selector: 'pos-product-search',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [MoneyPipe],
  template: `
    <div class="search">
      <label class="field">
        <span>Search products</span>
        <input
          #box
          type="search"
          autocomplete="off"
          role="combobox"
          aria-autocomplete="list"
          aria-controls="pos-search-results"
          [attr.aria-expanded]="showResults()"
          [attr.aria-activedescendant]="activeId()"
          data-till-search-field
          data-testid="search-field"
          (input)="typed($event)"
          (keydown)="onKeydown($event)"
        />
      </label>
      @if (failure(); as message) {
        <p class="fp-error" role="alert" data-testid="search-error">
          Search failed: {{ message.text }}
        </p>
      }
      @if (showResults()) {
        @if (results().length === 0 && !searching()) {
          <p class="fp-muted" role="status" data-testid="search-empty">No products found</p>
        }
        <ul
          id="pos-search-results"
          role="listbox"
          aria-label="Search results"
          data-testid="results"
        >
          @for (product of results(); track product.id; let i = $index) {
            <li
              role="option"
              [id]="'pos-search-option-' + i"
              [attr.aria-selected]="i === highlighted()"
              [class.highlighted]="i === highlighted()"
              data-testid="result"
              (click)="pick(product)"
            >
              <span class="name">{{ product.name }}</span>
              <span class="fp-muted">{{ product.sku }}</span>
              <span class="price">{{ product.list_price | money: product.currency }}</span>
            </li>
          }
        </ul>
      }
    </div>
  `,
  styles: `
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

    ul {
      list-style: none;
      margin: 8px 0 0;
      padding: 0;
      max-height: 40vh;
      overflow: auto;
      border: 1px solid var(--fp-border);
      border-radius: var(--fp-radius);
    }

    li {
      display: flex;
      align-items: center;
      gap: 12px;
      min-height: 44px;
      padding: 6px 12px;
      cursor: pointer;
    }

    li.highlighted {
      background: var(--fp-primary);
      color: var(--fp-primary-text);
      font-weight: 700;
    }

    li.highlighted .fp-muted {
      color: inherit;
    }

    .name {
      flex: 1;
    }
  `,
})
export class ProductSearch {
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  private readonly dialogs = inject(DialogHost);
  private readonly sale = inject(SaleStore);
  private readonly box = viewChild.required<ElementRef<HTMLInputElement>>('box');
  private readonly typedText = new Subject<string>();

  /** `sale` puts a picked product in the cart; `pick` only reports it. */
  readonly mode = input<SearchMode>('sale');
  /** The product the cashier picked (Enter or a click), in either mode. */
  readonly picked = output<TillProduct>();

  protected readonly results = signal<TillProduct[]>([]);
  protected readonly highlighted = signal(0);
  protected readonly searching = signal(false);
  protected readonly failure = signal<TillMessage | null>(null);
  protected readonly showResults = signal(false);

  constructor() {
    this.typedText
      .pipe(
        map((text) => text.trim()),
        tap((text) => {
          if (text === '') {
            this.clearResults();
          }
        }),
        debounceTime(SEARCH_DEBOUNCE_MS),
        switchMap((text) => {
          if (text === '') {
            return EMPTY;
          }
          this.searching.set(true);
          this.failure.set(null);
          return this.connectivity
            .track(this.api.products({ q: text, limit: PRODUCT_PAGE_SIZE }))
            .pipe(
              catchError((err: unknown) => {
                this.failure.set(describeError(err));
                this.searching.set(false);
                return of(null);
              }),
            );
        }),
        takeUntilDestroyed(inject(DestroyRef)),
      )
      .subscribe((page) => {
        if (page === null) {
          return;
        }
        this.searching.set(false);
        this.results.set(page.items);
        this.highlighted.set(0);
        this.showResults.set(true);
      });
  }

  protected activeId(): string | null {
    return this.showResults() && this.results().length > 0
      ? `pos-search-option-${this.highlighted()}`
      : null;
  }

  protected typed(event: Event): void {
    const text = (event.target as HTMLInputElement).value;
    // A search that has not been answered yet is stale the moment the text changes.
    this.typedText.next(text);
  }

  private clearResults(): void {
    this.results.set([]);
    this.highlighted.set(0);
    this.showResults.set(false);
    this.searching.set(false);
    this.failure.set(null);
  }

  protected onKeydown(event: KeyboardEvent): void {
    const count = this.results().length;
    if (event.key === 'ArrowDown' && count > 0) {
      event.preventDefault();
      this.highlighted.update((i) => Math.min(count - 1, i + 1));
    } else if (event.key === 'ArrowUp' && count > 0) {
      event.preventDefault();
      this.highlighted.update((i) => Math.max(0, i - 1));
    } else if (event.key === 'Enter') {
      const product = this.results()[this.highlighted()];
      if (product && this.showResults()) {
        event.preventDefault();
        void this.pick(product);
      }
    } else if (event.key === 'Escape') {
      this.reset();
    }
  }

  /** Empties the box and the results. */
  private reset(): void {
    this.box().nativeElement.value = '';
    this.typedText.next('');
    this.clearResults();
  }

  protected async pick(product: TillProduct): Promise<void> {
    this.picked.emit(product);
    if (this.mode() === 'sale') {
      this.reset();
      await this.sale.addProduct(product);
      this.dialogs.focusScanField();
    }
  }
}
