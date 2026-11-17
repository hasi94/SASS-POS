import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { TillProduct } from '@flexipos/shared';
import { checkDecimalInput } from '../../state/decimal-input';
import { DialogHost } from '../../state/dialog-host';
import { DialogFrame } from '../../ui/dialog-frame';

/** What the opener passes: the weighable product to ask the weight of. */
export interface WeightPromptData {
  product: TillProduct;
}

function isWeightPromptData(data: unknown): data is WeightPromptData {
  return typeof data === 'object' && data !== null && 'product' in data;
}

/**
 * The weight of a weighable product that came without one (T-037 AC-26, D-15): from the grid, the
 * search, or a code that carries no quantity. A positive decimal string of at most four places;
 * zero, negative, empty, non-numeric and over-precise entries are refused here with a message and
 * nothing is sent. Enter confirms and closes with the text as typed; Esc closes with nothing.
 */
@Component({
  selector: 'pos-weight-prompt-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame],
  template: `
    <pos-dialog-frame heading="Weight">
      <form (submit)="confirm($event)" novalidate>
        <p data-testid="weight-product">{{ name() }}</p>
        <label class="field">
          <span>Weight in {{ unit() }}</span>
          <input
            #weight
            data-autofocus
            type="text"
            inputmode="decimal"
            autocomplete="off"
            data-testid="weight-input"
            [attr.aria-invalid]="error() ? 'true' : null"
            (input)="error.set(null)"
            (keydown.enter)="confirm($event)"
          />
        </label>
        @if (error(); as message) {
          <p class="fp-error" role="alert" data-testid="weight-error">{{ message }}</p>
        }
        <div class="actions">
          <button type="submit" class="fp-primary" data-testid="weight-ok">Add</button>
          <button type="button" (click)="dialogs.close()">Cancel</button>
        </div>
      </form>
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

    input {
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
export class WeightPromptDialog {
  protected readonly dialogs = inject(DialogHost);
  private readonly weight = viewChild.required<ElementRef<HTMLInputElement>>('weight');
  /** What the opener passed to `DialogHost.open`. */
  readonly data = input<unknown>();

  protected readonly error = signal<string | null>(null);
  private readonly product = computed(() => {
    const data = this.data();
    return isWeightPromptData(data) ? data.product : null;
  });
  protected readonly name = computed(() => this.product()?.name ?? '');
  protected readonly unit = computed(() => {
    const product = this.product();
    return product ? (product.weigh_unit_code ?? product.unit_code) : '';
  });

  protected confirm(event: Event): void {
    event.preventDefault();
    const text = this.weight().nativeElement.value.trim();
    const problem = checkDecimalInput(text, { label: 'Weight', allowZero: false });
    if (problem !== null) {
      this.error.set(problem);
      return;
    }
    this.dialogs.close(text);
  }
}
