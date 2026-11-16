import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  OnInit,
  computed,
  inject,
  input,
  signal,
} from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { FormControl, FormGroup, ReactiveFormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import {
  ApiError,
  ListParams,
  Modifier,
  ModifierWrite,
  Product,
  Unit,
  UnitDimension,
} from '@flexipos/shared';
import { Observable, catchError, map, of, switchMap, tap } from 'rxjs';
import { ApiErrorPanel } from '../../crud/api-error-panel';
import { EntityPicker, PickerOption } from '../../crud/entity-picker';
import { FieldError } from '../../crud/field-error';
import { FormErrorSummary } from '../../crud/form-error-summary';
import { mapServerErrors } from '../../crud/form-errors';
import { SubmitState } from '../../crud/submit-state';
import { CatalogApi } from '../catalog-api';

const FIELDS = [
  'name',
  'price_delta',
  'cost_delta',
  'linked_product_id',
  'linked_quantity',
  'linked_unit_id',
] as const;
const SIGNED_DECIMAL = /^[+-]?(\d+(\.\d*)?|\.\d+)$/;
const POSITIVE_DECIMAL = /^\+?(\d+(\.\d*)?|\.\d+)$/;

/**
 * Create or edit one modifier (T-036 AC-53, AC-54). Choosing a linked product makes the linked
 * quantity and unit required; clearing it removes both from the request. The unit picker only
 * offers units of the product's stock-unit dimension as a convenience: a mismatch is the
 * server's to refuse and is shown on the unit field.
 */
@Component({
  selector: 'adm-modifier-form-page',
  imports: [
    ApiErrorPanel,
    EntityPicker,
    FieldError,
    FormErrorSummary,
    ReactiveFormsModule,
    RouterLink,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>{{ id() ? 'Edit modifier' : 'New modifier' }}</h1>
    @if (loadError(); as err) {
      <adm-api-error-panel [error]="err" (retry)="load()" />
    } @else if (loading()) {
      <p class="fp-muted" role="status">Loading…</p>
    } @else {
      <adm-form-error-summary [messages]="summary()" />
      <form class="adm-form-grid" [formGroup]="form" (ngSubmit)="save()" novalidate>
        <label
          >Name
          <input
            formControlName="name"
            [attr.aria-invalid]="errors()['name'] ? 'true' : null"
            [attr.aria-describedby]="errors()['name'] ? 'modifier-name-error' : null"
          />
        </label>
        <adm-field-error [id]="'modifier-name-error'" [message]="errors()['name']" />
        <label
          >Price change
          <input
            formControlName="price_delta"
            inputmode="decimal"
            [attr.aria-invalid]="errors()['price_delta'] ? 'true' : null"
            [attr.aria-describedby]="errors()['price_delta'] ? 'modifier-price-error' : null"
          />
        </label>
        <adm-field-error [id]="'modifier-price-error'" [message]="errors()['price_delta']" />
        <label
          >Cost change
          <input
            formControlName="cost_delta"
            inputmode="decimal"
            [attr.aria-invalid]="errors()['cost_delta'] ? 'true' : null"
            [attr.aria-describedby]="errors()['cost_delta'] ? 'modifier-cost-error' : null"
          />
        </label>
        <adm-field-error [id]="'modifier-cost-error'" [message]="errors()['cost_delta']" />
        <adm-entity-picker
          formControlName="linked_product_id"
          label="Linked product"
          noneLabel="No linked product"
          [search]="searchProducts"
          [optionOf]="productOption"
          [initialLabel]="modifier()?.linked_product_name ?? null"
          [describedBy]="errors()['linked_product_id'] ? 'modifier-product-error' : null"
          [invalid]="!!errors()['linked_product_id']"
        />
        <adm-field-error
          [id]="'modifier-product-error'"
          [message]="errors()['linked_product_id']"
        />
        @if (linkedProduct()) {
          <label
            >Linked quantity
            <input
              formControlName="linked_quantity"
              inputmode="decimal"
              [attr.aria-invalid]="errors()['linked_quantity'] ? 'true' : null"
              [attr.aria-describedby]="
                errors()['linked_quantity'] ? 'modifier-quantity-error' : null
              "
            />
          </label>
          <adm-field-error
            [id]="'modifier-quantity-error'"
            [message]="errors()['linked_quantity']"
          />
          <adm-entity-picker
            formControlName="linked_unit_id"
            label="Linked unit"
            [search]="searchUnits"
            [optionOf]="unitOption"
            [filter]="sameDimension()"
            [initialLabel]="modifier()?.linked_unit_code ?? null"
            [describedBy]="errors()['linked_unit_id'] ? 'modifier-unit-error' : null"
            [invalid]="!!errors()['linked_unit_id']"
          />
          <adm-field-error [id]="'modifier-unit-error'" [message]="errors()['linked_unit_id']" />
        }
        <div class="adm-actions">
          <button type="submit" class="fp-primary" [disabled]="state.busy()">
            {{ state.busy() ? 'Saving…' : 'Save' }}
          </button>
          <a [routerLink]="backUrl()">Cancel</a>
        </div>
      </form>
    }
  `,
})
export class ModifierFormPage implements OnInit {
  /** The route's `:groupId`. */
  readonly groupId = input.required<string>();
  /** The route's `:id`; absent on the create form. */
  readonly id = input<string>();

  private readonly api = inject(CatalogApi);
  private readonly router = inject(Router);
  protected readonly state = new SubmitState();
  protected readonly backUrl = computed(() => `/catalog/modifiers/${this.groupId()}`);

  protected readonly form = new FormGroup({
    name: new FormControl('', { nonNullable: true }),
    price_delta: new FormControl('0', { nonNullable: true }),
    cost_delta: new FormControl('0', { nonNullable: true }),
    linked_product_id: new FormControl<string | null>(null),
    linked_quantity: new FormControl('', { nonNullable: true }),
    linked_unit_id: new FormControl<string | null>(null),
  });
  protected readonly linkedProduct = toSignal(this.form.controls.linked_product_id.valueChanges, {
    initialValue: null,
  });
  /** The dimension of the linked product's stock unit, once known; null filters nothing. */
  private readonly dimension = signal<UnitDimension | null>(null);
  protected readonly modifier = signal<Modifier | null>(null);
  protected readonly loading = signal(false);
  protected readonly loadError = signal<ApiError | null>(null);

  private readonly clientErrors = signal<Record<string, string>>({});
  private readonly serverErrors = signal<Record<string, string>>({});
  protected readonly summary = signal<string[]>([]);
  protected readonly errors = computed<Record<string, string>>(() => ({
    ...this.serverErrors(),
    ...this.clientErrors(),
  }));

  private readonly seenProducts = new Map<string, Product>();
  protected readonly searchProducts = (params: ListParams) =>
    this.api.products
      .list(params)
      .pipe(tap((result) => result.data.forEach((p) => this.seenProducts.set(p.id, p))));
  protected readonly searchUnits = (params: ListParams) => this.api.units.list(params);
  protected readonly productOption = (p: Product): PickerOption => ({
    id: p.id,
    label: `${p.sku} ${p.name}`,
  });
  protected readonly unitOption = (u: Unit): PickerOption => ({
    id: u.id,
    label: `${u.code} (${u.name})`,
  });
  protected readonly sameDimension = computed(() => {
    const wanted = this.dimension();
    return (u: Unit) => wanted === null || u.dimension === wanted;
  });

  constructor() {
    this.form.controls.linked_product_id.valueChanges
      .pipe(
        tap((productId) => {
          if (productId === null) {
            // No product: the quantity and unit go with it and are never sent (AC-54).
            this.form.patchValue(
              { linked_quantity: '', linked_unit_id: null },
              { emitEvent: false },
            );
          }
        }),
        switchMap((productId) => this.dimensionOf(productId)),
        takeUntilDestroyed(inject(DestroyRef)),
      )
      .subscribe((dimension) => this.dimension.set(dimension));
  }

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    const id = this.id();
    if (!id) {
      return;
    }
    this.loading.set(true);
    this.loadError.set(null);
    this.api
      .modifiers(this.groupId())
      .get(id)
      .subscribe({
        next: (m) => {
          this.modifier.set(m);
          this.form.patchValue({
            name: m.name,
            price_delta: m.price_delta,
            cost_delta: m.cost_delta,
            linked_product_id: m.linked_product_id,
            linked_quantity: m.linked_quantity ?? '',
            linked_unit_id: m.linked_unit_id,
          });
          this.loading.set(false);
        },
        error: (err: ApiError) => {
          this.loadError.set(err);
          this.loading.set(false);
        },
      });
  }

  /** The stock unit's dimension for a product, or null when it cannot be found out. */
  private dimensionOf(productId: string | null): Observable<UnitDimension | null> {
    if (productId === null) {
      return of(null);
    }
    const known = this.seenProducts.get(productId);
    return (known ? of(known) : this.api.products.get(productId)).pipe(
      switchMap((product) => this.api.units.get(product.stock_unit_id)),
      map((unit) => unit.dimension),
      catchError(() => of(null)),
    );
  }

  protected save(): void {
    this.serverErrors.set({});
    this.summary.set([]);
    const v = this.form.getRawValue();
    const price = v.price_delta.trim();
    const cost = v.cost_delta.trim();
    const quantity = v.linked_quantity.trim();
    const problems: Record<string, string> = {};
    if (v.name.trim() === '') {
      problems['name'] = 'Name is required.';
    }
    if (price === '') {
      problems['price_delta'] = 'Price change is required.';
    } else if (!SIGNED_DECIMAL.test(price)) {
      problems['price_delta'] = 'Price change must be a number.';
    }
    if (cost === '') {
      problems['cost_delta'] = 'Cost change is required.';
    } else if (!SIGNED_DECIMAL.test(cost)) {
      problems['cost_delta'] = 'Cost change must be a number.';
    }
    if (v.linked_product_id !== null) {
      if (quantity === '') {
        problems['linked_quantity'] = 'Linked quantity is required.';
      } else if (!POSITIVE_DECIMAL.test(quantity) || Number(quantity) <= 0) {
        problems['linked_quantity'] = 'Linked quantity must be greater than 0.';
      }
      if (v.linked_unit_id === null) {
        problems['linked_unit_id'] = 'Linked unit is required.';
      }
    }
    this.clientErrors.set(problems);
    if (Object.keys(problems).length > 0) {
      return;
    }
    const body: ModifierWrite = { name: v.name.trim(), price_delta: price, cost_delta: cost };
    if (v.linked_product_id !== null && v.linked_unit_id !== null) {
      body.linked_product_id = v.linked_product_id;
      body.linked_quantity = quantity;
      body.linked_unit_id = v.linked_unit_id;
    }
    const groupId = this.groupId();
    const id = this.id();
    const resource = this.api.modifiers(groupId);
    this.state.run(id ? resource.update(id, body) : resource.create(body), {
      next: () => void this.router.navigateByUrl(this.backUrl()),
      error: (err) => {
        const mapped = mapServerErrors(err, FIELDS);
        this.serverErrors.set(mapped.fields);
        this.summary.set(mapped.summary);
      },
    });
  }
}
