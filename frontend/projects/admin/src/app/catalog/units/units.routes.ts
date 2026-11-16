import { Routes } from '@angular/router';

/** Stub from chunk 1 (T-036): chunk 2 replaces it with the real units screens. */
export const unitsRoutes: Routes = [
  {
    path: '',
    loadComponent: () => import('../../shell/placeholder-page').then((m) => m.PlaceholderPage),
    data: { title: 'Units', arrivesWith: 'the T-036 units screens (chunk 2)' },
  },
];

export default unitsRoutes;
