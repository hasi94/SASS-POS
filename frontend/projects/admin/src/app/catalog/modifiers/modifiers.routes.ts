import { Routes } from '@angular/router';
import { CATALOG_PERMISSIONS, requirePermissions } from '@flexipos/shared';

/** The form routes need both keys: `view` is the gate to the area, `manage` to change it (AC-7). */
const mayManage = requirePermissions([
  CATALOG_PERMISSIONS.modifiers.view,
  CATALOG_PERMISSIONS.modifiers.manage,
]);

/**
 * `new` is listed before `:groupId` so it is never read as a group id. Parameter names differ
 * on purpose: the group form reads `id`, the detail and modifier pages read `groupId`.
 */
export const modifiersRoutes: Routes = [
  {
    path: '',
    loadComponent: () => import('./modifier-groups-page').then((m) => m.ModifierGroupsPage),
  },
  {
    path: 'new',
    canActivate: [mayManage],
    loadComponent: () => import('./modifier-group-form-page').then((m) => m.ModifierGroupFormPage),
  },
  {
    path: ':groupId/modifiers/new',
    canActivate: [mayManage],
    loadComponent: () => import('./modifier-form-page').then((m) => m.ModifierFormPage),
  },
  {
    path: ':groupId/modifiers/:id/edit',
    canActivate: [mayManage],
    loadComponent: () => import('./modifier-form-page').then((m) => m.ModifierFormPage),
  },
  {
    path: ':id/edit',
    canActivate: [mayManage],
    loadComponent: () => import('./modifier-group-form-page').then((m) => m.ModifierGroupFormPage),
  },
  {
    path: ':groupId',
    loadComponent: () =>
      import('./modifier-group-detail-page').then((m) => m.ModifierGroupDetailPage),
  },
];

export default modifiersRoutes;
