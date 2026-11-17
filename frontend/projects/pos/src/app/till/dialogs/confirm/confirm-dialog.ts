import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { DialogHost } from '../../state/dialog-host';
import { DialogFrame } from '../../ui/dialog-frame';

/** What the confirm dialog is opened with (`DialogHost.open('confirm', data)`). */
export interface ConfirmDialogData {
  heading: string;
  message: string;
  /** The label of the button that says yes; the dialog then closes with `true`. */
  confirmLabel: string;
  /** The label of the button that says no; the dialog then closes with nothing (Esc does too). */
  cancelLabel: string;
}

export function isConfirmData(data: unknown): data is ConfirmDialogData {
  if (typeof data !== 'object' || data === null) {
    return false;
  }
  const d = data as Record<string, unknown>;
  return (
    typeof d['heading'] === 'string' &&
    typeof d['message'] === 'string' &&
    typeof d['confirmLabel'] === 'string' &&
    typeof d['cancelLabel'] === 'string'
  );
}

/**
 * A plain question with two answers (T-037 AC-54): the till asks whether to hold the sale in the
 * cart before another is recalled, and never discards a sale without being told to. The yes
 * button has the focus, so Enter answers yes; Esc and the other button answer no.
 */
@Component({
  selector: 'pos-confirm-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame],
  template: `
    <pos-dialog-frame [heading]="heading()">
      <p data-testid="confirm-message">{{ message() }}</p>
      <div class="actions">
        <button
          type="button"
          class="primary"
          data-autofocus
          data-testid="confirm-yes"
          (click)="dialogs.close(true)"
        >
          {{ confirmLabel() }}
        </button>
        <button type="button" data-testid="confirm-no" (click)="dialogs.close()">
          {{ cancelLabel() }}
        </button>
      </div>
    </pos-dialog-frame>
  `,
  styles: `
    .actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    button {
      min-height: 44px;
      padding: 0 16px;
    }
  `,
})
export class ConfirmDialog {
  protected readonly dialogs = inject(DialogHost);
  /** What the opener passed to `DialogHost.open`. */
  readonly data = input<unknown>();

  private readonly given = computed<ConfirmDialogData | null>(() => {
    const data = this.data();
    return isConfirmData(data) ? data : null;
  });
  protected readonly heading = computed(() => this.given()?.heading ?? 'Are you sure?');
  protected readonly message = computed(() => this.given()?.message ?? '');
  protected readonly confirmLabel = computed(() => this.given()?.confirmLabel ?? 'Yes');
  protected readonly cancelLabel = computed(() => this.given()?.cancelLabel ?? 'No');
}
