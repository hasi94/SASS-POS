import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { tap } from 'rxjs';
import { AuthService } from './auth.service';

/**
 * Adds the bearer token, and ends the session if the server says it is no longer valid.
 *
 * `auth/tenant` requires a **platform** token (`RequirePlatformToken`, D-7a): sending the
 * most recently received token there would risk sending a tenant token, which that
 * endpoint refuses with 403 `wrong_token_type`. Every other request gets the normal
 * `auth.token()` (tenant token once one exists, otherwise the platform token).
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const token = req.url.endsWith('/auth/tenant') ? auth.platformToken() : auth.token();
  const authed = token ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } }) : req;

  return next(authed).pipe(
    tap({
      error: (err: unknown) => {
        const isLogin = req.url.endsWith('/auth/login');
        if (err instanceof HttpErrorResponse && err.status === 401 && token && !isLogin) {
          auth.logout();
          void router.navigate(['/login']);
        }
      },
    }),
  );
};
