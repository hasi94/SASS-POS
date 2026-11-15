import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { authInterceptor } from '../auth/auth.interceptor';
import { MOCK_PASSWORD, MOCK_USER, mockBackendInterceptor } from '../mock/mock-backend';
import { LoginPage } from './login-page';

describe('LoginPage', () => {
  beforeEach(() => {
    sessionStorage.clear();
    TestBed.configureTestingModule({
      imports: [LoginPage],
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor, mockBackendInterceptor])),
      ],
    });
  });

  function fill(root: HTMLElement, email: string, password: string): void {
    const [e, p] = Array.from(root.querySelectorAll('input'));
    e.value = email;
    e.dispatchEvent(new Event('input'));
    p.value = password;
    p.dispatchEvent(new Event('input'));
  }

  it('disables the button until the form is valid', async () => {
    const fixture = TestBed.createComponent(LoginPage);
    await fixture.whenStable();
    const button = fixture.nativeElement.querySelector('button') as HTMLButtonElement;
    expect(button.disabled).toBe(true);

    fill(fixture.nativeElement, MOCK_USER.email, MOCK_PASSWORD);
    await fixture.whenStable();
    expect(button.disabled).toBe(false);
  });

  it('shows the server message on a bad password', async () => {
    const fixture = TestBed.createComponent(LoginPage);
    await fixture.whenStable();
    fill(fixture.nativeElement, MOCK_USER.email, 'wrong');
    fixture.nativeElement.querySelector('form').dispatchEvent(new Event('submit'));

    await new Promise((r) => setTimeout(r, 400));
    await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('[role="alert"]')?.textContent).toContain(
      'Email or password is wrong.',
    );
  });

  it('moves on to company selection after a good sign-in', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    const fixture = TestBed.createComponent(LoginPage);
    await fixture.whenStable();
    fill(fixture.nativeElement, MOCK_USER.email, MOCK_PASSWORD);
    fixture.nativeElement.querySelector('form').dispatchEvent(new Event('submit'));

    await new Promise((r) => setTimeout(r, 400));
    expect(navigate).toHaveBeenCalledWith(['/context']);
  });
});
