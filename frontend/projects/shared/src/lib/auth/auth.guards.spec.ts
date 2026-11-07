import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import {
  ActivatedRouteSnapshot,
  Router,
  RouterStateSnapshot,
  UrlTree,
  provideRouter,
} from '@angular/router';
import { authGuard, contextGuard, guestGuard } from './auth.guards';
import { Session } from './auth.models';

const KEY = 'flexipos.session';

function seed(tenantId: string | null, branchId: string | null): void {
  const session: Session = {
    platform: { accessToken: 'pt', refreshToken: 'prt' },
    tenantTokens: tenantId ? { accessToken: 'tt', refreshToken: 'trt' } : null,
    user: { id: '1', name: 'A', email: 'a@b.c' },
    tenants: [
      {
        id: 'T',
        code: 't',
        name: 'T',
        status: 'active',
        selectable: true,
        is_default: true,
        branches: [{ id: 'B', name: 'B' }],
      },
    ],
    defaultTenantId: 'T',
    mustChangePassword: false,
    tenantId,
    branchId,
  };
  sessionStorage.setItem(KEY, JSON.stringify(session));
}

function run(guard: typeof authGuard): boolean | UrlTree {
  return TestBed.runInInjectionContext(() =>
    guard({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot),
  ) as boolean | UrlTree;
}

describe('route guards', () => {
  let router: Router;

  beforeEach(() => {
    sessionStorage.clear();
    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()],
    });
    router = TestBed.inject(Router);
  });

  it('sends a signed-out person to /login', () => {
    expect(router.serializeUrl(run(authGuard) as UrlTree)).toBe('/login');
    expect(router.serializeUrl(run(contextGuard) as UrlTree)).toBe('/login');
    expect(run(guestGuard)).toBe(true);
  });

  it('sends a signed-in person without a branch to /context', () => {
    seed(null, null);
    expect(run(authGuard)).toBe(true);
    expect(router.serializeUrl(run(contextGuard) as UrlTree)).toBe('/context');
    expect(router.serializeUrl(run(guestGuard) as UrlTree)).toBe('/context');
  });

  it('lets a person with a company and branch through', () => {
    seed('T', 'B');
    expect(run(contextGuard)).toBe(true);
  });
});
