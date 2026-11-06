import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { MOCK_PASSWORD, MOCK_USER, mockBackendInterceptor } from '../mock/mock-backend';
import { authInterceptor } from './auth.interceptor';
import { AuthService } from './auth.service';
import { provideRouter } from '@angular/router';

function setup(): AuthService {
  TestBed.configureTestingModule({
    providers: [
      provideRouter([]),
      provideHttpClient(withInterceptors([authInterceptor, mockBackendInterceptor])),
    ],
  });
  return TestBed.inject(AuthService);
}

describe('AuthService', () => {
  beforeEach(() => sessionStorage.clear());

  it('starts signed out', () => {
    const auth = setup();
    expect(auth.isAuthenticated()).toBe(false);
    expect(auth.hasContext()).toBe(false);
  });

  it('signs in and lists the companies the server allows', async () => {
    const auth = setup();
    await firstValueFrom(auth.login(MOCK_USER.email, MOCK_PASSWORD));
    expect(auth.isAuthenticated()).toBe(true);
    expect(auth.tenants().length).toBeGreaterThan(0);
    expect(auth.hasContext()).toBe(false);
  });

  it('refuses a wrong password and stays signed out', async () => {
    const auth = setup();
    await expect(firstValueFrom(auth.login(MOCK_USER.email, 'nope'))).rejects.toMatchObject({
      status: 401,
    });
    expect(auth.isAuthenticated()).toBe(false);
  });

  it('has a context only after a company and a branch are chosen', async () => {
    const auth = setup();
    await firstValueFrom(auth.login(MOCK_USER.email, MOCK_PASSWORD));
    await firstValueFrom(auth.selectTenant('TEN001'));
    expect(auth.tenant()?.id).toBe('TEN001');
    expect(auth.hasContext()).toBe(false);

    auth.selectBranch('BR002');
    expect(auth.hasContext()).toBe(true);
    expect(auth.token()).toBe('mock-tenant-token-TEN001');
  });

  it('ignores a branch that belongs to another company', async () => {
    const auth = setup();
    await firstValueFrom(auth.login(MOCK_USER.email, MOCK_PASSWORD));
    await firstValueFrom(auth.selectTenant('TEN001'));
    auth.selectBranch('BR010');
    expect(auth.branch()).toBeNull();
  });

  it('rejects a company the person has no access to', async () => {
    const auth = setup();
    await firstValueFrom(auth.login(MOCK_USER.email, MOCK_PASSWORD));
    await expect(firstValueFrom(auth.selectTenant('TEN999'))).rejects.toMatchObject({
      status: 403,
    });
    expect(auth.tenant()).toBeNull();
  });

  it('restores the session in the same tab and forgets it on logout', async () => {
    const first = setup();
    await firstValueFrom(first.login(MOCK_USER.email, MOCK_PASSWORD));

    TestBed.resetTestingModule();
    const second = setup();
    expect(second.isAuthenticated()).toBe(true);

    second.logout();
    expect(second.isAuthenticated()).toBe(false);
    expect(sessionStorage.length).toBe(0);
  });
});
