import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { ApiClient } from '../api/api-client';
import { LoginResult, SelectTenantResult, Session, TenantSummary } from './auth.models';
import { SESSION_STORAGE_KEY } from './session-storage';

/**
 * Sign-in, company selection and branch context (Refference/ARCH.md §4–6).
 *
 * The client may *name* a tenant; the server decides whether the person may use it and
 * which database it lives in. Nothing here ever holds or sends a database name.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(ApiClient);
  private readonly storageKey = inject(SESSION_STORAGE_KEY);
  private readonly state = signal<Session | null>(this.restore());

  readonly session = this.state.asReadonly();
  readonly token = computed(() => this.state()?.token ?? null);
  readonly user = computed(() => this.state()?.user ?? null);
  readonly tenants = computed(() => this.state()?.tenants ?? []);
  readonly isAuthenticated = computed(() => this.state() !== null);

  readonly tenant = computed<TenantSummary | null>(() => {
    const s = this.state();
    return s?.tenants.find((t) => t.id === s.tenantId) ?? null;
  });
  readonly branch = computed(() => {
    const s = this.state();
    return this.tenant()?.branches.find((b) => b.id === s?.branchId) ?? null;
  });
  /** True once a company and a branch are chosen, i.e. business screens may open. */
  readonly hasContext = computed(() => this.tenant() !== null && this.branch() !== null);

  login(email: string, password: string): Observable<LoginResult> {
    return this.api.post<LoginResult>('auth/login', { email, password }).pipe(
      tap((r) =>
        this.commit({
          token: r.token,
          user: r.user,
          tenants: r.tenants,
          tenantId: null,
          branchId: null,
        }),
      ),
    );
  }

  /** Ask the server to switch to a company; the reply carries a token bound to it. */
  selectTenant(tenantId: string): Observable<SelectTenantResult> {
    return this.api.post<SelectTenantResult>('auth/select-tenant', { tenant_id: tenantId }).pipe(
      tap((r) => {
        const s = this.state();
        if (s) {
          this.commit({ ...s, token: r.token, tenantId: r.tenant.id, branchId: null });
        }
      }),
    );
  }

  selectBranch(branchId: string): void {
    const s = this.state();
    if (s && this.tenant()?.branches.some((b) => b.id === branchId)) {
      this.commit({ ...s, branchId });
    }
  }

  /** Drop the chosen company and branch but stay signed in, to switch company. */
  clearContext(): void {
    const s = this.state();
    if (s) {
      this.commit({ ...s, tenantId: null, branchId: null });
    }
  }

  logout(): void {
    this.commit(null);
  }

  private commit(next: Session | null): void {
    this.state.set(next);
    try {
      if (next) {
        sessionStorage.setItem(this.storageKey, JSON.stringify(next));
      } else {
        sessionStorage.removeItem(this.storageKey);
      }
    } catch {
      // Storage can be blocked; the in-memory session still works for this tab.
    }
  }

  private restore(): Session | null {
    try {
      const raw = sessionStorage.getItem(this.storageKey);
      return raw ? (JSON.parse(raw) as Session) : null;
    } catch {
      return null;
    }
  }
}
