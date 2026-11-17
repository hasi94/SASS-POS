import { inject } from '@angular/core';
import { CanActivateFn, CanMatchFn, Router } from '@angular/router';
import { PermissionService } from './permission.service';

/**
 * Lets a person through only when they hold every key; otherwise redirects (default
 * `/forbidden`). An empty key list is never satisfied (deny by default, T-036 D-6). It does
 * not read its arguments, so the same guard serves `canActivate` and `canMatch`; on a lazy
 * route `canMatch` also keeps the area's chunk from downloading (T-036 review W-7).
 */
export function requirePermissions(
  keys: readonly string[],
  redirect = '/forbidden',
): CanActivateFn & CanMatchFn {
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
