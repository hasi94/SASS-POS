import { HttpInterceptorFn } from '@angular/common/http';
import { mockBackendInterceptor, mockCatalogInterceptor } from '@flexipos/shared';

// Development: the mock backend answers until the real API exists (FP-215).
export const apiBaseUrl = '/api/v1';
export const devInterceptors: HttpInterceptorFn[] = [
  mockCatalogInterceptor,
  mockBackendInterceptor,
];
