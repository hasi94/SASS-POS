import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CATALOG_PERMISSIONS, PermissionService, Unit } from '@flexipos/shared';
import { ConfirmDialog } from '../../crud/confirm-dialog';
import { DeleteFlow } from '../../crud/delete-flow';
import { createListController } from '../../crud/list-controller';
import { ListColumn, ListView, RowActions } from '../../crud/list-view';
import { CatalogApi } from '../catalog-api';
import { UnitConversionsSection } from './unit-conversions-section';

/** The units list, with the unit conversions section below it (T-036 AC-34, AC-37). */
@Component({
  selector: 'adm-units-page',
  imports: [ConfirmDialog, ListView, RouterLink, RowActions, UnitConversionsSection],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>Units</h1>
    @if (canManage()) {
      <p><a routerLink="/catalog/units/new">New unit</a></p>
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
      caption="Units"
      searchLabel="Search units"
    >
      <ng-template [admRowActions]="list.rows()" let-row>
        @if (canManage()) {
          <a
            [routerLink]="['/catalog/units', row.id, 'edit']"
            [attr.aria-label]="'Edit ' + row.code"
            >Edit</a
          >
          <button type="button" [attr.aria-label]="'Delete ' + row.code" (click)="flow.ask(row)">
            Delete
          </button>
        }
      </ng-template>
    </adm-list-view>
    @if (flow.pending(); as row) {
      <adm-confirm-dialog
        [name]="row.code"
        [busy]="flow.busy()"
        (confirmed)="flow.confirm()"
        (cancelled)="flow.cancel()"
      />
    }
    <adm-unit-conversions-section />
  `,
})
export class UnitsPage {
  private readonly api = inject(CatalogApi);
  private readonly permissions = inject(PermissionService);

  protected readonly canManage = computed(() =>
    this.permissions.has(CATALOG_PERMISSIONS.units.manage),
  );
  protected readonly list = createListController<Unit>((params) => this.api.units.list(params));
  protected readonly flow = new DeleteFlow<Unit>(
    (row) => this.api.units.remove(row.id),
    () => this.list.load(),
  );
  protected readonly columns: ListColumn<Unit>[] = [
    { header: 'Code', cell: (u) => u.code },
    { header: 'Name', cell: (u) => u.name },
    { header: 'Dimension', cell: (u) => u.dimension },
    { header: 'Precision', cell: (u) => String(u.precision) },
    { header: 'Base unit', cell: (u) => u.base_unit_code ?? '' },
    { header: 'Factor', cell: (u) => u.factor ?? '' },
  ];

  constructor() {
    this.list.load();
  }
}
