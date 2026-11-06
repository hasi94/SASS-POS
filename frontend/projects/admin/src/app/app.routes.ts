import { Routes } from '@angular/router';
import { ContextPage, LoginPage, authGuard, contextGuard, guestGuard } from '@flexipos/shared';

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
      {
        path: 'catalog',
        loadComponent: () => import('./shell/placeholder-page').then((m) => m.PlaceholderPage),
        data: { title: 'Catalog', arrivesWith: 'FP-230 and FP-240' },
      },
      {
        path: 'customers',
        loadComponent: () => import('./shell/placeholder-page').then((m) => m.PlaceholderPage),
        data: { title: 'Customers', arrivesWith: 'FP-232 and FP-240' },
      },
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
    ],
  },
  { path: '**', redirectTo: '' },
];
