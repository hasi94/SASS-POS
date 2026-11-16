import { Injectable, computed, inject } from '@angular/core';
import { AuthService } from '@flexipos/shared';

/**
 * Who and where the till is, read from the existing session (T-037 AC-86). The till never sends a
 * branch, terminal, location or business date in a request: the server resolves them from the
 * session, so these are for display only.
 */
@Injectable()
export class TillContext {
  private readonly auth = inject(AuthService);

  readonly tenantName = computed(() => this.auth.tenant()?.name ?? '');
  readonly branchName = computed(() => this.auth.branch()?.name ?? '');
  readonly employeeName = computed(() => this.auth.user()?.name ?? '');
}
