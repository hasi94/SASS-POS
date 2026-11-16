import { HttpInterceptorFn } from '@angular/common/http';
import { mockBackendInterceptor, salesMockInterceptor } from '@flexipos/shared';

// Development: the mock backends answer until the real API exists (FP-215).
// The sales mock goes first; it passes through anything that is not a sales path (T-037).
export const apiBaseUrl = '/api/v1';
export const devInterceptors: HttpInterceptorFn[] = [salesMockInterceptor, mockBackendInterceptor];
