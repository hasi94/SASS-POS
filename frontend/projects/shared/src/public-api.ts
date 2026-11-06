/*
 * Public API of @flexipos/shared: the API client, authentication, tenant and branch context,
 * and the sign-in screens both applications use. docs/architecture.md §15.
 */

export * from './lib/api/envelope';
export * from './lib/api/api-client';
export * from './lib/auth/auth.models';
export * from './lib/auth/auth.service';
export * from './lib/auth/auth.guards';
export * from './lib/auth/auth.interceptor';
export * from './lib/auth/session-storage';
export * from './lib/mock/mock-backend';
export * from './lib/ui/login-page';
export * from './lib/ui/context-page';
