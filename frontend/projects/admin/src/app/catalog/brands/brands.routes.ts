import { Routes } from '@angular/router';

/** Stub from chunk 1 (T-036): chunk 2 replaces it with the real brands screens. */
export const brandsRoutes: Routes = [
  {
    path: '',
    loadComponent: () => import('../../shell/placeholder-page').then((m) => m.PlaceholderPage),
    data: { title: 'Brands', arrivesWith: 'the T-036 brands screens (chunk 2)' },
  },
];

export default brandsRoutes;
