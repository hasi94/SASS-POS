import { Routes } from '@angular/router';

/** Stub from chunk 1 (T-036): chunk 2 replaces it with the real modifiers screens. */
export const modifiersRoutes: Routes = [
  {
    path: '',
    loadComponent: () => import('../../shell/placeholder-page').then((m) => m.PlaceholderPage),
    data: { title: 'Modifiers', arrivesWith: 'the T-036 modifiers screens (chunk 2)' },
  },
];

export default modifiersRoutes;
