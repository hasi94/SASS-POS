import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { tap } from 'rxjs';
import { AuthService } from './auth.service';

/** Adds the bearer token, and ends the session if the server says it is no longer valid. */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const token = auth.token();
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
