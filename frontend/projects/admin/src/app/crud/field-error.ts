import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/**
 * The message under one form control (T-036 AC-17). The control points at it with
 * `[attr.aria-describedby]` set to the same `id` whenever a message exists.
 */
@Component({
  selector: 'adm-field-error',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (message(); as text) {
      <p class="fp-error" [id]="id()">{{ text }}</p>
    }
  `,
})
export class FieldError {
  readonly id = input.required<string>();
  readonly message = input<string | null | undefined>(null);
}
