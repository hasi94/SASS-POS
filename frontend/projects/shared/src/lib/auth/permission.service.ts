import { Injectable, computed, inject } from '@angular/core';
import { AuthService } from './auth.service';

/**
 * Answers "may the signed-in person do this?" from the permission keys on the session
 * (T-036 D-5, D-6). A convenience for menus and routes, never the authority: the server
 * decides, and every 403 is still shown as a permission message.
 *
 * Deny by default. An absent field, a non-array value or an empty list holds no keys, and
 * every check against an empty list is false, including `hasAny([])` and `hasAll([])`.
 */
@Injectable({ providedIn: 'root' })
export class PermissionService {
  private readonly auth = inject(AuthService);

  /** The held keys. A stored session is untrusted input, so only strings are kept. */
  readonly keys = computed<ReadonlySet<string>>(() => {
    const raw: unknown = this.auth.session()?.permissions;
    return new Set(Array.isArray(raw) ? raw.filter((k): k is string => typeof k === 'string') : []);
  });

  has(key: string): boolean {
    return this.keys().has(key);
  }

  /** True when at least one key is held; false for an empty list. */
  hasAny(keys: readonly string[]): boolean {
    const held = this.keys();
    return keys.some((k) => held.has(k));
  }

  /** True when every key is held; false for an empty list. */
  hasAll(keys: readonly string[]): boolean {
    const held = this.keys();
    return keys.length > 0 && keys.every((k) => held.has(k));
  }
}
