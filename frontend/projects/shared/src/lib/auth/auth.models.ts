/**
 * Mock-only (D-6): no real response field supplies this. `LoginResult` carries no user
 * object at all — the subject lives in the access token's `sub` claim. Kept as a
 * convenience for the mock backend and any UI that wants a friendly name; never read as
 * if the real backend supplied it.
 */
export interface AuthUser {
  id: string;
  name: string;
  email: string;
}

/**
 * Mock-only (D-5): no real endpoint supplies a branch list or lets a session pick a
 * branch. T-014 ties branch context to terminal activation, a device-level process, not
 * a per-employee-session choice.
 */
export interface Branch {
  id: string;
  name: string;
}

/** One item of `LoginResult.tenants` (real: `packages/auth/src/Features/Login/LoginResult.php`). */
export interface LoginTenant {
  id: string;
  code: string;
  name: string;
  status: string;
  selectable: boolean;
  is_default: boolean;
  /** Mock-only (D-5): not part of the real `LoginResult` tenant item. */
  branches?: Branch[];
}

/** A company the signed-in person may use. Never carries a database name or host. */
export type TenantSummary = LoginTenant;

/** An access/refresh token pair, as every real auth endpoint returns one. */
export interface TokenPair {
  accessToken: string;
  refreshToken: string;
}

/** Real, shipped reply to `POST auth/login`. No `user` object (D-6), no `branches` (D-5). */
export interface LoginResult {
  access_token: string;
  refresh_token: string;
  tenants: LoginTenant[];
  default_tenant_id: string | null;
  must_change_password: boolean;
  /** Mock-only (D-6): never sourced from a real response field. */
  user?: AuthUser;
}

/** Real, shipped reply to `POST auth/tenant`. Two tokens, no nested `tenant` object. */
export interface SelectTenantResult {
  access_token: string;
  refresh_token: string;
  tenant_id: string;
  tenant_code: string;
  /**
   * Mock-only (T-036 D-5): no real endpoint supplies the signed-in person's permission keys
   * (B-1 proposes one). The real backend sends nothing, so the client set is empty and denies.
   */
  permissions?: string[];
}

/** Real, shipped reply to `POST auth/logout`: always `{status:"signed_out"}`. */
export interface LogoutResult {
  status: string;
}

/** What the client keeps between page loads, under the existing per-app `sessionStorage` key. */
export interface Session {
  /** From `login()`; reused for every `auth/tenant` call (D-7a). */
  platform: TokenPair;
  /** From the most recent `selectTenant()`, or null before one is chosen. */
  tenantTokens: TokenPair | null;
  tenants: LoginTenant[];
  defaultTenantId: string | null;
  /** Stored, not acted on: no UI asks for it yet. */
  mustChangePassword: boolean;
  tenantId: string | null;
  /** Mock-only (D-5): no real endpoint supplies this. */
  branchId: string | null;
  /** Mock-only (D-6): never sourced from a real response field. */
  user?: AuthUser;
  /**
   * Permission keys of the signed-in person for the chosen company (T-036 D-5). Absent or
   * empty means deny (D-6). Mock-only source today; see {@link SelectTenantResult.permissions}.
   */
  permissions?: string[];
}
