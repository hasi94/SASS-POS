import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { DialogHost } from '../../state/dialog-host';
import { DialogFrame } from '../../ui/dialog-frame';

/**
 * Stub. Step B4 fills this dialog in: the server-offered discounts for a line (AC-35). Chunk 0 only fixes its selector, its `data`
 * input and its frame, so the dialog outlet on `TillPage` never has to change.
 */
@Component({
  selector: 'pos-discount-picker-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame],
  template: `
    <pos-dialog-frame heading="Discount">
      <p>This dialog arrives with step B4.</p>
      <button type="button" (click)="dialogs.close()">Close</button>
    </pos-dialog-frame>
  `,
})
export class DiscountPickerDialog {
  protected readonly dialogs = inject(DialogHost);
  /** What the opener passed to `DialogHost.open`. */
  readonly data = input<unknown>();
}
