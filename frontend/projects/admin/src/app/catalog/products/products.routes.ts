import { Routes } from '@angular/router';

/** Stub from chunk 1 (T-036): chunk 3 replaces it with the real products screens. */
export const productsRoutes: Routes = [
  {
    path: '',
    loadComponent: () => import('../../shell/placeholder-page').then((m) => m.PlaceholderPage),
    data: { title: 'Products', arrivesWith: 'the T-036 products screens (chunk 3)' },
  },
];

export default productsRoutes;
