import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { NETWORK_MESSAGE } from '../../errors/refusal-catalogue';
import { Connectivity } from '../../state/connectivity';

/**
 * The persistent banner shown while the server cannot be reached (T-037 AC-74). Sale actions are
 * paused, nothing is queued, and the cart stays readable. The till asks again every few seconds
 * on its own; Retry asks now.
 */
@Component({
  selector: 'pos-network-banner',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (!connectivity.online()) {
      <div class="banner" role="alert" data-testid="network-banner">
        <span class="text">{{ message }}</span>
        <button type="button" (click)="retry()">Retry now</button>
      </div>
    }
  `,
  styles: `
    .banner {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 16px;
      background: var(--fp-danger);
      color: var(--fp-primary-text);
      font-weight: 600;
    }

    button {
      margin-left: auto;
      min-height: 44px;
    }
  `,
})
export class NetworkBanner {
  protected readonly connectivity = inject(Connectivity);
  protected readonly message = NETWORK_MESSAGE;

  protected retry(): void {
    void this.connectivity.retryNow();
  }
}
