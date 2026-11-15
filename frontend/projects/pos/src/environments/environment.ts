import { HttpInterceptorFn } from '@angular/common/http';

// Production: the real API only. Nothing here may import the mock backend.
export const apiBaseUrl = '/api/v1';
export const devInterceptors: HttpInterceptorFn[] = [];
