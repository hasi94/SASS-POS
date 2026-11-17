import { NgTemplateOutlet } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  Directive,
  ElementRef,
  Injector,
  TemplateRef,
  afterNextRender,
  contentChild,
  effect,
  inject,
  input,
  untracked,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subject, debounceTime, distinctUntilChanged, map } from 'rxjs';
import { ApiErrorPanel } from './api-error-panel';
import { ListController } from './list-controller';

/** One column of a list: its header, and how a row becomes cell text. */
export interface ListColumn<T> {
  header: string;
  cell: (row: T) => string;
  /** Left indent in steps, for tree lists (T-036 AC-28). */
  indent?: (row: T) => number;
}

export interface RowActionsContext<T> {
  $implicit: T;
}

/**
 * Marks the `ng-template` of a row's action cell:
 * `<ng-template [admRowActions]="controller.rows()" let-row>...</ng-template>`.
 * The bound value only tells the compiler the row type.
 */
@Directive({ selector: 'ng-template[admRowActions]' })
export class RowActions<T> {
  readonly admRowActions = input<readonly T[] | null | undefined>(null);
  readonly template = inject<TemplateRef<RowActionsContext<T>>>(TemplateRef);

  static ngTemplateContextGuard<T>(
    _dir: RowActions<T>,
    _ctx: unknown,
  ): _ctx is RowActionsContext<T> {
    return true;
  }
}

/** Waits this long after the last key before a search is sent (T-036 AC-15). */
export const SEARCH_DEBOUNCE_MS = 300;

/**
 * The shared list: a captioned table with scoped headers, a search box, a pager, an empty
 * state, a polite loading status and an error panel with Retry (T-036 AC-14 to AC-16).
 * Presentational: all state lives in the {@link ListController} it is given.
 */
@Component({
  selector: 'adm-list-view',
  imports: [ApiErrorPanel, NgTemplateOutlet],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '(focusin)': 'rememberFocus($event)' },
  template: `
    @if (searchable()) {
      <label class="adm-search">
        <span>{{ searchLabel() }}</span>
        <input
          type="search"
          [value]="controller().search()"
          (input)="typed($any($event.target).value)"
        />
      </label>
    }

    <p class="fp-muted adm-live" role="status">{{ controller().loading() ? 'Loading…' : '' }}</p>

    @if (controller().error(); as err) {
      <adm-api-error-panel [error]="err" (retry)="controller().retry()" />
    } @else {
      <table #table class="adm-table" tabindex="-1">
        <caption>
          {{
            caption()
          }}
        </caption>
        <thead>
          <tr>
            @for (col of columns(); track col.header) {
              <th scope="col">{{ col.header }}</th>
            }
            @if (actions()) {
              <th scope="col">Actions</th>
            }
          </tr>
        </thead>
        <tbody>
          @for (row of controller().rows(); track key(row)) {
            <tr>
              @for (col of columns(); track col.header) {
                <td [style.padding-left.rem]="col.indent ? 0.75 + col.indent(row) * 1.25 : null">
                  {{ col.cell(row) }}
                </td>
              }
              @if (actions(); as a) {
                <td class="adm-actions">
                  <ng-container *ngTemplateOutlet="a.template; context: { $implicit: row }" />
                </td>
              }
            </tr>
          }
        </tbody>
      </table>
      @if (!controller().loading() && controller().rows().length === 0) {
        <p class="fp-muted adm-empty">Nothing here yet</p>
      }
      <nav class="adm-pager" aria-label="Pages">
        <button
          type="button"
          [disabled]="controller().page() <= 1"
          (click)="controller().goTo(controller().page() - 1)"
        >
          Previous
        </button>
        <span>Page {{ controller().page() }} of {{ controller().pageCount() }}</span>
        <button
          type="button"
          [disabled]="controller().page() >= controller().pageCount()"
          (click)="controller().goTo(controller().page() + 1)"
        >
          Next
        </button>
      </nav>
    }
  `,
})
export class ListView<T> {
  readonly controller = input.required<ListController<T>>();
  readonly columns = input.required<readonly ListColumn<T>[]>();
  readonly caption = input.required<string>();
  readonly searchable = input(true);
  readonly searchLabel = input('Search');

  protected readonly actions = contentChild(RowActions);
  private readonly table = viewChild<ElementRef<HTMLElement>>('table');
  private readonly typedText = new Subject<string>();
  private readonly injector = inject(Injector);
  private focused: EventTarget | null = null;
  private wasLoading = false;

  constructor() {
    // When a reload removes the element that had focus (the Delete button of a deleted row),
    // focus goes to the table, not to <body> (QA BUG-4, review S-1).
    effect(() => {
      const loading = this.controller().loading();
      untracked(() => {
        if (this.wasLoading && !loading) {
          afterNextRender(() => this.restoreFocus(), { injector: this.injector });
        }
        this.wasLoading = loading;
      });
    });
    this.typedText
      .pipe(
        map((text) => text.trim()),
        debounceTime(SEARCH_DEBOUNCE_MS),
        distinctUntilChanged(),
        takeUntilDestroyed(),
      )
      .subscribe((text) => this.controller().setSearch(text));
  }

  /** Rows are keyed by `id` when they have one, so a reload keeps the rows that remain. */
  protected key(row: T): unknown {
    const id = (row as { id?: unknown } | null)?.id;
    return id ?? row;
  }

  protected rememberFocus(event: Event): void {
    this.focused = event.target;
  }

  private restoreFocus(): void {
    const gone = this.focused;
    this.focused = null;
    if (gone instanceof Node && !gone.isConnected && !this.hasFocus()) {
      this.table()?.nativeElement.focus();
    }
  }

  private hasFocus(): boolean {
    const active = document.activeElement;
    return active !== null && active !== document.body;
  }

  protected typed(text: string): void {
    this.typedText.next(text);
  }
}
