import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from '@flexipos/shared';

/** Admin frame: navigation on the side, company and person on top. Areas load lazily. */
@Component({
  selector: 'adm-shell',
  imports: [RouterLink, RouterLinkActive, RouterOutlet],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <nav class="side" aria-label="Main">
      <strong class="brand">FlexiPOS</strong>
      @for (item of nav; track item.path) {
        <a [routerLink]="item.path" routerLinkActive="active">{{ item.label }}</a>
      }
    </nav>
    <div class="body">
      <header class="bar">
        <strong>{{ auth.tenant()?.name }}</strong>
        <span class="fp-muted">{{ auth.branch()?.name }}</span>
        <span class="spacer"></span>
        @if (auth.user(); as u) {
          <span class="fp-muted">{{ u.name }}</span>
        }
        <button type="button" (click)="switchCompany()">Switch</button>
        <button type="button" (click)="signOut()">Sign out</button>
      </header>
      <main><router-outlet /></main>
    </div>
  `,
  styles: `
    :host {
      display: flex;
      min-height: 100vh;
    }
    .side {
      width: 200px;
      display: flex;
      flex-direction: column;
      gap: 4px;
      padding: 16px 12px;
      background: var(--fp-surface);
      border-right: 1px solid var(--fp-border);
    }
    .brand {
      padding: 0 8px 12px;
    }
    .side a {
      padding: 8px;
      border-radius: var(--fp-radius);
      color: var(--fp-text);
      text-decoration: none;
    }
    .side a.active {
      background: var(--fp-bg);
      font-weight: 600;
    }
    .body {
      flex: 1;
      display: flex;
      flex-direction: column;
    }
    .bar {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 16px;
      background: var(--fp-surface);
      border-bottom: 1px solid var(--fp-border);
    }
    .spacer {
      flex: 1;
    }
    main {
      padding: 24px;
    }
  `,
})
export class AdminShell {
  protected readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly nav = [
    { path: '/dashboard', label: 'Dashboard' },
    { path: '/catalog', label: 'Catalog' },
    { path: '/customers', label: 'Customers' },
  ];

  protected switchCompany(): void {
    this.auth.clearContext();
    void this.router.navigate(['/context']);
  }

  protected signOut(): void {
    this.auth.logout();
    void this.router.navigate(['/login']);
  }
}
