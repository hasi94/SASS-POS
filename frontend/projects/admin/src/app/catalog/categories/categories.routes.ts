import { Routes } from '@angular/router';

/** Stub from chunk 1 (T-036): chunk 2 replaces it with the real categories screens. */
export const categoriesRoutes: Routes = [
  {
    path: '',
    loadComponent: () => import('../../shell/placeholder-page').then((m) => m.PlaceholderPage),
    data: { title: 'Categories', arrivesWith: 'the T-036 categories screens (chunk 2)' },
  },
];

export default categoriesRoutes;
