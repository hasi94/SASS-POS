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
import { ApiError, CATALOG_PERMISSIONS, PermissionService, ProductBarcode } from '@flexipos/shared';
import { ApiErrorPanel } from '../../crud/api-error-panel';
import { ConfirmDialog } from '../../crud/confirm-dialog';
import { DeleteFlow } from '../../crud/delete-flow';
import { createListController } from '../../crud/list-controller';
import { ListColumn, ListView, RowActions } from '../../crud/list-view';
import { SubmitState } from '../../crud/submit-state';
import { CatalogApi } from '../catalog-api';

/**
 * A product's barcodes (T-036 AC-49 to AC-51). The caller shows it only to holders of the
 * barcodes view key; add, edit, delete and "Make primary" need the manage key as well, and
 * are absent for a deleted product. After a change the list is re-read from the server: the
 * client never clears the old primary flag itself (AC-51).
 */
@Component({
  selector: 'adm-product-barcodes-section',
  imports: [ApiErrorPanel, ConfirmDialog, ListView, RouterLink, RowActions],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section aria-labelledby="product-barcodes-heading">
      <h2 id="product-barcodes-heading">Barcodes</h2>
      @if (canChange()) {
        <p>
          <a [routerLink]="['/catalog/products', productId(), 'barcodes', 'new']">Add barcode</a>
        </p>
      }
      @if (failure(); as f) {
        <adm-api-error-panel [error]="f" [retryable]="false" />
      }
      <adm-list-view
        [controller]="list"
        [columns]="columns"
        caption="Barcodes"
        [searchable]="false"
      >
        <ng-template [admRowActions]="list.rows()" let-row>
          @if (canChange()) {
            <a
              [routerLink]="['/catalog/products', productId(), 'barcodes', row.id, 'edit']"
              [attr.aria-label]="'Edit barcode ' + row.barcode"
              >Edit</a
            >
            @if (!row.is_primary) {
              <button
                type="button"
                [attr.aria-label]="'Make ' + row.barcode + ' primary'"
                [disabled]="primary.busy()"
                (click)="makePrimary(row)"
              >
                Make primary
              </button>
            }
            <button
              type="button"
              [attr.aria-label]="'Delete barcode ' + row.barcode"
              (click)="flow.ask(row)"
            >
              Delete
            </button>
          }
        </ng-template>
      </adm-list-view>
      @if (flow.pending(); as row) {
        <adm-confirm-dialog
          [name]="row.barcode"
          [busy]="flow.busy()"
          (confirmed)="flow.confirm()"
          (cancelled)="flow.cancel()"
        />
      }
    </section>
  `,
})
export class ProductBarcodesSection implements OnInit {
  readonly productId = input.required<string>();
  /** A deleted product's barcodes are shown, never changed (D-11). */
  readonly deleted = input(false);

  private readonly api = inject(CatalogApi);
  private readonly permissions = inject(PermissionService);
  protected readonly primary = new SubmitState();
  private readonly primaryFailure = signal<ApiError | null>(null);

  protected readonly canChange = computed(
    () => !this.deleted() && this.permissions.has(CATALOG_PERMISSIONS.barcodes.manage),
  );
  protected readonly list = createListController<ProductBarcode>((params) =>
    this.api.barcodes(this.productId()).list(params),
  );
  protected readonly flow = new DeleteFlow<ProductBarcode>(
    (row) => this.api.barcodes(this.productId()).remove(row.id),
    () => this.list.load(),
  );
  protected readonly failure = computed(() => this.flow.error() ?? this.primaryFailure());
  protected readonly columns: ListColumn<ProductBarcode>[] = [
    { header: 'Barcode', cell: (b) => b.barcode },
    { header: 'Unit', cell: (b) => b.unit_code ?? 'Stock unit' },
    { header: 'Quantity', cell: (b) => b.quantity },
    { header: 'Primary', cell: (b) => (b.is_primary ? 'Primary' : '') },
  ];

  ngOnInit(): void {
    this.list.load();
  }

  /** Sends the row as primary, then re-reads the list: the server clears the old flag. */
  protected makePrimary(row: ProductBarcode): void {
    this.primaryFailure.set(null);
    this.primary.run(
      this.api.barcodes(this.productId()).update(row.id, {
        unit_id: row.unit_id,
        quantity: row.quantity,
        is_primary: true,
      }),
      {
        next: () => this.list.load(),
        error: (err) => this.primaryFailure.set(err),
      },
    );
  }
}
