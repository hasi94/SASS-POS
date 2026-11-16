import { Routes } from '@angular/router';
import { CATALOG_PERMISSIONS, requirePermissions } from '@flexipos/shared';

/** The form routes need both keys: `view` is the gate to the area, `manage` to change it (AC-7). */
const mayManage = requirePermissions([
  CATALOG_PERMISSIONS.units.view,
  CATALOG_PERMISSIONS.units.manage,
]);

export const unitsRoutes: Routes = [
  {
    path: '',
    loadComponent: () => import('./units-page').then((m) => m.UnitsPage),
  },
  {
    path: 'new',
    canActivate: [mayManage],
    loadComponent: () => import('./unit-form-page').then((m) => m.UnitFormPage),
  },
  {
    path: 'conversions/new',
    canActivate: [mayManage],
    loadComponent: () =>
      import('./unit-conversion-form-page').then((m) => m.UnitConversionFormPage),
  },
  {
    path: ':id/edit',
    canActivate: [mayManage],
    loadComponent: () => import('./unit-form-page').then((m) => m.UnitFormPage),
  },
];

export default unitsRoutes;
