import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import {
  SaleLine,
  SalesApi,
  TillProductUnit,
  UpdateLineRequest,
  asQuantity,
} from '@flexipos/shared';
import { PRODUCT_PAGE_SIZE } from '../../panels/product-grid/product-grid';
import { Connectivity } from '../../state/connectivity';
import { checkDecimalInput } from '../../state/decimal-input';
import { DialogHost } from '../../state/dialog-host';
import { SaleStore } from '../../state/sale-store';
import { DialogFrame } from '../../ui/dialog-frame';

/** What the opener passes: the line to change. */
export interface LineDialogData {
  lineId: string;
}

export function isLineDialogData(data: unknown): data is LineDialogData {
  return (
    typeof data === 'object' &&
    data !== null &&
    'lineId' in data &&
    typeof (data as { lineId: unknown }).lineId === 'string'
  );
}

/**
 * The quantity and unit of a line (T-037 AC-31). The form refuses an empty, non-numeric,
 * negative, zero or over-precise quantity before any call; the server's `validation` is the
 * backstop. The units on offer are the product's own, read from the till's product list. The
 * line that comes back replaces the cart's, with the server's prices.
 */
@Component({
  selector: 'pos-line-edit-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame],
  template: `
    <pos-dialog-frame heading="Change the line">
      @if (line(); as current) {
        <form (submit)="submit($event)" novalidate>
          <p data-testid="edit-product">{{ current.product_name }}</p>
          <label class="field">
            <span>Quantity</span>
            <input
              #quantity
              data-autofocus
              type="text"
              inputmode="decimal"
              autocomplete="off"
              data-testid="edit-quantity"
              [value]="current.quantity"
              [attr.aria-invalid]="error() ? 'true' : null"
              (input)="error.set(null)"
              (keydown.enter)="submit($event)"
            />
          </label>
          <label class="field">
            <span>Unit</span>
            <select #unit data-testid="edit-unit">
              @for (option of units(); track option.unit_id) {
                <option [value]="option.unit_id" [selected]="option.unit_id === current.unit_id">
                  {{ option.unit_code }}
                </option>
              }
            </select>
          </label>
          @if (error(); as message) {
            <p class="fp-error" role="alert" data-testid="edit-error">{{ message }}</p>
          }
          <div class="actions">
            <button type="submit" class="fp-primary" data-testid="edit-save" [disabled]="busy()">
              Save
            </button>
            <button type="button" (click)="dialogs.close()">Cancel</button>
          </div>
        </form>
      } @else {
        <p class="fp-muted">That line is no longer in the cart.</p>
        <button type="button" data-autofocus (click)="dialogs.close()">Close</button>
      }
    </pos-dialog-frame>
  `,
  styles: `
    form {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    p {
      margin: 0;
      font-weight: 600;
    }

    .field {
      display: flex;
      flex-direction: column;
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

    .actions {
      display: flex;
      gap: 8px;
    }

    button {
      min-height: 44px;
    }
  `,
})
export class LineEditDialog {
  protected readonly dialogs = inject(DialogHost);
  private readonly store = inject(SaleStore);
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  private readonly quantity = viewChild<ElementRef<HTMLInputElement>>('quantity');
  private readonly unit = viewChild<ElementRef<HTMLSelectElement>>('unit');
  /** What the opener passed to `DialogHost.open`. */
  readonly data = input<unknown>();

  protected readonly error = signal<string | null>(null);
  protected readonly busy = signal(false);
  private readonly productUnits = signal<TillProductUnit[]>([]);

  protected readonly line = computed<SaleLine | null>(() => {
    const data = this.data();
    return isLineDialogData(data)
      ? (this.store.lines().find((l) => l.id === data.lineId) ?? null)
      : null;
  });
  /** The product's units; until they are read, the line's own unit alone. */
  protected readonly units = computed<TillProductUnit[]>(() => {
    const line = this.line();
    if (!line) {
      return [];
    }
    const own = { unit_id: line.unit_id, unit_code: line.unit_code };
    return this.productUnits().length > 0 ? this.productUnits() : [own];
  });

  constructor() {
    let started = false;
    effect(() => {
      const line = this.line();
      if (started || !line) {
        return;
      }
      started = true;
      untracked(() =>
        this.connectivity
          .track(this.api.products({ q: line.sku, limit: PRODUCT_PAGE_SIZE }))
          .subscribe({
            next: (page) => {
              const product = page.items.find((p) => p.id === line.product_id);
              if (product) {
                this.productUnits.set(product.units);
              }
            },
            error: () => undefined,
          }),
      );
    });
  }

  protected async submit(event: Event): Promise<void> {
    event.preventDefault();
    const line = this.line();
    const quantityBox = this.quantity();
    const unitBox = this.unit();
    if (!line || !quantityBox || !unitBox || this.busy()) {
      return;
    }
    const text = quantityBox.nativeElement.value.trim();
    const problem = checkDecimalInput(text, { label: 'Quantity', allowZero: false });
    if (problem !== null) {
      this.error.set(problem);
      return;
    }
    const request: UpdateLineRequest = { quantity: asQuantity(text) };
    if (unitBox.nativeElement.value !== line.unit_id) {
      request.unit_id = unitBox.nativeElement.value;
    }
    this.busy.set(true);
    try {
      const outcome = await this.store.updateLine(line.id, request);
      if (outcome.ok) {
        this.dialogs.close();
      }
    } finally {
      this.busy.set(false);
    }
  }
}
