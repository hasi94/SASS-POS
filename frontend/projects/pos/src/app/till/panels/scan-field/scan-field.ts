import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterNextRender,
  inject,
  viewChild,
} from '@angular/core';
import { ScanQueue } from '../../state/scan-queue';

/**
 * The scan field (T-037 AC-21, AC-22, AC-81). A hardware scanner types the code and presses
 * Enter, so Enter hands the text to the scan queue and the field clears at once, ready for the
 * next code. The text is trimmed by the queue and sent as typed. `3*` before a code sets a
 * multiplier, shown as a chip until it is used or `Esc` clears it.
 *
 * The input carries `data-till-scan-field`, the attribute `DialogHost` looks for when a closing
 * dialog returns the focus here, and is focused when the till opens.
 */
@Component({
  selector: 'pos-scan-field',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <label class="field">
      <span>Scan or type a code</span>
      <span class="entry">
        @if (queue.multiplier(); as times) {
          <span class="chip" data-testid="multiplier-chip">
            Quantity {{ times }} for the next code (Esc cancels)
          </span>
        }
        <input
          #code
          type="text"
          autocomplete="off"
          spellcheck="false"
          data-till-scan-field
          data-testid="scan-field"
          (keydown.enter)="submit($event)"
          (keydown.escape)="escape($event)"
        />
      </span>
    </label>
  `,
  styles: `
    .field {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .entry {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .chip {
      padding: 6px 10px;
      border: 2px solid var(--fp-primary);
      border-radius: var(--fp-radius);
      font-weight: 700;
      white-space: nowrap;
    }

    input {
      flex: 1;
      min-width: 0;
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
  protected readonly queue = inject(ScanQueue);
  private readonly code = viewChild.required<ElementRef<HTMLInputElement>>('code');

  constructor() {
    afterNextRender(() => this.code().nativeElement.focus());
  }

  protected submit(event: Event): void {
    event.preventDefault();
    const input = this.code().nativeElement;
    const text = input.value;
    input.value = '';
    this.queue.submit(text);
  }

  /** Esc drops a waiting multiplier first; with none waiting it goes on to the page. */
  protected escape(event: Event): void {
    if (this.queue.clearMultiplier()) {
      event.preventDefault();
    }
  }
}
