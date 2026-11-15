import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { App } from './app';
import { routes } from './app.routes';

describe('Till App', () => {
  it('creates the root component', async () => {
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [provideRouter(routes)],
    }).compileComponents();

    expect(TestBed.createComponent(App).componentInstance).toBeTruthy();
  });

  it('keeps the till behind sign-in and a chosen branch', () => {
    const till = routes.find((r) => r.path === 'till');
    expect(till?.canActivate?.length).toBe(1);
  });
});
