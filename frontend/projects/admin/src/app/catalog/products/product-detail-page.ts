import {
  ChangeDetectionStrategy,
  Component,
  OnInit,
  computed,
  inject,
  input,
  signal,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { ApiError, CATALOG_PERMISSIONS, PermissionService, Product } from '@flexipos/shared';
import { ApiErrorPanel } from '../../crud/api-error-panel';
import { createListController } from '../../crud/list-controller';
import { ListColumn, ListView, RowActions } from '../../crud/list-view';
import { CatalogApi } from '../catalog-api';
import { ProductBarcodesSection } from './product-barcodes-section';

/**
 * One product's fields; for a variant parent also its variants and the "New variant" link
 * with the parent preset (T-036 AC-41, AC-48); and its barcodes (AC-49).
 */
@Component({
  selector: 'adm-product-detail-page',
  imports: [ApiErrorPanel, ListView, ProductBarcodesSection, RouterLink, RowActions],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (loadError(); as err) {
      <adm-api-error-panel [error]="err" (retry)="load()" />
    } @else if (product(); as p) {
      <h1>{{ p.name }}</h1>
      @if (p.deleted_at) {
        <p><span class="adm-badge">Deleted</span> This product is deleted and cannot be changed.</p>
      } @else if (canManage()) {
        <p><a [routerLink]="['/catalog/products', p.id, 'edit']">Edit product</a></p>
      }
      <dl class="adm-details">
        <dt>SKU</dt>
        <dd>{{ p.sku }}</dd>
        <dt>Type</dt>
        <dd>{{ p.type }}</dd>
        <dt>Category</dt>
        <dd>{{ p.category_name }}</dd>
        <dt>Brand</dt>
        <dd>{{ p.brand_name ?? 'None' }}</dd>
        <dt>Stock unit</dt>
        <dd>{{ p.stock_unit_code }}</dd>
        <dt>Weighable</dt>
        <dd>{{ p.is_weighable ? 'Yes' : 'No' }}</dd>
        <dt>Price mode</dt>
        <dd>{{ p.price_mode }}</dd>
        <dt>PLU code</dt>
        <dd>{{ p.plu_code ?? 'None' }}</dd>
        <dt>Track stock</dt>
        <dd>{{ p.track_stock ? 'Yes' : 'No' }}</dd>
        <dt>Tracking mode</dt>
        <dd>{{ p.tracking_mode }}</dd>
        <dt>Active</dt>
        <dd>{{ p.is_active ? 'Yes' : 'No' }}</dd>
      </dl>

      @if (p.type === 'variant_parent') {
        <section aria-labelledby="product-variants-heading">
          <h2 id="product-variants-heading">Variants</h2>
          @if (canManage() && !p.deleted_at) {
            <p>
              <a
                routerLink="/catalog/products/new"
                [queryParams]="{ type: 'variant', parent_id: p.id }"
                >New variant</a
              >
            </p>
          }
          <adm-list-view
            [controller]="variants"
            [columns]="variantColumns"
            caption="Variants"
            [searchable]="false"
          >
            <ng-template [admRowActions]="variants.rows()" let-row>
              <a
                [routerLink]="['/catalog/products', row.id]"
                [attr.aria-label]="'Details of ' + row.name"
                >Details</a
              >
            </ng-template>
          </adm-list-view>
        </section>
      }

      @if (canViewBarcodes()) {
        <adm-product-barcodes-section [productId]="p.id" [deleted]="!!p.deleted_at" />
      }
      <p><a routerLink="/catalog/products">Back to products</a></p>
    } @else {
      <p class="fp-muted" role="status">Loading…</p>
    }
  `,
})
export class ProductDetailPage implements OnInit {
  readonly id = input.required<string>();

  private readonly api = inject(CatalogApi);
  private readonly permissions = inject(PermissionService);

  protected readonly product = signal<Product | null>(null);
  protected readonly loadError = signal<ApiError | null>(null);
  protected readonly canManage = computed(() =>
    this.permissions.has(CATALOG_PERMISSIONS.products.manage),
  );
  protected readonly canViewBarcodes = computed(() =>
    this.permissions.has(CATALOG_PERMISSIONS.barcodes.view),
  );
  protected readonly variants = createListController<Product>(
    (params) => this.api.products.list(params),
    () => ({ parent_id: this.id() }),
  );
  protected readonly variantColumns: ListColumn<Product>[] = [
    { header: 'SKU', cell: (p) => p.sku },
    { header: 'Name', cell: (p) => p.name },
    { header: 'Active', cell: (p) => (p.is_active ? 'Yes' : 'No') },
  ];

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loadError.set(null);
    this.api.products.get(this.id()).subscribe({
      next: (p) => {
        this.product.set(p);
        if (p.type === 'variant_parent') {
          this.variants.load();
        }
      },
      error: (err: ApiError) => this.loadError.set(err),
    });
  }
}
