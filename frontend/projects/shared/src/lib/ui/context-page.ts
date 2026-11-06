import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { Router } from '@angular/router';
import { ApiError } from '../api/envelope';
import { AuthService } from '../auth/auth.service';

/** After sign-in: choose a company (ARCH.md §6), then a branch. */
@Component({
  selector: 'fp-context-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="fp-auth">
      <section class="fp-card">
        <h1>Welcome, {{ auth.user()?.name }}</h1>

        @if (!auth.tenant()) {
          <p class="fp-muted">Choose a company</p>
          <ul class="fp-choices">
            @for (t of auth.tenants(); track t.id) {
              <li>
                <button type="button" [disabled]="busy()" (click)="pickTenant(t.id)">
                  {{ t.name }}
                </button>
              </li>
            } @empty {
              <li class="fp-muted">No companies are linked to this account.</li>
            }
          </ul>
        } @else {
          <p class="fp-muted">{{ auth.tenant()?.name }} — choose a branch</p>
          <ul class="fp-choices">
            @for (b of auth.tenant()?.branches; track b.id) {
              <li>
                <button type="button" (click)="pickBranch(b.id)">{{ b.name }}</button>
              </li>
            }
          </ul>
          <button type="button" class="fp-link" (click)="auth.clearContext()">
            Change company
          </button>
        }

        @if (error(); as message) {
          <p class="fp-error" role="alert">{{ message }}</p>
        }
        <button type="button" class="fp-link" (click)="signOut()">Sign out</button>
      </section>
    </main>
  `,
})
export class ContextPage {
  protected readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  /** Where to go once a company and branch are chosen. */
  readonly landing = input('/');

  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);

  protected pickTenant(id: string): void {
    this.busy.set(true);
    this.error.set(null);
    this.auth.selectTenant(id).subscribe({
      next: () => {
        this.busy.set(false);
        const branches = this.auth.tenant()?.branches ?? [];
        if (branches.length === 1) {
          this.pickBranch(branches[0].id);
        }
      },
      error: (err: ApiError) => {
        this.busy.set(false);
        this.error.set(err.message);
      },
    });
  }

  protected pickBranch(id: string): void {
    this.auth.selectBranch(id);
    void this.router.navigateByUrl(this.landing());
  }

  protected signOut(): void {
    this.auth.logout();
    void this.router.navigate(['/login']);
  }
}
