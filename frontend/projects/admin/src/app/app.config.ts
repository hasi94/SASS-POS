import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { API_BASE_URL, SESSION_STORAGE_KEY, authInterceptor } from '@flexipos/shared';
import { apiBaseUrl, devInterceptors } from '../environments/environment';
import { routes } from './app.routes';

// The mock backend sits after the auth interceptor so it sees the Authorization header.
const interceptors = [authInterceptor, ...devInterceptors];

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes, withComponentInputBinding()),
    provideHttpClient(withInterceptors(interceptors)),
    { provide: API_BASE_URL, useValue: apiBaseUrl },
    { provide: SESSION_STORAGE_KEY, useValue: 'flexipos.admin.session' },
  ],
};
