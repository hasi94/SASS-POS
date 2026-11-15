import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '@flexipos/shared';

/**
 * The till shell: who is signed in, where, and the two areas the sale screen will fill.
 * The product grid, scanning and cart arrive with FP-236; this only proves the frame.
 */
@Component({
  selector: 'pos-till-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './till-page.html',
  styleUrl: './till-page.scss',
})
export class TillPage {
  protected readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected switchBranch(): void {
    this.auth.clearContext();
    void this.router.navigate(['/context']);
  }

  protected signOut(): void {
    this.auth.logout();
    void this.router.navigate(['/login']);
  }
}
