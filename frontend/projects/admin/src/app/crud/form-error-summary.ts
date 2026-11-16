import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/** The refusals no field could take, at the top of a form (T-036 AC-17). */
@Component({
  selector: 'adm-form-error-summary',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (messages().length > 0) {
      <ul class="adm-form-summary fp-error" role="alert">
        @for (m of messages(); track $index) {
          <li>{{ m }}</li>
        }
      </ul>
    }
  `,
})
export class FormErrorSummary {
  readonly messages = input<readonly string[]>([]);
}
