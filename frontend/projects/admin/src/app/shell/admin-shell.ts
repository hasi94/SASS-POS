import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService, PermissionService } from '@flexipos/shared';
import { NavEntry, NavLink, isNavGroup, visibleNav } from './admin-nav';

/** Admin frame: navigation on the side, company and person on top. Areas load lazily. */
@Component({
  selector: 'adm-shell',
  imports: [RouterLink, RouterLinkActive, RouterOutlet],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <nav class="side" aria-label="Main">
      <strong class="brand">FlexiPOS</strong>
      @for (entry of menu(); track entry.label) {
        @if (link(entry); as item) {
          <a [routerLink]="item.path" routerLinkActive="active" ariaCurrentWhenActive="page">
            {{ item.label }}
          </a>
        } @else {
          @if (children(entry); as items) {
            <div role="group" [attr.aria-labelledby]="groupId(entry.label)" class="group">
              <span class="group-title" [id]="groupId(entry.label)">{{ entry.label }}</span>
              @for (item of items; track item.path) {
                <a [routerLink]="item.path" routerLinkActive="active" ariaCurrentWhenActive="page">
                  {{ item.label }}
                </a>
              }
            </div>
          }
        }
      }
    </nav>
    <div class="body">
      <header class="bar">
        @if (auth.tenant(); as tenant) {
          <strong>{{ tenant.name }}</strong>
        }
        @if (auth.branch(); as branch) {
          <span class="fp-muted">{{ branch.name }}</span>
        }
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
    .group {
      display: flex;
      flex-direction: column;
      gap: 4px;
      margin-top: 8px;
    }
    .group-title {
      padding: 4px 8px;
      font-size: 0.75rem;
      text-transform: uppercase;
      color: var(--fp-muted);
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
  private readonly permissions = inject(PermissionService);

  /** The menu for the permission keys held right now; it follows the session (AC-10). */
  protected readonly menu = computed(() => {
    const held = this.permissions.keys();
    return visibleNav((key) => held.has(key));
  });

  protected link(entry: NavEntry): NavLink | null {
    return isNavGroup(entry) ? null : entry;
  }

  protected children(entry: NavEntry): NavLink[] | null {
    return isNavGroup(entry) ? entry.children : null;
  }

  protected groupId(label: string): string {
    return `adm-nav-${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
  }

  protected switchCompany(): void {
    this.auth.clearContext();
    void this.router.navigate(['/context']);
  }

  protected signOut(): void {
    this.auth.logout();
    void this.router.navigate(['/login']);
  }
}
