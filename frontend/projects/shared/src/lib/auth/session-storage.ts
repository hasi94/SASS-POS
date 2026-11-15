import { InjectionToken } from '@angular/core';

/** Key under which the session lives in `sessionStorage`. Each app overrides it so the two never share one. */
export const SESSION_STORAGE_KEY = new InjectionToken<string>('SESSION_STORAGE_KEY', {
  providedIn: 'root',
  factory: () => 'flexipos.session',
});
