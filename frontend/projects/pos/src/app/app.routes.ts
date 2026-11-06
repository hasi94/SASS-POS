import { Routes } from '@angular/router';
import { ContextPage, LoginPage, authGuard, contextGuard, guestGuard } from '@flexipos/shared';

export const routes: Routes = [
  {
    path: 'login',
    component: LoginPage,
    canActivate: [guestGuard],
    data: { appName: 'FlexiPOS Till' },
  },
  {
    path: 'context',
    component: ContextPage,
    canActivate: [authGuard],
    data: { landing: '/till' },
  },
  {
    path: 'till',
    canActivate: [contextGuard],
    loadComponent: () => import('./till/till-page').then((m) => m.TillPage),
  },
  { path: '', pathMatch: 'full', redirectTo: 'till' },
  { path: '**', redirectTo: 'till' },
];
