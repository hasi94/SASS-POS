import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CATALOG_PERMISSIONS, ModifierGroup, PermissionService } from '@flexipos/shared';
import { ConfirmDialog } from '../../crud/confirm-dialog';
import { DeleteFlow } from '../../crud/delete-flow';
import { createListController } from '../../crud/list-controller';
import { ListColumn, ListView, RowActions } from '../../crud/list-view';
import { CatalogApi } from '../catalog-api';

/** The modifier groups list (T-036 AC-52, AC-55). A group opens to show its modifiers. */
@Component({
  selector: 'adm-modifier-groups-page',
  imports: [ConfirmDialog, ListView, RouterLink, RowActions],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>Modifier groups</h1>
    @if (canManage()) {
      <p><a routerLink="/catalog/modifiers/new">New modifier group</a></p>
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
      caption="Modifier groups"
      searchLabel="Search modifier groups"
    >
      <ng-template [admRowActions]="list.rows()" let-row>
        <a [routerLink]="['/catalog/modifiers', row.id]" [attr.aria-label]="'Open ' + row.name"
          >Open</a
        >
        @if (canManage()) {
          <a
            [routerLink]="['/catalog/modifiers', row.id, 'edit']"
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
export class ModifierGroupsPage {
  private readonly api = inject(CatalogApi);
  private readonly permissions = inject(PermissionService);

  protected readonly canManage = computed(() =>
    this.permissions.has(CATALOG_PERMISSIONS.modifiers.manage),
  );
  protected readonly list = createListController<ModifierGroup>((params) =>
    this.api.modifierGroups.list(params),
  );
  protected readonly flow = new DeleteFlow<ModifierGroup>(
    (row) => this.api.modifierGroups.remove(row.id),
    () => this.list.load(),
  );
  protected readonly columns: ListColumn<ModifierGroup>[] = [
    { header: 'Name', cell: (g) => g.name },
    { header: 'Minimum', cell: (g) => String(g.min_select) },
    { header: 'Maximum', cell: (g) => (g.max_select === null ? 'No limit' : String(g.max_select)) },
  ];

  constructor() {
    this.list.load();
  }
}
