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
import {
  ApiError,
  CATALOG_PERMISSIONS,
  Modifier,
  ModifierGroup,
  PermissionService,
} from '@flexipos/shared';
import { ApiErrorPanel } from '../../crud/api-error-panel';
import { ConfirmDialog } from '../../crud/confirm-dialog';
import { DeleteFlow } from '../../crud/delete-flow';
import { createListController } from '../../crud/list-controller';
import { ListColumn, ListView, RowActions } from '../../crud/list-view';
import { CatalogApi } from '../catalog-api';

/**
 * One modifier group with its modifiers (T-036 AC-53): name, price change, cost change and,
 * when linked, the product, quantity and unit. A soft-deleted group shows its modifiers but
 * offers no change (D-11).
 */
@Component({
  selector: 'adm-modifier-group-detail-page',
  imports: [ApiErrorPanel, ConfirmDialog, ListView, RouterLink, RowActions],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (loadError(); as err) {
      <h1>Modifier group</h1>
      <adm-api-error-panel [error]="err" (retry)="loadGroup()" />
    } @else if (group(); as g) {
      <h1>{{ g.name }}</h1>
      @if (g.deleted_at) {
        <p><span class="adm-badge">Deleted</span> This modifier group is deleted.</p>
      }
      <p class="fp-muted">
        Choose at least {{ g.min_select }},
        {{ g.max_select === null ? 'with no upper limit' : 'at most ' + g.max_select }}.
      </p>
      @if (canChange()) {
        <p>
          <a [routerLink]="['/catalog/modifiers', g.id, 'edit']">Edit group</a>
          <a [routerLink]="['/catalog/modifiers', g.id, 'modifiers', 'new']">New modifier</a>
        </p>
      }
    } @else {
      <h1>Modifier group</h1>
      <p class="fp-muted" role="status">Loading…</p>
    }
    <p><a routerLink="/catalog/modifiers">Back to modifier groups</a></p>
    @if (flow.error(); as failure) {
      <adm-api-error-panel [error]="failure" [retryable]="false" />
    }
    <adm-list-view
      [controller]="list"
      [columns]="columns"
      caption="Modifiers"
      searchLabel="Search modifiers"
    >
      <ng-template [admRowActions]="list.rows()" let-row>
        @if (canChange()) {
          <a
            [routerLink]="['/catalog/modifiers', groupId(), 'modifiers', row.id, 'edit']"
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
export class ModifierGroupDetailPage implements OnInit {
  /** The route's `:groupId`. */
  readonly groupId = input.required<string>();

  private readonly api = inject(CatalogApi);
  private readonly permissions = inject(PermissionService);

  protected readonly group = signal<ModifierGroup | null>(null);
  protected readonly loadError = signal<ApiError | null>(null);
  protected readonly canChange = computed(
    () => this.permissions.has(CATALOG_PERMISSIONS.modifiers.manage) && !this.group()?.deleted_at,
  );
  protected readonly list = createListController<Modifier>((params) =>
    this.api.modifiers(this.groupId()).list(params),
  );
  protected readonly flow = new DeleteFlow<Modifier>(
    (row) => this.api.modifiers(this.groupId()).remove(row.id),
    () => this.list.load(),
  );
  protected readonly columns: ListColumn<Modifier>[] = [
    { header: 'Name', cell: (m) => m.name },
    { header: 'Price change', cell: (m) => m.price_delta },
    { header: 'Cost change', cell: (m) => m.cost_delta },
    { header: 'Linked product', cell: (m) => m.linked_product_name ?? '' },
    { header: 'Linked quantity', cell: (m) => m.linked_quantity ?? '' },
    { header: 'Linked unit', cell: (m) => m.linked_unit_code ?? '' },
  ];

  ngOnInit(): void {
    this.loadGroup();
    this.list.load();
  }

  protected loadGroup(): void {
    this.loadError.set(null);
    this.api.modifierGroups.get(this.groupId()).subscribe({
      next: (g) => this.group.set(g),
      error: (err: ApiError) => this.loadError.set(err),
    });
  }
}
