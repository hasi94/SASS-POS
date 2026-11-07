import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { authInterceptor } from '../auth/auth.interceptor';
import { AuthService } from '../auth/auth.service';
import { MOCK_PASSWORD, MOCK_TENANTS, MOCK_USER, mockBackendInterceptor } from '../mock/mock-backend';
import { ContextPage } from './context-page';

describe('ContextPage', () => {
  beforeEach(() => {
    sessionStorage.clear();
    TestBed.configureTestingModule({
      imports: [ContextPage],
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor, mockBackendInterceptor])),
      ],
    });
  });

  async function signIn(): Promise<void> {
    await TestBed.inject(AuthService).login(MOCK_USER.email, MOCK_PASSWORD).toPromise();
  }

  it('disables a non-selectable tenant and shows its status, without letting it be clicked', async () => {
    await signIn();
    const fixture = TestBed.createComponent(ContextPage);
    await fixture.whenStable();

    const buttons = Array.from(
      fixture.nativeElement.querySelectorAll('li button'),
    ) as HTMLButtonElement[];
    const suspended = MOCK_TENANTS.find((t) => !t.selectable)!;
    const button = buttons.find((b) => b.textContent?.includes(suspended.name));

    expect(button).toBeTruthy();
    expect(button!.disabled).toBe(true);
    expect(button!.closest('li')?.textContent).toContain(suspended.status);

    const auth = TestBed.inject(AuthService);
    const spy = vi.spyOn(auth, 'selectTenant');
    button!.click();
    await fixture.whenStable();
    expect(spy).not.toHaveBeenCalled();
  });

  it('keeps a selectable tenant clickable', async () => {
    await signIn();
    const fixture = TestBed.createComponent(ContextPage);
    await fixture.whenStable();

    const buttons = Array.from(
      fixture.nativeElement.querySelectorAll('li button'),
    ) as HTMLButtonElement[];
    const selectable = MOCK_TENANTS.find((t) => t.selectable)!;
    const button = buttons.find((b) => b.textContent?.includes(selectable.name));

    expect(button).toBeTruthy();
    expect(button!.disabled).toBe(false);
  });
});
