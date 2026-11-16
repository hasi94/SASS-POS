import { inject } from '@angular/core';
import { CanActivateFn, Router, Routes } from '@angular/router';
import { CATALOG_PERMISSIONS, PermissionService, requirePermissions } from '@flexipos/shared';

/**
 * The catalog areas in menu order. Landing on `/catalog` goes to the first one the person
 * may view, so `view` is the gate to every area (T-036 D-4).
 */
export const CATALOG_AREAS = [
  { path: 'categories', view: CATALOG_PERMISSIONS.categories.view },
  { path: 'brands', view: CATALOG_PERMISSIONS.brands.view },
  { path: 'units', view: CATALOG_PERMISSIONS.units.view },
  { path: 'products', view: CATALOG_PERMISSIONS.products.view },
  { path: 'modifiers', view: CATALOG_PERMISSIONS.modifiers.view },
] as const;

/** `/catalog` itself shows nothing: it redirects to the first permitted area, else `/forbidden`. */
export const catalogLandingGuard: CanActivateFn = () => {
  const permissions = inject(PermissionService);
  const router = inject(Router);
  const first = CATALOG_AREAS.find((area) => permissions.has(area.view));
  return router.createUrlTree(first ? ['/catalog', first.path] : ['/forbidden']);
};

/**
 * Each area loads lazily from its own routes file, so two developers never edit the same
 * file and no screen is in the initial bundle (AC-56). The view guard runs before anything
 * of the area renders and before any catalog request is sent (AC-6, AC-7).
 */
export const catalogRoutes: Routes = [
  { path: '', pathMatch: 'full', canActivate: [catalogLandingGuard], children: [] },
  {
    path: 'categories',
    canActivate: [requirePermissions([CATALOG_PERMISSIONS.categories.view])],
    loadChildren: () => import('./categories/categories.routes'),
  },
  {
    path: 'brands',
    canActivate: [requirePermissions([CATALOG_PERMISSIONS.brands.view])],
    loadChildren: () => import('./brands/brands.routes'),
  },
  {
    path: 'units',
    canActivate: [requirePermissions([CATALOG_PERMISSIONS.units.view])],
    loadChildren: () => import('./units/units.routes'),
  },
  {
    path: 'products',
    canActivate: [requirePermissions([CATALOG_PERMISSIONS.products.view])],
    loadChildren: () => import('./products/products.routes'),
  },
  {
    path: 'modifiers',
    canActivate: [requirePermissions([CATALOG_PERMISSIONS.modifiers.view])],
    loadChildren: () => import('./modifiers/modifiers.routes'),
  },
];

export default catalogRoutes;
