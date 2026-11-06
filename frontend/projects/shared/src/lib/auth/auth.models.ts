export interface AuthUser {
  id: string;
  name: string;
  email: string;
}

export interface Branch {
  id: string;
  name: string;
}

/** A company the signed-in person may use. Never carries a database name or host. */
export interface TenantSummary {
  id: string;
  name: string;
  branches: Branch[];
}

/** Reply to `POST auth/login`: identity only, no tenant yet. */
export interface LoginResult {
  token: string;
  user: AuthUser;
  tenants: TenantSummary[];
}

/** Reply to `POST auth/select-tenant`: a token that now carries the tenant context. */
export interface SelectTenantResult {
  token: string;
  tenant: TenantSummary;
}

/** What the client keeps between page loads. */
export interface Session {
  token: string;
  user: AuthUser;
  tenants: TenantSummary[];
  tenantId: string | null;
  branchId: string | null;
}
