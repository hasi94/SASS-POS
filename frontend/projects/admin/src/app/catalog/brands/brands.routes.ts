import { Routes } from '@angular/router';
import { CATALOG_PERMISSIONS, requirePermissions } from '@flexipos/shared';

/** The form routes need both keys: `view` is the gate to the area, `manage` to change it (AC-7). */
const mayManage = requirePermissions([
  CATALOG_PERMISSIONS.brands.view,
  CATALOG_PERMISSIONS.brands.manage,
]);

export const brandsRoutes: Routes = [
  {
    path: '',
    loadComponent: () => import('./brands-page').then((m) => m.BrandsPage),
  },
  {
    path: 'new',
    canActivate: [mayManage],
    loadComponent: () => import('./brand-form-page').then((m) => m.BrandFormPage),
  },
  {
    path: ':id/edit',
    canActivate: [mayManage],
    loadComponent: () => import('./brand-form-page').then((m) => m.BrandFormPage),
  },
];

export default brandsRoutes;
