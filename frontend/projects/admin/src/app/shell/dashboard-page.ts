import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { AuthService } from '@flexipos/shared';

@Component({
  selector: 'adm-dashboard-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>Dashboard</h1>
    <p class="fp-muted">
      Signed in to {{ auth.tenant()?.name }} at {{ auth.branch()?.name }}. Reports arrive with
      FP-294.
    </p>
  `,
})
export class DashboardPage {
  protected readonly auth = inject(AuthService);
}
