import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';

/** Needs a signed-in person; otherwise sends them to `/login`. */
export const authGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  return auth.isAuthenticated() ? true : inject(Router).createUrlTree(['/login']);
};

/** Needs a company and branch chosen; otherwise sends them to `/context`. */
export const contextGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  if (!auth.isAuthenticated()) {
    return inject(Router).createUrlTree(['/login']);
  }
  return auth.hasContext() ? true : inject(Router).createUrlTree(['/context']);
};

/** For the login page: a signed-in person goes on to the company step instead. */
export const guestGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  return auth.isAuthenticated() ? inject(Router).createUrlTree(['/context']) : true;
};
