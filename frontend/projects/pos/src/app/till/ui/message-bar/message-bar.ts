import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { Notifier } from '../../state/notifier';

/**
 * Shows the message the cashier needs to read now, and carries the two live regions (T-037
 * AC-27, AC-84): refusals are written to an alert region, everything else to a polite status
 * region, so a screen reader announces them. The regions are always in the page; only their text
 * changes. `Esc` removes the message (`Notifier.dismiss`).
 */
@Component({
  selector: 'pos-message-bar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="sr-only" role="status" aria-live="polite" data-testid="polite-region">
      {{ notifier.polite() }}
    </div>
    <div class="sr-only" role="alert" aria-live="assertive" data-testid="assertive-region">
      {{ notifier.assertive() }}
    </div>
    @if (notifier.current(); as message) {
      <div class="bar" [class.refusal]="message.kind === 'refusal'" data-testid="message-bar">
        <span class="kind">{{ message.kind === 'refusal' ? 'Problem' : 'Note' }}</span>
        <span class="text">{{ message.text }}</span>
        @if (message.detail) {
          <span class="detail">{{ message.detail }}</span>
        }
        @if (message.next && message.next.kind !== 'none') {
          <span class="next">Next: {{ message.next.label }}</span>
        }
        <button type="button" (click)="notifier.dismiss()">Dismiss (Esc)</button>
      </div>
    }
  `,
  styles: `
    :host {
      display: block;
    }

    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip-path: inset(50%);
      white-space: nowrap;
    }

    .bar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 12px;
      padding: 10px 16px;
      background: var(--fp-surface);
      border-top: 3px solid var(--fp-primary);
    }

    .bar.refusal {
      border-top-color: var(--fp-danger);
    }

    .kind {
      font-weight: 700;
    }

    .detail,
    .next {
      color: var(--fp-muted);
    }

    button {
      margin-left: auto;
      min-height: 44px;
    }
  `,
})
export class MessageBar {
  protected readonly notifier = inject(Notifier);
}
