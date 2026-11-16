import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { DialogHost } from '../../state/dialog-host';
import { DialogFrame } from '../../ui/dialog-frame';

/**
 * Stub. Step B6 fills this dialog in: the branch list of held sales (AC-52). Chunk 0 only fixes its selector, its `data`
 * input and its frame, so the dialog outlet on `TillPage` never has to change.
 */
@Component({
  selector: 'pos-recall-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame],
  template: `
    <pos-dialog-frame heading="Recall a held sale">
      <p>This dialog arrives with step B6.</p>
      <button type="button" (click)="dialogs.close()">Close</button>
    </pos-dialog-frame>
  `,
})
export class RecallDialog {
  protected readonly dialogs = inject(DialogHost);
  /** What the opener passed to `DialogHost.open`. */
  readonly data = input<unknown>();
}
