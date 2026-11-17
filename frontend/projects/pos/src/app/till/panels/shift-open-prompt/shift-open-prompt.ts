import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterNextRender,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { Connectivity } from '../../state/connectivity';
import { DialogHost } from '../../state/dialog-host';
import { checkDecimalInput } from '../../state/decimal-input';
import { ShiftStore } from '../../state/shift-store';

/**
 * Asks for the opening cash when the terminal has no open shift (T-037 AC-9, AC-10). `TillPage`
 * shows it only once the shift has loaded and there is none. The amount is checked here, before
 * any call, and a wrong one is named by field; a second submit while one is in flight is ignored.
 */
@Component({
  selector: 'pos-shift-open-prompt',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section aria-labelledby="pos-shift-title" data-testid="shift-open-prompt">
      <h2 id="pos-shift-title">Open the shift</h2>
      <p class="fp-muted">Count the cash in the drawer, then enter it to start selling.</p>
      <form (submit)="submit($event)" novalidate>
        <label class="field">
          <span>Opening cash</span>
          <input
            #cash
            type="text"
            inputmode="decimal"
            autocomplete="off"
            data-testid="opening-cash"
            [attr.aria-invalid]="error() ? 'true' : null"
            [attr.aria-describedby]="error() ? 'pos-shift-error' : null"
            (input)="error.set(null)"
          />
        </label>
        @if (error(); as message) {
          <p class="fp-error" id="pos-shift-error" role="alert" data-testid="opening-cash-error">
            {{ message }}
          </p>
        }
        <button
          type="submit"
          class="fp-primary"
          data-testid="opening-cash-submit"
          [disabled]="shift.busy() || !online()"
        >
          Open shift
        </button>
      </form>
    </section>
  `,
  styles: `
    h2 {
      margin: 0 0 8px;
      font-size: 1.1rem;
    }

    form {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-top: 8px;
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

    button {
      min-height: 44px;
    }
  `,
})
export class ShiftOpenPrompt {
  protected readonly shift = inject(ShiftStore);
  protected readonly online = inject(Connectivity).online;
  private readonly dialogs = inject(DialogHost);
  private readonly cash = viewChild.required<ElementRef<HTMLInputElement>>('cash');
  protected readonly error = signal<string | null>(null);

  constructor() {
    afterNextRender(() => this.cash().nativeElement.focus());
  }

  protected async submit(event: Event): Promise<void> {
    event.preventDefault();
    if (this.shift.busy()) {
      return;
    }
    const text = this.cash().nativeElement.value;
    const problem = checkDecimalInput(text, { label: 'Opening cash', allowZero: true });
    if (problem !== null) {
      this.error.set(problem);
      this.cash().nativeElement.focus();
      return;
    }
    if (await this.shift.openShift(text.trim())) {
      this.dialogs.focusScanField();
    }
  }
}
