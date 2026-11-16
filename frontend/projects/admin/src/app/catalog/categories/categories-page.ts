import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CATALOG_PERMISSIONS, Category, PermissionService } from '@flexipos/shared';
import { ConfirmDialog } from '../../crud/confirm-dialog';
import { DeleteFlow } from '../../crud/delete-flow';
import { createListController } from '../../crud/list-controller';
import { ListColumn, ListView, RowActions } from '../../crud/list-view';
import { CatalogApi } from '../catalog-api';

/** The categories list: code, name (indented by depth), parent and depth (T-036 AC-28). */
@Component({
  selector: 'adm-categories-page',
  imports: [ConfirmDialog, ListView, RouterLink, RowActions],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>Categories</h1>
    @if (canManage()) {
      <p><a routerLink="/catalog/categories/new">New category</a></p>
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
      caption="Categories"
      searchLabel="Search categories"
    >
      <ng-template [admRowActions]="list.rows()" let-row>
        @if (canManage()) {
          <a
            [routerLink]="['/catalog/categories', row.id, 'edit']"
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
export class CategoriesPage {
  private readonly api = inject(CatalogApi);
  private readonly permissions = inject(PermissionService);

  protected readonly canManage = computed(() =>
    this.permissions.has(CATALOG_PERMISSIONS.categories.manage),
  );
  protected readonly list = createListController<Category>((params) =>
    this.api.categories.list(params),
  );
  protected readonly flow = new DeleteFlow<Category>(
    (row) => this.api.categories.remove(row.id),
    () => this.list.load(),
  );
  protected readonly columns: ListColumn<Category>[] = [
    { header: 'Code', cell: (c) => c.code },
    { header: 'Name', cell: (c) => c.name, indent: (c) => c.depth },
    { header: 'Parent', cell: (c) => c.parent_name ?? '' },
    { header: 'Depth', cell: (c) => String(c.depth) },
  ];

  constructor() {
    this.list.load();
  }
}
