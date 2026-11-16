import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CATALOG_PERMISSIONS, PermissionService, Product } from '@flexipos/shared';
import { ConfirmDialog } from '../../crud/confirm-dialog';
import { DeleteFlow } from '../../crud/delete-flow';
import { createListController } from '../../crud/list-controller';
import { ListColumn, ListView, RowActions } from '../../crud/list-view';
import { CatalogApi } from '../catalog-api';

/** The products list, searchable by SKU or name (T-036 AC-38, AC-47). */
@Component({
  selector: 'adm-products-page',
  imports: [ConfirmDialog, ListView, RouterLink, RowActions],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>Products</h1>
    @if (canManage()) {
      <p><a routerLink="/catalog/products/new">New product</a></p>
    }
    @if (flow.error(); as failure) {
      <div class="adm-error-panel" role="alert">
        <p class="adm-error-message">{{ failure.message }}</p>
        @if (failure.requestId; as id) {
          <p class="fp-muted">Request id: {{ id }}</p>
        }
      </div>
    }
    <adm-list-view
      [controller]="list"
      [columns]="columns"
      caption="Products"
      searchLabel="Search products by SKU or name"
    >
      <ng-template [admRowActions]="list.rows()" let-row>
        <a [routerLink]="['/catalog/products', row.id]" [attr.aria-label]="'Details of ' + row.name"
          >Details</a
        >
        @if (canManage()) {
          <a
            [routerLink]="['/catalog/products', row.id, 'edit']"
            [attr.aria-label]="'Edit ' + row.name"
            >Edit</a
          >
          <button type="button" [attr.aria-label]="'Delete ' + row.name" (click)="flow.ask(row)">
            Delete
          </button>
        }
      </ng-template>
    </adm-list-view>
    @if (flow.pending(); as row) {
      <adm-confirm-dialog
        [name]="row.name"
        [busy]="flow.busy()"
        (confirmed)="flow.confirm()"
        (cancelled)="flow.cancel()"
      />
    }
  `,
})
export class ProductsPage {
  private readonly api = inject(CatalogApi);
  private readonly permissions = inject(PermissionService);

  protected readonly canManage = computed(() =>
    this.permissions.has(CATALOG_PERMISSIONS.products.manage),
  );
  protected readonly list = createListController<Product>((params) =>
    this.api.products.list(params),
  );
  protected readonly flow = new DeleteFlow<Product>(
    (row) => this.api.products.remove(row.id),
    () => this.list.load(),
  );
  protected readonly columns: ListColumn<Product>[] = [
    { header: 'SKU', cell: (p) => p.sku },
    { header: 'Name', cell: (p) => p.name },
    { header: 'Type', cell: (p) => p.type },
    { header: 'Category', cell: (p) => p.category_name },
    { header: 'Brand', cell: (p) => p.brand_name ?? '' },
    { header: 'Stock unit', cell: (p) => p.stock_unit_code },
    { header: 'Active', cell: (p) => (p.is_active ? 'Yes' : 'No') },
  ];

  constructor() {
    this.list.load();
  }
}
