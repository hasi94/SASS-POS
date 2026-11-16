import { Routes } from '@angular/router';
import { CATALOG_PERMISSIONS, requirePermissions } from '@flexipos/shared';

/** The product form routes need both keys: `view` gates the area, `manage` the change (AC-7). */
const mayManage = requirePermissions([
  CATALOG_PERMISSIONS.products.view,
  CATALOG_PERMISSIONS.products.manage,
]);

/** The barcode form routes need the barcode keys as well (AC-49). */
const mayManageBarcodes = requirePermissions([
  CATALOG_PERMISSIONS.products.view,
  CATALOG_PERMISSIONS.barcodes.view,
  CATALOG_PERMISSIONS.barcodes.manage,
]);

export const productsRoutes: Routes = [
  {
    path: '',
    loadComponent: () => import('./products-page').then((m) => m.ProductsPage),
  },
  {
    path: 'new',
    canActivate: [mayManage],
    loadComponent: () => import('./product-form-page').then((m) => m.ProductFormPage),
  },
  {
    path: ':id',
    loadComponent: () => import('./product-detail-page').then((m) => m.ProductDetailPage),
  },
  {
    path: ':id/edit',
    canActivate: [mayManage],
    loadComponent: () => import('./product-form-page').then((m) => m.ProductFormPage),
  },
  {
    path: ':id/barcodes/new',
    canActivate: [mayManageBarcodes],
    loadComponent: () => import('./barcode-form-page').then((m) => m.BarcodeFormPage),
  },
  {
    path: ':id/barcodes/:barcodeId/edit',
    canActivate: [mayManageBarcodes],
    loadComponent: () => import('./barcode-form-page').then((m) => m.BarcodeFormPage),
  },
];

export default productsRoutes;
