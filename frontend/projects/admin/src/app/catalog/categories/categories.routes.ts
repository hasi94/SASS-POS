import { Routes } from '@angular/router';
import { CATALOG_PERMISSIONS, requirePermissions } from '@flexipos/shared';

/** The form routes need both keys: `view` is the gate to the area, `manage` to change it (AC-7). */
const mayManage = requirePermissions([
  CATALOG_PERMISSIONS.categories.view,
  CATALOG_PERMISSIONS.categories.manage,
]);

export const categoriesRoutes: Routes = [
  {
    path: '',
    loadComponent: () => import('./categories-page').then((m) => m.CategoriesPage),
  },
  {
    path: 'new',
    canActivate: [mayManage],
    loadComponent: () => import('./category-form-page').then((m) => m.CategoryFormPage),
  },
  {
    path: ':id/edit',
    canActivate: [mayManage],
    loadComponent: () => import('./category-form-page').then((m) => m.CategoryFormPage),
  },
];

export default categoriesRoutes;
