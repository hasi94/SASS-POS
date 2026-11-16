import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { ListParams, Product, Unit, UnitConversionCreate } from '@flexipos/shared';
import { EntityPicker, PickerOption } from '../../crud/entity-picker';
import { FieldError } from '../../crud/field-error';
import { FormErrorSummary } from '../../crud/form-error-summary';
import { mapServerErrors } from '../../crud/form-errors';
import { SubmitState } from '../../crud/submit-state';
import { CatalogApi } from '../catalog-api';

const FIELDS = ['from_unit_id', 'to_unit_id', 'factor', 'product_id'] as const;
const LIST_URL = '/catalog/units';
const POSITIVE_DECIMAL = /^\+?(\d+(\.\d*)?|\.\d+)$/;

/**
 * Create one unit conversion (T-036 AC-37). Only the self-contained rules run here: both units
 * chosen, different from each other, and a positive factor. Cross-dimension, duplicate pairs and
 * a product-scoped to-unit that is not the product's stock unit are the server's to refuse.
 */
@Component({
  selector: 'adm-unit-conversion-form-page',
  imports: [EntityPicker, FieldError, FormErrorSummary, ReactiveFormsModule, RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>New unit conversion</h1>
    <adm-form-error-summary [messages]="summary()" />
    <form class="adm-form-grid" [formGroup]="form" (ngSubmit)="save()" novalidate>
      <adm-entity-picker
        formControlName="from_unit_id"
        label="From unit"
        [search]="searchUnits"
        [optionOf]="unitOption"
        [describedBy]="errors()['from_unit_id'] ? 'conversion-from-error' : null"
        [invalid]="!!errors()['from_unit_id']"
      />
      <adm-field-error [id]="'conversion-from-error'" [message]="errors()['from_unit_id']" />
      <adm-entity-picker
        formControlName="to_unit_id"
        label="To unit"
        [search]="searchUnits"
        [optionOf]="unitOption"
        [describedBy]="errors()['to_unit_id'] ? 'conversion-to-error' : null"
        [invalid]="!!errors()['to_unit_id']"
      />
      <adm-field-error [id]="'conversion-to-error'" [message]="errors()['to_unit_id']" />
      <label
        >Factor
        <input
          formControlName="factor"
          inputmode="decimal"
          [attr.aria-invalid]="errors()['factor'] ? 'true' : null"
          [attr.aria-describedby]="errors()['factor'] ? 'conversion-factor-error' : null"
        />
      </label>
      <adm-field-error [id]="'conversion-factor-error'" [message]="errors()['factor']" />
      <adm-entity-picker
        formControlName="product_id"
        label="Product"
        noneLabel="All products"
        [search]="searchProducts"
        [optionOf]="productOption"
        [describedBy]="errors()['product_id'] ? 'conversion-product-error' : null"
        [invalid]="!!errors()['product_id']"
      />
      <adm-field-error [id]="'conversion-product-error'" [message]="errors()['product_id']" />
      <div class="adm-actions">
        <button type="submit" class="fp-primary" [disabled]="state.busy()">
          {{ state.busy() ? 'Saving…' : 'Save' }}
        </button>
        <a [routerLink]="listUrl">Cancel</a>
      </div>
    </form>
  `,
})
export class UnitConversionFormPage {
  private readonly api = inject(CatalogApi);
  private readonly router = inject(Router);
  protected readonly state = new SubmitState();
  protected readonly listUrl = LIST_URL;

  protected readonly form = new FormGroup({
    from_unit_id: new FormControl<string | null>(null),
    to_unit_id: new FormControl<string | null>(null),
    factor: new FormControl('', { nonNullable: true }),
    product_id: new FormControl<string | null>(null),
  });

  private readonly clientErrors = signal<Record<string, string>>({});
  private readonly serverErrors = signal<Record<string, string>>({});
  protected readonly summary = signal<string[]>([]);
  protected readonly errors = computed<Record<string, string>>(() => ({
    ...this.serverErrors(),
    ...this.clientErrors(),
  }));

  protected readonly searchUnits = (params: ListParams) => this.api.units.list(params);
  protected readonly searchProducts = (params: ListParams) => this.api.products.list(params);
  protected readonly unitOption = (u: Unit): PickerOption => ({
    id: u.id,
    label: `${u.code} (${u.name})`,
  });
  protected readonly productOption = (p: Product): PickerOption => ({
    id: p.id,
    label: `${p.sku} ${p.name}`,
  });

  protected save(): void {
    this.serverErrors.set({});
    this.summary.set([]);
    const v = this.form.getRawValue();
    const factor = v.factor.trim();
    const problems: Record<string, string> = {};
    if (v.from_unit_id === null) {
      problems['from_unit_id'] = 'From unit is required.';
    }
    if (v.to_unit_id === null) {
      problems['to_unit_id'] = 'To unit is required.';
    } else if (v.to_unit_id === v.from_unit_id) {
      problems['to_unit_id'] = 'From and to units must be different.';
    }
    if (factor === '') {
      problems['factor'] = 'Factor is required.';
    } else if (!POSITIVE_DECIMAL.test(factor) || Number(factor) <= 0) {
      problems['factor'] = 'Factor must be greater than 0.';
    }
    this.clientErrors.set(problems);
    if (Object.keys(problems).length > 0) {
      return;
    }
    const body: UnitConversionCreate = {
      from_unit_id: v.from_unit_id as string,
      to_unit_id: v.to_unit_id as string,
      factor,
      product_id: v.product_id,
    };
    this.state.run(this.api.unitConversions.create(body), {
      next: () => void this.router.navigateByUrl(LIST_URL),
      error: (err) => {
        const mapped = mapServerErrors(err, FIELDS);
        this.serverErrors.set(mapped.fields);
        this.summary.set(mapped.summary);
      },
    });
  }
}
