import { ChangeDetectionStrategy, Component } from '@angular/core';

/**
 * Asks for the opening cash when the terminal has no open shift (T-037 AC-9, AC-10). Stub: step
 * B1 fills it in. `TillPage` shows it only once the shift has loaded and there is none.
 */
@Component({
  selector: 'pos-shift-open-prompt',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section aria-labelledby="pos-shift-title" data-testid="shift-open-prompt">
      <h2 id="pos-shift-title">Open the shift</h2>
      <p class="fp-muted">Opening the shift arrives with step B1.</p>
    </section>
  `,
  styles: `
    h2 {
      margin: 0 0 8px;
      font-size: 1.1rem;
    }
  `,
})
export class ShiftOpenPrompt {}
