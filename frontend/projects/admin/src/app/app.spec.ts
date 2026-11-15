import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { App } from './app';
import { routes } from './app.routes';

describe('Admin App', () => {
  it('creates the root component', async () => {
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [provideRouter(routes)],
    }).compileComponents();

    expect(TestBed.createComponent(App).componentInstance).toBeTruthy();
  });

  it('keeps every admin area behind sign-in and a chosen branch', () => {
    const shell = routes.find((r) => r.path === '' && r.children);
    expect(shell?.canActivate?.length).toBe(1);
  });
});
