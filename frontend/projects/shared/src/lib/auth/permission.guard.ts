import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { PermissionService } from './permission.service';

/**
 * Lets a person through only when they hold every key; otherwise redirects (default
 * `/forbidden`). An empty key list is never satisfied (deny by default, T-036 D-6).
 */
export function requirePermissions(
  keys: readonly string[],
  redirect = '/forbidden',
): CanActivateFn {
  return () =>
    inject(PermissionService).hasAll(keys) ? true : inject(Router).createUrlTree([redirect]);
}

/** As {@link requirePermissions}, but holding any one of the keys is enough. */
export function requireAnyPermission(
  keys: readonly string[],
  redirect = '/forbidden',
): CanActivateFn {
  return () =>
    inject(PermissionService).hasAny(keys) ? true : inject(Router).createUrlTree([redirect]);
}
