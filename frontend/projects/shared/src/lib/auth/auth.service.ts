import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { ApiClient } from '../api/api-client';
import { LoginResult, LoginTenant, LogoutResult, SelectTenantResult, Session } from './auth.models';
import { SESSION_STORAGE_KEY } from './session-storage';

/**
 * Sign-in, company selection and branch context (Refference/ARCH.md §4–6).
 *
 * The client may *name* a tenant; the server decides whether the person may use it and
 * which database it lives in. Nothing here ever holds or sends a database name.
 *
 * Reconciled against the real T-009 endpoints (T-025, D-7/D-7a/D-8): `login()`,
 * `selectTenant()` and `logout()` call {@link ApiClient.postRaw} because none of these
 * bodies is wrapped in the envelope yet (T-021 D-1, until FP-369). The session holds the
 * **platform** token pair (from `login()`) and the **tenant** token pair (from the most
 * recent `selectTenant()`) side by side — `auth/tenant` requires a platform token
 * (`RequirePlatformToken`) and refuses a tenant token with `wrong_token_type`, so
 * overwriting the platform pair would break a later "change company".
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(ApiClient);
  private readonly storageKey = inject(SESSION_STORAGE_KEY);
  private readonly state = signal<Session | null>(this.restore());

  readonly session = this.state.asReadonly();
  readonly isAuthenticated = computed(() => this.state() !== null);

  /** The token sent on every request except `auth/tenant` (D-7a). */
  readonly token = computed(() => {
    const s = this.state();
    return s ? (s.tenantTokens?.accessToken ?? s.platform.accessToken) : null;
  });
  /** The token `auth/tenant` must always receive, even after a tenant token exists. */
  readonly platformToken = computed(() => this.state()?.platform.accessToken ?? null);

  /** Mock-only (D-6): never sourced from a real response field. */
  readonly user = computed(() => this.state()?.user ?? null);
  readonly tenants = computed<LoginTenant[]>(() => this.state()?.tenants ?? []);

  readonly tenant = computed<LoginTenant | null>(() => {
    const s = this.state();
    return s?.tenants.find((t) => t.id === s.tenantId) ?? null;
  });
  /** Mock-only (D-5): no real endpoint supplies a branch list. */
  readonly branch = computed(() => {
    const s = this.state();
    return this.tenant()?.branches?.find((b) => b.id === s?.branchId) ?? null;
  });
  /**
   * True once a company and a branch are chosen, i.e. business screens may open. With
   * the real backend this never becomes true (D-5: no real endpoint supplies branches) —
   * a logged, accepted gap, not a regression, since nothing talks to a real backend yet.
   */
  readonly hasContext = computed(() => this.tenant() !== null && this.branch() !== null);

  login(email: string, password: string): Observable<LoginResult> {
    return this.api.postRaw<LoginResult>('auth/login', { email, password }).pipe(
      tap((r) =>
        this.commit({
          platform: { accessToken: r.access_token, refreshToken: r.refresh_token },
          tenantTokens: null,
          tenants: r.tenants,
          defaultTenantId: r.default_tenant_id,
          mustChangePassword: r.must_change_password,
          tenantId: null,
          branchId: null,
          user: r.user,
          permissions: [],
        }),
      ),
    );
  }

  /** Ask the server to switch to a company; always sends the stored platform token (D-7a). */
  selectTenant(code: string): Observable<SelectTenantResult> {
    return this.api.postRaw<SelectTenantResult>('auth/tenant', { code }).pipe(
      tap((r) => {
        const s = this.state();
        if (s) {
          this.commit({
            ...s,
            tenantTokens: { accessToken: r.access_token, refreshToken: r.refresh_token },
            tenantId: r.tenant_id,
            branchId: null,
            permissions: r.permissions ?? [],
          });
        }
      }),
    );
  }

  /** Mock-only (D-5): the real backend has no endpoint to validate this against. */
  selectBranch(branchId: string): void {
    const s = this.state();
    if (s && this.tenant()?.branches?.some((b) => b.id === branchId)) {
      this.commit({ ...s, branchId });
    }
  }

  /** Drop the chosen company and branch but stay signed in, to switch company. */
  clearContext(): void {
    const s = this.state();
    if (s) {
      this.commit({ ...s, tenantTokens: null, tenantId: null, branchId: null, permissions: [] });
    }
  }

  /**
   * Clears local state synchronously, then fires one `auth/logout` per non-null refresh
   * token held (tenant, then platform). The real endpoint always answers 200, whatever
   * is presented, so any error here is swallowed.
   */
  logout(): void {
    const s = this.state();
    const refreshTokens = [s?.tenantTokens?.refreshToken, s?.platform.refreshToken].filter(
      (t): t is string => !!t,
    );
    this.commit(null);
    for (const refresh_token of refreshTokens) {
      this.api.postRaw<LogoutResult>('auth/logout', { refresh_token }).subscribe({ error: () => {} });
    }
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
      if (!raw) {
        return null;
      }
      const parsed = JSON.parse(raw) as Partial<Session>;
      // A stored session in the old T-012 shape (no `platform` pair) is discarded.
      if (!parsed.platform) {
        return null;
      }
      return parsed as Session;
    } catch {
      return null;
    }
  }
}
