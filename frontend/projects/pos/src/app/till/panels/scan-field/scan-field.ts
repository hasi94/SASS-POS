import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterNextRender,
  viewChild,
} from '@angular/core';

/**
 * The scan field (T-037 AC-21, AC-22). Stub: step B3 adds code entry, the `N*` multiplier and the
 * scan queue. Chunk 0 provides the input, carrying `data-till-scan-field` (the attribute
 * `DialogHost` looks for when a closing dialog returns the focus here), and focuses it when the
 * till opens.
 */
@Component({
  selector: 'pos-scan-field',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <label class="field">
      <span>Scan or type a code</span>
      <input
        #code
        type="text"
        autocomplete="off"
        spellcheck="false"
        data-till-scan-field
        data-testid="scan-field"
      />
    </label>
  `,
  styles: `
    .field {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    input {
      font: inherit;
      min-height: 44px;
      padding: 8px 12px;
      border: 1px solid var(--fp-border);
      border-radius: var(--fp-radius);
      background: var(--fp-bg);
      color: var(--fp-text);
    }
  `,
})
export class ScanField {
  private readonly code = viewChild.required<ElementRef<HTMLInputElement>>('code');

  constructor() {
    afterNextRender(() => this.code().nativeElement.focus());
  }
}
