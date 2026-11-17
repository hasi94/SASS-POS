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
  BarcodeCreate,
  ListParams,
  Product,
  ProductBarcode,
  Unit,
} from '@flexipos/shared';
import { Observable } from 'rxjs';
import { ApiErrorPanel } from '../../crud/api-error-panel';
import { EntityPicker, PickerOption } from '../../crud/entity-picker';
import { isGreaterThanZero } from '../../crud/decimal';
import { FieldError } from '../../crud/field-error';
import { FormErrorSummary } from '../../crud/form-error-summary';
import { FormErrors } from '../../crud/form-errors';
import { SubmitState } from '../../crud/submit-state';
import { CatalogApi } from '../catalog-api';

const FIELDS = ['barcode', 'unit_id', 'quantity', 'is_primary'] as const;
const MAX_BARCODE = 64;

/**
 * Add or edit one barcode of a product (T-036 AC-49 to AC-51). On edit the barcode value is
 * shown read-only and never sent: the server cannot change it. The unit picker offers only
 * units of the product's stock-unit dimension (a convenience; the server still decides).
 */
@Component({
  selector: 'adm-barcode-form-page',
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
    <h1>{{ barcodeId() ? 'Edit barcode' : 'Add barcode' }}</h1>
    @if (loadError(); as err) {
      <adm-api-error-panel [error]="err" (retry)="load()" />
    } @else if (loading()) {
      <p class="fp-muted" role="status">Loading…</p>
    } @else {
      @if (product(); as p) {
        <p class="fp-muted">
          Product: {{ p.sku }} - {{ p.name }} (stock unit {{ p.stock_unit_code }})
        </p>
        @if (p.deleted_at) {
          <p>
            <span class="adm-badge">Deleted</span> This product is deleted and cannot be changed.
          </p>
        }
      }
      <adm-form-error-summary [messages]="summary()" />
      <form class="adm-form-grid" [formGroup]="form" (ngSubmit)="save()" novalidate>
        <label
          >Barcode
          <input
            formControlName="barcode"
            [readonly]="!!barcodeId()"
            [attr.aria-invalid]="errors()['barcode'] ? 'true' : null"
            [attr.aria-describedby]="errors()['barcode'] ? 'barcode-barcode-error' : null"
          />
        </label>
        @if (barcodeId()) {
          <p class="fp-muted">A barcode value cannot be changed. Delete it and add a new one.</p>
        }
        <adm-field-error [id]="'barcode-barcode-error'" [message]="errors()['barcode']" />

        <adm-entity-picker
          formControlName="unit_id"
          label="Unit"
          noneLabel="Stock unit"
          [search]="searchUnits"
          [optionOf]="unitOption"
          [filter]="sameDimension()"
          [initialLabel]="barcode()?.unit_code ?? null"
          [invalid]="!!errors()['unit_id']"
          [describedBy]="errors()['unit_id'] ? 'barcode-unit_id-error' : null"
        />
        <adm-field-error [id]="'barcode-unit_id-error'" [message]="errors()['unit_id']" />

        <label
          >Quantity
          <input
            formControlName="quantity"
            inputmode="decimal"
            [attr.aria-invalid]="errors()['quantity'] ? 'true' : null"
            [attr.aria-describedby]="errors()['quantity'] ? 'barcode-quantity-error' : null"
          />
        </label>
        <adm-field-error [id]="'barcode-quantity-error'" [message]="errors()['quantity']" />

        <label
          ><span><input type="checkbox" formControlName="is_primary" /> Primary barcode</span>
        </label>
        <adm-field-error [id]="'barcode-is_primary-error'" [message]="errors()['is_primary']" />

        @if (!readOnly()) {
          <div class="adm-actions">
            <button type="submit" class="fp-primary" [disabled]="state.busy()">
              {{ state.busy() ? 'Saving…' : 'Save' }}
            </button>
            <a [routerLink]="productUrl()">Cancel</a>
          </div>
        } @else {
          <a [routerLink]="productUrl()">Back to the product</a>
        }
      </form>
    }
  `,
})
export class BarcodeFormPage implements OnInit {
  /** The product's id (`:id`). */
  readonly id = input.required<string>();
  /** The barcode's id; absent on the add form. */
  readonly barcodeId = input<string>();

  private readonly api = inject(CatalogApi);
  private readonly router = inject(Router);
  protected readonly state = new SubmitState();

  protected readonly form = new FormGroup({
    barcode: new FormControl('', { nonNullable: true }),
    unit_id: new FormControl<string | null>(null),
    quantity: new FormControl('1', { nonNullable: true }),
    is_primary: new FormControl(false, { nonNullable: true }),
  });
  protected readonly product = signal<Product | null>(null);
  protected readonly barcode = signal<ProductBarcode | null>(null);
  protected readonly stockUnit = signal<Unit | null>(null);
  protected readonly loading = signal(false);
  protected readonly loadError = signal<ApiError | null>(null);
  protected readonly readOnly = computed(() => !!this.product()?.deleted_at);
  protected readonly productUrl = computed(() => `/catalog/products/${this.id()}`);
  protected readonly sameDimension = computed<((u: Unit) => boolean) | null>(() => {
    const dimension = this.stockUnit()?.dimension;
    return dimension ? (u: Unit) => u.dimension === dimension : null;
  });

  private readonly formErrors = FormErrors.forPage(FIELDS);
  protected readonly errors = this.formErrors.fields;
  protected readonly summary = this.formErrors.summary;

  protected readonly searchUnits = (p: ListParams): Observable<ApiResult<Unit[]>> =>
    this.api.units.list(p);
  protected readonly unitOption = (u: Unit): PickerOption => ({
    id: u.id,
    label: `${u.code} - ${u.name}`,
  });

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.loadError.set(null);
    this.api.products.get(this.id()).subscribe({
      next: (p) => {
        this.product.set(p);
        if (p.deleted_at) {
          this.form.disable();
        }
        this.api.units.get(p.stock_unit_id).subscribe({
          next: (u) => this.stockUnit.set(u),
          error: () => undefined,
        });
        this.loadBarcode();
      },
      error: (err: ApiError) => this.fail(err),
    });
  }

  private loadBarcode(): void {
    const barcodeId = this.barcodeId();
    if (!barcodeId) {
      this.loading.set(false);
      return;
    }
    this.api
      .barcodes(this.id())
      .get(barcodeId)
      .subscribe({
        next: (b) => {
          this.barcode.set(b);
          this.form.patchValue({
            barcode: b.barcode,
            unit_id: b.unit_id,
            quantity: b.quantity,
            is_primary: b.is_primary,
          });
          this.loading.set(false);
        },
        error: (err: ApiError) => this.fail(err),
      });
  }

  private fail(err: ApiError): void {
    this.loadError.set(err);
    this.loading.set(false);
  }

  protected save(): void {
    if (this.readOnly()) {
      return;
    }
    this.formErrors.startSave();
    const v = this.form.getRawValue();
    const problems: Record<string, string> = {};
    const value = v.barcode.trim();
    if (!this.barcodeId()) {
      if (value === '') {
        problems['barcode'] = 'Barcode is required.';
      } else if (value.length > MAX_BARCODE) {
        problems['barcode'] = `Barcode can be at most ${MAX_BARCODE} characters.`;
      }
    }
    const quantity = v.quantity.trim();
    if (!isGreaterThanZero(quantity)) {
      problems['quantity'] = 'Quantity must be a number greater than 0.';
    }
    if (this.formErrors.blockWith(problems)) {
      return;
    }
    const rest = { unit_id: v.unit_id, quantity, is_primary: v.is_primary };
    const barcodeId = this.barcodeId();
    const api = this.api.barcodes(this.id());
    const body: BarcodeCreate = { barcode: value, ...rest };
    this.state.run(barcodeId ? api.update(barcodeId, rest) : api.create(body), {
      next: () => void this.router.navigateByUrl(this.productUrl()),
      error: (err) => {
        this.formErrors.refuse(err);
      },
    });
  }
}
