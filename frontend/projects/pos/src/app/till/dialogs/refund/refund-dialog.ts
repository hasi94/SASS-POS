import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { DialogHost } from '../../state/dialog-host';
import { DialogFrame } from '../../ui/dialog-frame';

/**
 * Stub. Step A4 fills this dialog in: referenced and goodwill refunds (AC-59 to AC-64). Chunk 0 only fixes its selector, its `data`
 * input and its frame, so the dialog outlet on `TillPage` never has to change.
 */
@Component({
  selector: 'pos-refund-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame],
  template: `
    <pos-dialog-frame heading="Refund">
      <p>This dialog arrives with step A4.</p>
      <button type="button" (click)="dialogs.close()">Close</button>
    </pos-dialog-frame>
  `,
})
export class RefundDialog {
  protected readonly dialogs = inject(DialogHost);
  /** What the opener passed to `DialogHost.open`. */
  readonly data = input<unknown>();
}
