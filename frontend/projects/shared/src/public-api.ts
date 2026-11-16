/*
 * Public API of @flexipos/shared: the API client, authentication, tenant and branch context,
 * and the sign-in screens both applications use. docs/architecture.md §15.
 */

export * from './lib/api/envelope';
export * from './lib/api/api-client';
export * from './lib/api/legacy-refusal';
export * from './lib/auth/auth.models';
export * from './lib/auth/auth.service';
export * from './lib/auth/auth.guards';
export * from './lib/auth/auth.interceptor';
export * from './lib/auth/session-storage';
export * from './lib/mock/mock-backend';
export * from './lib/ui/login-page';
export * from './lib/ui/context-page';
export * from './lib/plugins/plugin-routes';

// T-036 catalog
export * from './lib/contracts/catalog/catalog-permissions';
export * from './lib/contracts/catalog/catalog.models';
export * from './lib/contracts/catalog/catalog.requests';
export * from './lib/contracts/catalog/catalog.endpoints';
export * from './lib/api/api-list';
export * from './lib/auth/permission.service';
export * from './lib/auth/permission.guard';
export { MOCK_CATALOG_SENTINEL, resetMockCatalog } from './lib/mock/catalog/mock-catalog.store';
export { mockCatalogInterceptor } from './lib/mock/catalog/mock-catalog.interceptor';
