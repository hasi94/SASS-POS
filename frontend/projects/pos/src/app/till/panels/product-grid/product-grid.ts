import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  computed,
  effect,
  inject,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { SalesApi, TillCategory, TillProduct } from '@flexipos/shared';
import { Subscription } from 'rxjs';
import { TillMessage, describeError } from '../../errors/refusal-catalogue';
import { MoneyPipe } from '../../format/money.pipe';
import { Connectivity } from '../../state/connectivity';
import { DialogHost } from '../../state/dialog-host';
import { SaleStore } from '../../state/sale-store';
import { ShiftStore } from '../../state/shift-store';

/** The grid and the search ask for this many products at a time, never the whole catalogue (AC-17). */
export const PRODUCT_PAGE_SIZE = 50;

/**
 * The product grid with category tabs (T-037 AC-16 to AC-20, AC-85). It lists what the server
 * says is sellable, 50 at a time, with the server's list price labelled as such (the cart price
 * may differ). Arrow keys move between tiles (a roving tab stop), Enter adds the focused product
 * (a weighable one opens the weight prompt). A failed request shows an error with Retry and
 * leaves the scan field and the cart usable.
 */
@Component({
  selector: 'pos-product-grid',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [MoneyPipe],
  template: `
    <section aria-labelledby="pos-grid-title">
      <h2 id="pos-grid-title">Products</h2>
      @if (!shift.shiftOpen()) {
        <p class="fp-muted" data-testid="grid-closed">Open a shift to see products</p>
      } @else {
        <div class="tabs" role="group" aria-label="Categories">
          <button
            type="button"
            data-testid="category-all"
            [attr.aria-pressed]="categoryId() === null"
            (click)="choose(null)"
          >
            All
          </button>
          @for (category of categories(); track category.id) {
            <button
              type="button"
              [attr.data-testid]="'category-' + category.id"
              [attr.aria-pressed]="categoryId() === category.id"
              (click)="choose(category.id)"
            >
              {{ category.name }}
            </button>
          }
        </div>
        @if (failure(); as message) {
          <div class="failure" role="alert" data-testid="grid-error">
            <span>Products could not be loaded: {{ message.text }}</span>
            <button type="button" data-testid="grid-retry" (click)="retry()">Retry</button>
          </div>
        }
        <div class="scroll">
          <div
            class="tiles"
            role="group"
            aria-label="Products"
            data-testid="tiles"
            #tiles
            (keydown)="onKeydown($event)"
          >
            @for (product of products(); track product.id) {
              <button
                type="button"
                class="tile"
                data-testid="tile"
                [attr.data-product-id]="product.id"
                [attr.tabindex]="product.id === tabStop() ? 0 : -1"
                (focus)="focused.set(product.id)"
                (click)="activate(product)"
                (keydown.enter)="enter($event, product)"
              >
                <span class="name">{{ product.name }}</span>
                <span class="price">
                  <span class="label">List price</span>
                  {{ product.list_price | money: product.currency }}
                </span>
              </button>
            }
          </div>
          @if (loading()) {
            <p class="fp-muted" role="status">Loading products</p>
          } @else if (!failure() && products().length === 0) {
            <p class="fp-muted" data-testid="grid-empty">No products in this category</p>
          }
          @if (nextCursor()) {
            <button type="button" class="more" data-testid="load-more" (click)="loadMore()">
              Load more
            </button>
          }
          <div #sentinel class="sentinel" aria-hidden="true"></div>
        </div>
      }
    </section>
  `,
  styles: `
    h2 {
      margin: 0 0 8px;
      font-size: 1.1rem;
    }

    .tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-bottom: 8px;
    }

    .tabs button {
      min-height: 44px;
    }

    .tabs button[aria-pressed='true'] {
      background: var(--fp-primary);
      border-color: var(--fp-primary);
      color: var(--fp-primary-text);
      font-weight: 700;
    }

    .failure {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 8px 12px;
      margin-bottom: 8px;
      border: 2px solid var(--fp-danger);
      border-radius: var(--fp-radius);
    }

    .failure button {
      margin-left: auto;
      min-height: 44px;
    }

    .scroll {
      max-height: 55vh;
      overflow: auto;
    }

    .tiles {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
      gap: 8px;
    }

    .tile {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      justify-content: space-between;
      gap: 4px;
      min-width: 44px;
      min-height: 72px;
      text-align: left;
    }

    .tile:focus-visible,
    .tabs button:focus-visible,
    .more:focus-visible {
      outline: 3px solid var(--fp-primary);
      outline-offset: 2px;
    }

    .name {
      font-weight: 600;
    }

    .label {
      color: var(--fp-muted);
      font-size: 0.8rem;
    }

    .more {
      margin-top: 8px;
      min-height: 44px;
    }

    .sentinel {
      height: 1px;
    }
  `,
})
export class ProductGrid {
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  private readonly dialogs = inject(DialogHost);
  private readonly sale = inject(SaleStore);
  protected readonly shift = inject(ShiftStore);
  private readonly tiles = viewChild<ElementRef<HTMLElement>>('tiles');
  private readonly sentinel = viewChild<ElementRef<HTMLElement>>('sentinel');

  protected readonly categories = signal<TillCategory[]>([]);
  protected readonly categoryId = signal<string | null>(null);
  protected readonly products = signal<TillProduct[]>([]);
  protected readonly nextCursor = signal<string | null>(null);
  protected readonly loading = signal(false);
  protected readonly failure = signal<TillMessage | null>(null);
  protected readonly focused = signal<string | null>(null);
  /** The one tile in the tab order: the focused one, else the first. */
  protected readonly tabStop = computed(() => {
    const list = this.products();
    const id = this.focused();
    return list.some((p) => p.id === id) ? id : (list[0]?.id ?? null);
  });

  private started = false;
  private categoriesLoaded = false;
  private request: Subscription | null = null;

  constructor() {
    inject(DestroyRef).onDestroy(() => this.request?.unsubscribe());
    // The grid loads when a shift is open, once; closing and opening again keeps what it has.
    effect(() => {
      if (this.shift.shiftOpen() && !this.started) {
        this.started = true;
        untracked(() => this.start());
      }
    });
    effect((onCleanup) => {
      const element = this.sentinel()?.nativeElement;
      if (!element || typeof IntersectionObserver === 'undefined') {
        return;
      }
      const observer = new IntersectionObserver((entries) => {
        if (entries.some((e) => e.isIntersecting)) {
          this.loadMore();
        }
      });
      observer.observe(element);
      onCleanup(() => observer.disconnect());
    });
  }

  private start(): void {
    this.loadCategories();
    this.fetch(true);
  }

  private loadCategories(): void {
    this.connectivity.track(this.api.categories()).subscribe({
      next: (list) => {
        this.categories.set(list);
        this.categoriesLoaded = true;
      },
      error: (err: unknown) => this.failure.set(describeError(err)),
    });
  }

  private fetch(reset: boolean): void {
    this.request?.unsubscribe();
    const cursor = reset ? undefined : (this.nextCursor() ?? undefined);
    const category = this.categoryId();
    this.loading.set(true);
    this.failure.set(null);
    this.request = this.connectivity
      .track(
        this.api.products({
          limit: PRODUCT_PAGE_SIZE,
          ...(category !== null ? { category_id: category } : {}),
          ...(cursor !== undefined ? { cursor } : {}),
        }),
      )
      .subscribe({
        next: (page) => {
          this.products.update((have) => (reset ? page.items : [...have, ...page.items]));
          this.nextCursor.set(page.next_cursor);
          this.loading.set(false);
        },
        error: (err: unknown) => {
          this.loading.set(false);
          this.failure.set(describeError(err));
        },
      });
  }

  protected choose(categoryId: string | null): void {
    if (categoryId === this.categoryId()) {
      return;
    }
    this.categoryId.set(categoryId);
    this.products.set([]);
    this.nextCursor.set(null);
    this.fetch(true);
  }

  protected loadMore(): void {
    if (this.nextCursor() !== null && !this.loading()) {
      this.fetch(false);
    }
  }

  protected retry(): void {
    if (!this.categoriesLoaded) {
      this.loadCategories();
    }
    this.fetch(this.products().length === 0);
  }

  protected enter(event: Event, product: TillProduct): void {
    event.preventDefault();
    this.activate(product);
  }

  protected async activate(product: TillProduct): Promise<void> {
    await this.sale.addProduct(product);
    this.dialogs.focusScanField();
  }

  /** Arrow keys, Home and End move between tiles; the cart's Up and Down keys stay out of it. */
  protected onKeydown(event: KeyboardEvent): void {
    const root = this.tiles()?.nativeElement;
    if (!root) {
      return;
    }
    const buttons = Array.from(root.querySelectorAll<HTMLElement>('.tile'));
    const at = buttons.findIndex((b) => b === root.ownerDocument.activeElement);
    if (at === -1) {
      return;
    }
    const columns = this.columnCount(root);
    const steps: Record<string, number> = {
      ArrowRight: 1,
      ArrowLeft: -1,
      ArrowDown: columns,
      ArrowUp: -columns,
    };
    let target: number | null = null;
    const step = steps[event.key];
    if (step !== undefined) {
      target = at + step;
    } else if (event.key === 'Home') {
      target = 0;
    } else if (event.key === 'End') {
      target = buttons.length - 1;
    }
    if (target === null) {
      return;
    }
    event.preventDefault();
    const next = buttons[Math.max(0, Math.min(buttons.length - 1, target))];
    next?.focus();
  }

  private columnCount(root: HTMLElement): number {
    const template = root.ownerDocument.defaultView?.getComputedStyle(root).gridTemplateColumns;
    // A browser answers with the resolved tracks (`150px 150px ...`); anything else is one column.
    const tracks = (template ?? '').split(' ').filter((part) => /^\d+(\.\d+)?px$/.test(part));
    return tracks.length > 0 ? tracks.length : 1;
  }
}
