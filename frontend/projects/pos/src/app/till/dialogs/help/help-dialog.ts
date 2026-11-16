import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { KEYMAP, formatBinding } from '../../keyboard/keymap';
import { DialogHost } from '../../state/dialog-host';
import { DialogFrame } from '../../ui/dialog-frame';

/** The shortcut list (T-037 AC-80): every binding of the key map, read from `KEYMAP`. */
@Component({
  selector: 'pos-help-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DialogFrame],
  template: `
    <pos-dialog-frame heading="Keyboard shortcuts">
      <dl class="keys" data-testid="shortcut-list">
        @for (entry of entries; track entry.action) {
          <div class="row">
            <dt>
              @for (binding of entry.keys; track binding.key; let last = $last) {
                <kbd>{{ show(binding) }}</kbd>
                @if (!last) {
                  <span> or </span>
                }
              }
            </dt>
            <dd>{{ entry.label }}</dd>
          </div>
        }
        <div class="row">
          <dt><kbd>3*</kbd></dt>
          <dd>Type a quantity and a star before a code to scan that many</dd>
        </div>
      </dl>
      <button type="button" data-autofocus (click)="dialogs.close()">Close</button>
    </pos-dialog-frame>
  `,
  styles: `
    .keys {
      margin: 0;
      display: grid;
      gap: 6px;
    }

    .row {
      display: grid;
      grid-template-columns: 11rem 1fr;
      gap: 12px;
    }

    dt,
    dd {
      margin: 0;
    }

    kbd {
      padding: 2px 6px;
      border: 1px solid var(--fp-border);
      border-radius: 4px;
      background: var(--fp-bg);
      font-family: inherit;
    }

    button {
      min-height: 44px;
    }
  `,
})
export class HelpDialog {
  protected readonly dialogs = inject(DialogHost);
  protected readonly entries = KEYMAP;
  protected readonly show = formatBinding;
  /** What the opener passed to `DialogHost.open`. */
  readonly data = input<unknown>();
}
