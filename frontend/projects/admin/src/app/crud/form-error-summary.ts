import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/**
 * The refusals no field could take, at the top of a form (T-036 AC-17). The live region is
 * always in the page and only its text changes, so a screen reader announces the update
 * (review W-8); it is also where focus lands when no single control is to blame.
 */
@Component({
  selector: 'adm-form-error-summary',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="adm-form-summary fp-error" role="status" tabindex="-1">
      @if (messages().length > 0) {
        <ul>
          @for (m of messages(); track $index) {
            <li>{{ m }}</li>
          }
        </ul>
      }
    </div>
  `,
})
export class FormErrorSummary {
  readonly messages = input<readonly string[]>([]);
}
