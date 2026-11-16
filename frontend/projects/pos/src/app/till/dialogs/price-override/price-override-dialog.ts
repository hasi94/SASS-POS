import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { DialogHost } from '../../state/dialog-host';
import { DialogFrame } from '../../ui/dialog-frame';

/**
 * Stub. Step B4 fills this dialog in: a price override with a supervisor approval (AC-34). Chunk 0 only fixes its selector, its `data`
 * input and its frame, so the dialog outlet on `TillPage` never has to change.
 */
@Component({
  selector: 'pos-price-override-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame],
  template: `
    <pos-dialog-frame heading="Price override">
      <p>This dialog arrives with step B4.</p>
      <button type="button" (click)="dialogs.close()">Close</button>
    </pos-dialog-frame>
  `,
})
export class PriceOverrideDialog {
  protected readonly dialogs = inject(DialogHost);
  /** What the opener passed to `DialogHost.open`. */
  readonly data = input<unknown>();
}
