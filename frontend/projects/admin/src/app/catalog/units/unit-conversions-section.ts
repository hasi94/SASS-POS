import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CATALOG_PERMISSIONS, PermissionService, UnitConversion } from '@flexipos/shared';
import { ConfirmDialog } from '../../crud/confirm-dialog';
import { DeleteFlow } from '../../crud/delete-flow';
import { createListController } from '../../crud/list-controller';
import { ListColumn, ListView, RowActions } from '../../crud/list-view';
import { CatalogApi } from '../catalog-api';

/**
 * The unit conversions sub-list of the Units screen (T-036 AC-37). It is only rendered
 * inside the units area, so the units view key already gates it; Create and Delete need the
 * units manage key. There is no edit: a conversion is deleted and made again.
 */
@Component({
  selector: 'adm-unit-conversions-section',
  imports: [ConfirmDialog, ListView, RouterLink, RowActions],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section aria-labelledby="unit-conversions-heading">
      <h2 id="unit-conversions-heading">Unit conversions</h2>
      @if (canManage()) {
        <p><a routerLink="/catalog/units/conversions/new">New conversion</a></p>
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
        caption="Unit conversions"
        searchLabel="Search conversions"
      >
        <ng-template [admRowActions]="list.rows()" let-row>
          @if (canManage()) {
            <button
              type="button"
              [attr.aria-label]="'Delete ' + label(row)"
              (click)="flow.ask(row)"
            >
              Delete
            </button>
          }
        </ng-template>
      </adm-list-view>
      @if (flow.pending(); as row) {
        <adm-confirm-dialog
          [name]="label(row)"
          [busy]="flow.busy()"
          (confirmed)="flow.confirm()"
          (cancelled)="flow.cancel()"
        />
      }
    </section>
  `,
})
export class UnitConversionsSection {
  private readonly api = inject(CatalogApi);
  private readonly permissions = inject(PermissionService);

  protected readonly canManage = computed(() =>
    this.permissions.has(CATALOG_PERMISSIONS.units.manage),
  );
  protected readonly list = createListController<UnitConversion>((params) =>
    this.api.unitConversions.list(params),
  );
  protected readonly flow = new DeleteFlow<UnitConversion>(
    (row) => this.api.unitConversions.remove(row.id),
    () => this.list.load(),
  );
  protected readonly columns: ListColumn<UnitConversion>[] = [
    { header: 'From', cell: (c) => c.from_unit_code },
    { header: 'To', cell: (c) => c.to_unit_code },
    { header: 'Factor', cell: (c) => c.factor },
    {
      header: 'Product',
      cell: (c) => (c.product_id === null ? 'All products' : (c.product_name ?? c.product_id)),
    },
  ];

  constructor() {
    this.list.load();
  }

  protected label(c: UnitConversion): string {
    return `${c.from_unit_code} to ${c.to_unit_code}`;
  }
}
