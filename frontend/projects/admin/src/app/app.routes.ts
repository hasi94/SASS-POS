import { Routes } from '@angular/router';
import {
  ContextPage,
  LoginPage,
  authGuard,
  contextGuard,
  guestGuard,
  requireAnyPermission,
} from '@flexipos/shared';
import { adminPlugins } from './plugins';

export const routes: Routes = [
  {
    path: 'login',
    component: LoginPage,
    canActivate: [guestGuard],
    data: { appName: 'FlexiPOS Admin' },
  },
  {
    path: 'context',
    component: ContextPage,
    canActivate: [authGuard],
    data: { landing: '/dashboard' },
  },
  {
    path: '',
    canActivate: [contextGuard],
    loadComponent: () => import('./shell/admin-shell').then((m) => m.AdminShell),
    children: [
      {
        path: 'dashboard',
        loadComponent: () => import('./shell/dashboard-page').then((m) => m.DashboardPage),
      },
      { path: 'catalog', loadChildren: () => import('./catalog/catalog.routes') },
      {
        path: 'forbidden',
        loadComponent: () => import('./shell/forbidden-page').then((m) => m.ForbiddenPage),
      },
      {
        // No customer permission keys exist yet, so nobody may open this: the guard always
        // sends the person to /forbidden (T-036 AC-56). The customer screens are F-2.
        path: 'customers',
        canActivate: [requireAnyPermission([])],
        loadComponent: () => import('./shell/placeholder-page').then((m) => m.PlaceholderPage),
        data: { title: 'Customers', arrivesWith: 'the customer screens follow-up (F-2)' },
      },
      ...adminPlugins.toRoutes(),
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
    ],
  },
  { path: '**', redirectTo: '' },
];
