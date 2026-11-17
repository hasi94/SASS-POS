import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Brand, CATALOG_PERMISSIONS, PermissionService } from '@flexipos/shared';
import { ApiErrorPanel } from '../../crud/api-error-panel';
import { ConfirmDialog } from '../../crud/confirm-dialog';
import { DeleteFlow } from '../../crud/delete-flow';
import { createListController } from '../../crud/list-controller';
import { ListColumn, ListView, RowActions } from '../../crud/list-view';
import { CatalogApi } from '../catalog-api';

/** The brands list with create, edit and delete for holders of the manage key (T-036 AC-33). */
@Component({
  selector: 'adm-brands-page',
  imports: [ApiErrorPanel, ConfirmDialog, ListView, RouterLink, RowActions],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>Brands</h1>
    @if (canManage()) {
      <p><a routerLink="/catalog/brands/new">New brand</a></p>
    }
    @if (flow.error(); as failure) {
      <adm-api-error-panel [error]="failure" [retryable]="false" />
    }
    <adm-list-view
      [controller]="list"
      [columns]="columns"
      caption="Brands"
      searchLabel="Search brands"
    >
      <ng-template [admRowActions]="list.rows()" let-row>
        @if (canManage()) {
          <a
            [routerLink]="['/catalog/brands', row.id, 'edit']"
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
export class BrandsPage {
  private readonly api = inject(CatalogApi);
  private readonly permissions = inject(PermissionService);

  protected readonly canManage = computed(() =>
    this.permissions.has(CATALOG_PERMISSIONS.brands.manage),
  );
  protected readonly list = createListController<Brand>((params) => this.api.brands.list(params));
  protected readonly flow = new DeleteFlow<Brand>(
    (row) => this.api.brands.remove(row.id),
    () => this.list.load(),
  );
  protected readonly columns: ListColumn<Brand>[] = [
    { header: 'Code', cell: (b) => b.code },
    { header: 'Name', cell: (b) => b.name },
  ];

  constructor() {
    this.list.load();
  }
}
