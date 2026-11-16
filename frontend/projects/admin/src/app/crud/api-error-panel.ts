import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { describeApiError } from './describe-api-error';

/**
 * The error panel of a list or detail screen (T-036 AC-8, AC-16): the message as text (never
 * HTML), the request id when there is one, and a Retry action.
 */
@Component({
  selector: 'adm-api-error-panel',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="adm-error-panel" role="alert">
      <p class="adm-error-message">{{ view().message }}</p>
      @if (view().requestId; as id) {
        <p class="fp-muted">Request id: {{ id }}</p>
      }
      @if (retryable()) {
        <button type="button" (click)="retry.emit()">Retry</button>
      }
    </div>
  `,
})
export class ApiErrorPanel {
  /** The failure: normally an `ApiError`; anything else gets a generic message. */
  readonly error = input.required<unknown>();
  readonly retryable = input(true);
  readonly retry = output<void>();

  protected readonly view = computed(() => describeApiError(this.error()));
}
