import { toSignal } from '@angular/core/rxjs-interop';
import {
  ChangeDetectionStrategy,
  Component,
  OnInit,
  computed,
  inject,
  input,
  signal,
} from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import {
  ApiError,
  ApiResult,
  Brand,
  Category,
  ListParams,
  PRICE_MODES,
  PRODUCT_TYPES,
  PriceMode,
  Product,
  ProductCreate,
  ProductType,
  ProductUpdate,
  TRACKING_MODES,
  TrackingMode,
  Unit,
} from '@flexipos/shared';
import { Observable, tap } from 'rxjs';
import { ApiErrorPanel } from '../../crud/api-error-panel';
import { EntityPicker, PickerOption } from '../../crud/entity-picker';
import { FieldError } from '../../crud/field-error';
import { FormErrorSummary } from '../../crud/form-error-summary';
import { FormErrors } from '../../crud/form-errors';
import { SubmitState } from '../../crud/submit-state';
import { CatalogApi } from '../catalog-api';

const FIELDS = [
  'type',
  'parent_id',
  'sku',
  'name',
  'category_id',
  'brand_id',
  'stock_unit_id',
  'purchase_unit_id',
  'sales_unit_id',
  'is_weighable',
  'price_mode',
  'plu_code',
  'track_stock',
  'tracking_mode',
  'variant_options',
  'variant_values',
  'version',
] as const;

const LIST_URL = '/catalog/products';
const STALE_CODE = 'catalog.stale_version';
const MAX_SKU = 50;
const MAX_PLU = 10;

/** The two JSON inputs belong to the variant family only (T-036 AC-41). */
function hasVariantJson(type: ProductType): boolean {
  return type === 'variant_parent' || type === 'variant';
}

/** `null` when the text is not JSON; `{ value }` otherwise. */
function parseJson(text: string): { value: unknown } | null {
  try {
    return { value: JSON.parse(text) as unknown };
  } catch {
    return null;
  }
}

function pretty(value: unknown): string {
  return value === null || value === undefined ? '' : JSON.stringify(value, null, 2);
}

/**
 * Create or edit one product (T-036 AC-39 to AC-46, AC-48). The client checks only
 * self-contained rules (D-17); duplicate SKU or PLU, the parent's type and unit dimensions are
 * the server's call and are shown on the field it names. A soft-deleted product opens
 * read-only (D-11, AC-48).
 */
@Component({
  selector: 'adm-product-form-page',
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
    <h1>{{ id() ? 'Edit product' : 'New product' }}</h1>
    @if (loadError(); as err) {
      <adm-api-error-panel [error]="err" (retry)="load()" />
    } @else if (loading()) {
      <p class="fp-muted" role="status">Loading…</p>
    } @else {
      @if (readOnly()) {
        <p><span class="adm-badge">Deleted</span> This product is deleted and cannot be changed.</p>
      }
      @if (stale()) {
        <div class="adm-error-panel" role="alert">
          <p class="adm-error-message">
            This product was changed by someone else. Your changes are kept below; press Reload to
            discard them and load the latest version.
          </p>
          <button type="button" (click)="load()">Reload</button>
        </div>
      }
      <adm-form-error-summary [messages]="summary()" />
      <form class="adm-form-grid" [formGroup]="form" (ngSubmit)="save()" novalidate>
        <label
          >Type
          <select
            formControlName="type"
            [attr.aria-invalid]="errors()['type'] ? 'true' : null"
            [attr.aria-describedby]="errors()['type'] ? 'product-type-error' : null"
          >
            @for (t of types; track t) {
              <option [value]="t">{{ t }}</option>
            }
          </select>
        </label>
        <adm-field-error [id]="'product-type-error'" [message]="errors()['type']" />

        @if (type() === 'variant') {
          <adm-entity-picker
            formControlName="parent_id"
            label="Parent product"
            [search]="searchParents"
            [optionOf]="productOption"
            [initialLabel]="parentLabel()"
            [invalid]="!!errors()['parent_id']"
            [describedBy]="errors()['parent_id'] ? 'product-parent_id-error' : null"
          />
          <adm-field-error [id]="'product-parent_id-error'" [message]="errors()['parent_id']" />
        }

        <label
          >SKU
          <input
            formControlName="sku"
            [attr.aria-invalid]="errors()['sku'] ? 'true' : null"
            [attr.aria-describedby]="errors()['sku'] ? 'product-sku-error' : null"
          />
        </label>
        <adm-field-error [id]="'product-sku-error'" [message]="errors()['sku']" />

        <label
          >Name
          <input
            formControlName="name"
            [attr.aria-invalid]="errors()['name'] ? 'true' : null"
            [attr.aria-describedby]="errors()['name'] ? 'product-name-error' : null"
          />
        </label>
        <adm-field-error [id]="'product-name-error'" [message]="errors()['name']" />

        <adm-entity-picker
          formControlName="category_id"
          label="Category"
          [search]="searchCategories"
          [optionOf]="categoryOption"
          [initialLabel]="product()?.category_name ?? null"
          [invalid]="!!errors()['category_id']"
          [describedBy]="errors()['category_id'] ? 'product-category_id-error' : null"
        />
        <adm-field-error [id]="'product-category_id-error'" [message]="errors()['category_id']" />

        <adm-entity-picker
          formControlName="brand_id"
          label="Brand"
          noneLabel="No brand"
          [search]="searchBrands"
          [optionOf]="brandOption"
          [initialLabel]="product()?.brand_name ?? null"
          [invalid]="!!errors()['brand_id']"
          [describedBy]="errors()['brand_id'] ? 'product-brand_id-error' : null"
        />
        <adm-field-error [id]="'product-brand_id-error'" [message]="errors()['brand_id']" />

        @if (id()) {
          <p
            id="product-stock-unit-readonly"
            [attr.aria-describedby]="
              errors()['stock_unit_id'] ? 'product-stock_unit_id-error' : null
            "
          >
            Stock unit: <strong>{{ product()?.stock_unit_code }}</strong>
            <span class="fp-muted">
              The stock unit cannot be changed after a product is created.</span
            >
          </p>
          <adm-field-error
            [id]="'product-stock_unit_id-error'"
            [message]="errors()['stock_unit_id']"
          />
        } @else {
          <adm-entity-picker
            formControlName="stock_unit_id"
            label="Stock unit"
            [search]="searchUnits"
            [optionOf]="unitOption"
            [invalid]="!!errors()['stock_unit_id']"
            [describedBy]="errors()['stock_unit_id'] ? 'product-stock_unit_id-error' : null"
          />
          <adm-field-error
            [id]="'product-stock_unit_id-error'"
            [message]="errors()['stock_unit_id']"
          />
        }

        <adm-entity-picker
          formControlName="purchase_unit_id"
          label="Purchase unit"
          noneLabel="Same as stock unit"
          [search]="searchUnits"
          [optionOf]="unitOption"
          [filter]="sameDimension()"
          [initialLabel]="unitLabel(product()?.purchase_unit_id)"
          [invalid]="!!errors()['purchase_unit_id']"
          [describedBy]="errors()['purchase_unit_id'] ? 'product-purchase_unit_id-error' : null"
        />
        <adm-field-error
          [id]="'product-purchase_unit_id-error'"
          [message]="errors()['purchase_unit_id']"
        />

        <adm-entity-picker
          formControlName="sales_unit_id"
          label="Sales unit"
          noneLabel="Same as stock unit"
          [search]="searchUnits"
          [optionOf]="unitOption"
          [filter]="sameDimension()"
          [initialLabel]="unitLabel(product()?.sales_unit_id)"
          [invalid]="!!errors()['sales_unit_id']"
          [describedBy]="errors()['sales_unit_id'] ? 'product-sales_unit_id-error' : null"
        />
        <adm-field-error
          [id]="'product-sales_unit_id-error'"
          [message]="errors()['sales_unit_id']"
        />

        <label
          ><span><input type="checkbox" formControlName="is_weighable" /> Weighable</span>
        </label>

        <label
          >Price mode
          <select
            formControlName="price_mode"
            [attr.aria-invalid]="errors()['price_mode'] ? 'true' : null"
            [attr.aria-describedby]="errors()['price_mode'] ? 'product-price_mode-error' : null"
          >
            @for (m of priceModes; track m) {
              <option [value]="m">{{ m }}</option>
            }
          </select>
        </label>
        <adm-field-error [id]="'product-price_mode-error'" [message]="errors()['price_mode']" />

        <label
          >PLU code
          <input
            formControlName="plu_code"
            [attr.aria-invalid]="errors()['plu_code'] ? 'true' : null"
            [attr.aria-describedby]="errors()['plu_code'] ? 'product-plu_code-error' : null"
          />
        </label>
        <adm-field-error [id]="'product-plu_code-error'" [message]="errors()['plu_code']" />

        <label
          ><span><input type="checkbox" formControlName="track_stock" /> Track stock</span>
        </label>

        <label
          >Tracking mode
          <select
            formControlName="tracking_mode"
            [attr.aria-invalid]="errors()['tracking_mode'] ? 'true' : null"
            [attr.aria-describedby]="
              errors()['tracking_mode'] ? 'product-tracking_mode-error' : null
            "
          >
            @for (m of trackingModes; track m) {
              <option [value]="m">{{ m }}</option>
            }
          </select>
        </label>
        <adm-field-error
          [id]="'product-tracking_mode-error'"
          [message]="errors()['tracking_mode']"
        />

        @if (showJson()) {
          <label
            >Variant options (JSON)
            <textarea
              formControlName="variant_options"
              rows="3"
              [attr.aria-invalid]="errors()['variant_options'] ? 'true' : null"
              [attr.aria-describedby]="
                errors()['variant_options'] ? 'product-variant_options-error' : null
              "
            ></textarea>
          </label>
          <adm-field-error
            [id]="'product-variant_options-error'"
            [message]="errors()['variant_options']"
          />
          <label
            >Variant values (JSON)
            <textarea
              formControlName="variant_values"
              rows="3"
              [attr.aria-invalid]="errors()['variant_values'] ? 'true' : null"
              [attr.aria-describedby]="
                errors()['variant_values'] ? 'product-variant_values-error' : null
              "
            ></textarea>
          </label>
          <adm-field-error
            [id]="'product-variant_values-error'"
            [message]="errors()['variant_values']"
          />
        }

        @if (!readOnly()) {
          <div class="adm-actions">
            <button type="submit" class="fp-primary" [disabled]="state.busy()">
              {{ state.busy() ? 'Saving…' : 'Save' }}
            </button>
            <a [routerLink]="cancelUrl()">Cancel</a>
          </div>
        } @else {
          <a [routerLink]="listUrl">Back to products</a>
        }
      </form>
    }
  `,
})
export class ProductFormPage implements OnInit {
  /** The route's `:id`; absent on the create form. */
  readonly id = input<string>();
  /** Query presets of the "New variant" link (AC-41): `?type=variant&parent_id=<id>`. */
  readonly presetType = input<string | undefined>(undefined, { alias: 'type' });
  readonly presetParent = input<string | undefined>(undefined, { alias: 'parent_id' });

  private readonly api = inject(CatalogApi);
  private readonly router = inject(Router);
  protected readonly state = new SubmitState();
  protected readonly listUrl = LIST_URL;
  protected readonly types = PRODUCT_TYPES;
  protected readonly priceModes = PRICE_MODES;
  protected readonly trackingModes = TRACKING_MODES;

  protected readonly form = new FormGroup({
    type: new FormControl<ProductType>('simple', { nonNullable: true }),
    parent_id: new FormControl<string | null>(null),
    sku: new FormControl('', { nonNullable: true }),
    name: new FormControl('', { nonNullable: true }),
    category_id: new FormControl<string | null>(null),
    brand_id: new FormControl<string | null>(null),
    stock_unit_id: new FormControl<string | null>(null),
    purchase_unit_id: new FormControl<string | null>(null),
    sales_unit_id: new FormControl<string | null>(null),
    is_weighable: new FormControl(false, { nonNullable: true }),
    price_mode: new FormControl<PriceMode>('per_unit', { nonNullable: true }),
    plu_code: new FormControl('', { nonNullable: true }),
    track_stock: new FormControl(true, { nonNullable: true }),
    tracking_mode: new FormControl<TrackingMode>('quantity', { nonNullable: true }),
    variant_options: new FormControl('', { nonNullable: true }),
    variant_values: new FormControl('', { nonNullable: true }),
  });

  protected readonly product = signal<Product | null>(null);
  protected readonly loading = signal(false);
  protected readonly loadError = signal<ApiError | null>(null);
  protected readonly stale = signal(false);
  protected readonly readOnly = computed(() => !!this.product()?.deleted_at);

  protected readonly type = toSignal(this.form.controls.type.valueChanges, {
    initialValue: this.form.controls.type.value,
  });
  private readonly stockUnitId = toSignal(this.form.controls.stock_unit_id.valueChanges, {
    initialValue: null,
  });
  /** Every unit the form has seen, for the dimension convenience (AC-43). */
  private readonly units = signal<ReadonlyMap<string, Unit>>(new Map());
  private readonly requestedUnits = new Set<string>();
  protected readonly parentName = signal<string | null>(null);

  protected readonly showJson = computed(() => hasVariantJson(this.type()));
  protected readonly parentLabel = computed(() => this.parentName());
  private readonly stockDimension = computed(() => {
    const id = this.stockUnitId();
    return id ? (this.units().get(id)?.dimension ?? null) : null;
  });
  /** Only units of the stock unit's dimension; everything until the dimension is known. */
  protected readonly sameDimension = computed<((u: Unit) => boolean) | null>(() => {
    const dimension = this.stockDimension();
    return dimension === null ? null : (u: Unit) => u.dimension === dimension;
  });

  private readonly formErrors = FormErrors.forPage(FIELDS);
  protected readonly errors = this.formErrors.fields;
  protected readonly summary = this.formErrors.summary;
  /** Where Cancel goes: back to the parent's page when a variant is being added from it. */
  protected readonly cancelUrl = computed(() =>
    this.presetParent() && !this.id() ? `${LIST_URL}/${this.presetParent()}` : LIST_URL,
  );

  protected readonly searchCategories = (p: ListParams): Observable<ApiResult<Category[]>> =>
    this.api.categories.list(p);
  protected readonly searchBrands = (p: ListParams): Observable<ApiResult<Brand[]>> =>
    this.api.brands.list(p);
  protected readonly searchParents = (p: ListParams): Observable<ApiResult<Product[]>> =>
    this.api.products.list({ ...p, type: 'variant_parent' });
  protected readonly searchUnits = (p: ListParams): Observable<ApiResult<Unit[]>> =>
    this.api.units.list(p).pipe(tap((result) => result.data.forEach((u) => this.remember(u))));
  protected readonly categoryOption = (c: Category): PickerOption => ({
    id: c.id,
    label: `${c.code} - ${c.name}`,
  });
  protected readonly brandOption = (b: Brand): PickerOption => ({
    id: b.id,
    label: `${b.code} - ${b.name}`,
  });
  protected readonly productOption = (p: Product): PickerOption => ({
    id: p.id,
    label: `${p.sku} - ${p.name}`,
  });
  protected readonly unitOption = (u: Unit): PickerOption => ({
    id: u.id,
    label: `${u.code} - ${u.name}`,
  });

  constructor() {
    // A stock unit picked from a search is already known; only an edited product's is not.
    this.form.controls.stock_unit_id.valueChanges.subscribe((id) => this.ensureUnit(id));
  }

  ngOnInit(): void {
    if (this.id()) {
      this.load();
      return;
    }
    const preset = this.presetType();
    if (preset && (PRODUCT_TYPES as readonly string[]).includes(preset)) {
      this.form.controls.type.setValue(preset as ProductType);
    }
    const parent = this.presetParent();
    if (parent) {
      this.form.controls.parent_id.setValue(parent);
      this.api.products.get(parent).subscribe({
        next: (p) => this.parentName.set(`${p.sku} - ${p.name}`),
        error: () => undefined,
      });
    }
  }

  protected unitLabel(id: string | null | undefined): string | null {
    return id ? (this.units().get(id)?.code ?? null) : null;
  }

  /** Loads, or reloads, the product and replaces whatever is typed (AC-46 Reload). */
  protected load(): void {
    const id = this.id();
    if (!id) {
      return;
    }
    this.loading.set(true);
    this.loadError.set(null);
    this.api.products.get(id).subscribe({
      next: (p) => {
        this.product.set(p);
        this.stale.set(false);
        this.formErrors.clearAll();
        this.form.enable({ emitEvent: false });
        this.form.reset({
          type: p.type,
          parent_id: p.parent_id,
          sku: p.sku,
          name: p.name,
          category_id: p.category_id,
          brand_id: p.brand_id,
          stock_unit_id: p.stock_unit_id,
          purchase_unit_id: p.purchase_unit_id,
          sales_unit_id: p.sales_unit_id,
          is_weighable: p.is_weighable,
          price_mode: p.price_mode,
          plu_code: p.plu_code ?? '',
          track_stock: p.track_stock,
          tracking_mode: p.tracking_mode,
          variant_options: pretty(p.variant_options),
          variant_values: pretty(p.variant_values),
        });
        this.ensureUnit(p.purchase_unit_id);
        this.ensureUnit(p.sales_unit_id);
        if (p.deleted_at) {
          this.form.disable({ emitEvent: false });
        }
        this.loading.set(false);
      },
      error: (err: ApiError) => {
        this.loadError.set(err);
        this.loading.set(false);
      },
    });
  }

  protected save(): void {
    if (this.readOnly()) {
      return;
    }
    this.formErrors.startSave();
    this.stale.set(false);
    const problems = this.validate();
    if (this.formErrors.blockWith(problems)) {
      return;
    }
    const loaded = this.product();
    const id = this.id();
    const request =
      id && loaded
        ? this.api.products.update(id, this.updateBody(loaded))
        : this.api.products.create(this.createBody());
    this.state.run(request, {
      next: () => void this.router.navigateByUrl(this.afterSaveUrl()),
      error: (err) => {
        if (err.hasCode(STALE_CODE)) {
          this.stale.set(true);
          return;
        }
        this.formErrors.refuse(err);
      },
    });
  }

  private afterSaveUrl(): string {
    const parent = this.presetParent();
    return parent && !this.id() ? `${LIST_URL}/${parent}` : LIST_URL;
  }

  private remember(unit: Unit): void {
    if (this.units().get(unit.id) === unit) {
      return;
    }
    this.units.update((known) => new Map(known).set(unit.id, unit));
  }

  /** Fetches a unit the form has not seen (an edited product's own units). */
  private ensureUnit(id: string | null): void {
    if (!id || this.units().has(id) || this.requestedUnits.has(id)) {
      return;
    }
    this.requestedUnits.add(id);
    this.api.units.get(id).subscribe({
      next: (u) => this.remember(u),
      error: () => this.requestedUnits.delete(id),
    });
  }

  /** Self-contained rules only (D-17); everything else is the server's refusal. */
  private validate(): Record<string, string> {
    const v = this.form.getRawValue();
    const problems: Record<string, string> = {};
    const sku = v.sku.trim();
    if (sku === '') {
      problems['sku'] = 'SKU is required.';
    } else if (sku.length > MAX_SKU) {
      problems['sku'] = `SKU can be at most ${MAX_SKU} characters.`;
    }
    if (v.name.trim() === '') {
      problems['name'] = 'Name is required.';
    }
    if (!v.category_id) {
      problems['category_id'] = 'Category is required.';
    }
    if (!this.id() && !v.stock_unit_id) {
      problems['stock_unit_id'] = 'Stock unit is required.';
    }
    if (v.type === 'variant' && !v.parent_id) {
      problems['parent_id'] = 'Parent product is required for a variant.';
    }
    if (v.plu_code.trim().length > MAX_PLU) {
      problems['plu_code'] = `PLU code can be at most ${MAX_PLU} characters.`;
    }
    if (hasVariantJson(v.type)) {
      for (const field of ['variant_options', 'variant_values'] as const) {
        const text = v[field].trim();
        if (text !== '' && parseJson(text) === null) {
          problems[field] =
            `${field === 'variant_options' ? 'Variant options' : 'Variant values'} must be valid JSON.`;
        }
      }
    }
    return problems;
  }

  /** The fields shared by create and update. `parent_id` only for a variant (AC-40). */
  private commonBody(): Omit<ProductCreate, 'stock_unit_id' | 'plu_code'> {
    const v = this.form.getRawValue();
    return {
      type: v.type,
      ...(v.type === 'variant' && v.parent_id ? { parent_id: v.parent_id } : {}),
      sku: v.sku.trim(),
      name: v.name.trim(),
      category_id: v.category_id as string,
      brand_id: v.brand_id,
      purchase_unit_id: v.purchase_unit_id,
      sales_unit_id: v.sales_unit_id,
      is_weighable: v.is_weighable,
      price_mode: v.price_mode,
      track_stock: v.track_stock,
      tracking_mode: v.tracking_mode,
    };
  }

  private createBody(): ProductCreate {
    const v = this.form.getRawValue();
    const plu = v.plu_code.trim();
    const body: ProductCreate = {
      ...this.commonBody(),
      stock_unit_id: v.stock_unit_id as string,
      ...(plu !== '' ? { plu_code: plu } : {}),
    };
    if (hasVariantJson(v.type)) {
      this.addJson(body, null);
    }
    return body;
  }

  /** No `stock_unit_id` (it cannot change); the loaded `version` always travels (AC-42, AC-46). */
  private updateBody(loaded: Product): ProductUpdate {
    const v = this.form.getRawValue();
    const plu = v.plu_code.trim();
    const body: ProductUpdate = { ...this.commonBody(), version: loaded.version };
    if (plu !== (loaded.plu_code ?? '')) {
      body.plu_code = plu === '' ? null : plu;
    }
    if (hasVariantJson(v.type)) {
      this.addJson(body, loaded);
    }
    return body;
  }

  /** Blank means absent; on update, a blank over a stored value clears it with `null`. */
  private addJson(body: ProductCreate | ProductUpdate, loaded: Product | null): void {
    const v = this.form.getRawValue();
    for (const field of ['variant_options', 'variant_values'] as const) {
      const text = v[field].trim();
      if (text !== '') {
        body[field] = (parseJson(text) as { value: unknown }).value;
      } else if (loaded && loaded[field] !== null && loaded[field] !== undefined) {
        body[field] = null;
      }
    }
  }
}
