import {
  ChangeDetectionStrategy,
  Component,
  OnInit,
  computed,
  inject,
  input,
  signal,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormControl, FormGroup, ReactiveFormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import {
  ApiError,
  ListParams,
  UNIT_DIMENSIONS,
  Unit,
  UnitCreate,
  UnitDimension,
  UnitUpdate,
} from '@flexipos/shared';
import { ApiErrorPanel } from '../../crud/api-error-panel';
import { EntityPicker, PickerOption } from '../../crud/entity-picker';
import { FieldError } from '../../crud/field-error';
import { FormErrorSummary } from '../../crud/form-error-summary';
import { mapServerErrors } from '../../crud/form-errors';
import { SubmitState } from '../../crud/submit-state';
import { CatalogApi } from '../catalog-api';

const FIELDS = ['code', 'name', 'dimension', 'precision', 'base_unit_id', 'factor'] as const;
const LIST_URL = '/catalog/units';
const POSITIVE_DECIMAL = /^\+?(\d+(\.\d*)?|\.\d+)$/;

/**
 * Create or edit one unit (T-036 AC-34 to AC-36). Client checks mirror only the rules that need
 * no other data (D-17): required fields, precision 0 to 4, and base unit and factor together.
 * A base unit of another dimension is NOT refused here: the server decides and its refusal is
 * shown on the base unit field. The dimension cannot change, so on edit it is read-only and
 * never sent.
 */
@Component({
  selector: 'adm-unit-form-page',
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
    <h1>{{ id() ? 'Edit unit' : 'New unit' }}</h1>
    @if (loadError(); as err) {
      <adm-api-error-panel [error]="err" (retry)="load()" />
    } @else if (loading()) {
      <p class="fp-muted" role="status">Loading…</p>
    } @else {
      <adm-form-error-summary [messages]="summary()" />
      <form class="adm-form-grid" [formGroup]="form" (ngSubmit)="save()" novalidate>
        <label
          >Code
          <input
            formControlName="code"
            [attr.aria-invalid]="errors()['code'] ? 'true' : null"
            [attr.aria-describedby]="errors()['code'] ? 'unit-code-error' : null"
          />
        </label>
        <adm-field-error [id]="'unit-code-error'" [message]="errors()['code']" />
        <label
          >Name
          <input
            formControlName="name"
            [attr.aria-invalid]="errors()['name'] ? 'true' : null"
            [attr.aria-describedby]="errors()['name'] ? 'unit-name-error' : null"
          />
        </label>
        <adm-field-error [id]="'unit-name-error'" [message]="errors()['name']" />
        @if (id()) {
          <p>
            Dimension: <strong>{{ dimension() }}</strong>
            <span class="fp-muted"> (a unit's dimension cannot be changed)</span>
          </p>
        } @else {
          <label
            >Dimension
            <select
              formControlName="dimension"
              [attr.aria-invalid]="errors()['dimension'] ? 'true' : null"
              [attr.aria-describedby]="errors()['dimension'] ? 'unit-dimension-error' : null"
            >
              @for (d of dimensions; track d) {
                <option [value]="d">{{ d }}</option>
              }
            </select>
          </label>
          <adm-field-error [id]="'unit-dimension-error'" [message]="errors()['dimension']" />
        }
        <label
          >Precision
          <input
            type="number"
            formControlName="precision"
            min="0"
            max="4"
            step="1"
            [attr.aria-invalid]="errors()['precision'] ? 'true' : null"
            [attr.aria-describedby]="errors()['precision'] ? 'unit-precision-error' : null"
          />
        </label>
        <adm-field-error [id]="'unit-precision-error'" [message]="errors()['precision']" />
        <adm-entity-picker
          formControlName="base_unit_id"
          label="Base unit"
          noneLabel="No base unit"
          [search]="searchUnits"
          [optionOf]="optionOf"
          [filter]="sameDimension()"
          [initialLabel]="unit()?.base_unit_code ?? null"
          [describedBy]="errors()['base_unit_id'] ? 'unit-base-error' : null"
          [invalid]="!!errors()['base_unit_id']"
        />
        <adm-field-error [id]="'unit-base-error'" [message]="errors()['base_unit_id']" />
        <label
          >Factor
          <input
            formControlName="factor"
            inputmode="decimal"
            [attr.aria-invalid]="errors()['factor'] ? 'true' : null"
            [attr.aria-describedby]="errors()['factor'] ? 'unit-factor-error' : null"
          />
        </label>
        <adm-field-error [id]="'unit-factor-error'" [message]="errors()['factor']" />
        <div class="adm-actions">
          <button type="submit" class="fp-primary" [disabled]="state.busy()">
            {{ state.busy() ? 'Saving…' : 'Save' }}
          </button>
          <a [routerLink]="listUrl">Cancel</a>
        </div>
      </form>
    }
  `,
})
export class UnitFormPage implements OnInit {
  /** The route's `:id`; absent on the create form. */
  readonly id = input<string>();

  private readonly api = inject(CatalogApi);
  private readonly router = inject(Router);
  protected readonly state = new SubmitState();
  protected readonly listUrl = LIST_URL;
  protected readonly dimensions = UNIT_DIMENSIONS;

  protected readonly form = new FormGroup({
    code: new FormControl('', { nonNullable: true }),
    name: new FormControl('', { nonNullable: true }),
    dimension: new FormControl<UnitDimension>('count', { nonNullable: true }),
    precision: new FormControl<number | null>(4),
    base_unit_id: new FormControl<string | null>(null),
    factor: new FormControl('', { nonNullable: true }),
  });
  protected readonly dimension = toSignal(this.form.controls.dimension.valueChanges, {
    initialValue: this.form.controls.dimension.value,
  });
  protected readonly unit = signal<Unit | null>(null);
  protected readonly loading = signal(false);
  protected readonly loadError = signal<ApiError | null>(null);

  private readonly clientErrors = signal<Record<string, string>>({});
  private readonly serverErrors = signal<Record<string, string>>({});
  protected readonly summary = signal<string[]>([]);
  protected readonly errors = computed<Record<string, string>>(() => ({
    ...this.serverErrors(),
    ...this.clientErrors(),
  }));

  protected readonly searchUnits = (params: ListParams) => this.api.units.list(params);
  protected readonly optionOf = (u: Unit): PickerOption => ({
    id: u.id,
    label: `${u.code} (${u.name})`,
  });
  /** A convenience only (AC-36): other dimensions and the unit itself are not offered. */
  protected readonly sameDimension = computed(() => {
    const wanted = this.dimension();
    const own = this.id();
    return (u: Unit) => u.dimension === wanted && u.id !== own;
  });

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
    this.api.units.get(id).subscribe({
      next: (u) => {
        this.unit.set(u);
        this.form.patchValue({
          code: u.code,
          name: u.name,
          dimension: u.dimension,
          precision: u.precision,
          base_unit_id: u.base_unit_id,
          factor: u.factor ?? '',
        });
        this.loading.set(false);
      },
      error: (err: ApiError) => {
        this.loadError.set(err);
        this.loading.set(false);
      },
    });
  }

  protected save(): void {
    this.serverErrors.set({});
    this.summary.set([]);
    const v = this.form.getRawValue();
    const factor = v.factor.trim();
    const problems: Record<string, string> = {};
    if (v.code.trim() === '') {
      problems['code'] = 'Code is required.';
    }
    if (v.name.trim() === '') {
      problems['name'] = 'Name is required.';
    }
    const precision = v.precision;
    if (precision === null || !Number.isInteger(precision) || precision < 0 || precision > 4) {
      problems['precision'] = 'Precision must be a whole number from 0 to 4.';
    }
    if ((v.base_unit_id === null) !== (factor === '')) {
      problems['factor'] = 'Base unit and factor go together.';
    } else if (factor !== '' && (!POSITIVE_DECIMAL.test(factor) || Number(factor) <= 0)) {
      problems['factor'] = 'Factor must be greater than 0.';
    }
    this.clientErrors.set(problems);
    if (Object.keys(problems).length > 0) {
      return;
    }
    const common: UnitUpdate = {
      code: v.code.trim(),
      name: v.name.trim(),
      precision: precision as number,
      base_unit_id: v.base_unit_id,
      factor: factor === '' ? null : factor,
    };
    const id = this.id();
    const call = id
      ? this.api.units.update(id, common)
      : this.api.units.create({ ...common, dimension: v.dimension } satisfies UnitCreate);
    this.state.run(call, {
      next: () => void this.router.navigateByUrl(LIST_URL),
      error: (err) => {
        const mapped = mapServerErrors(err, FIELDS);
        this.serverErrors.set(mapped.fields);
        this.summary.set(mapped.summary);
      },
    });
  }
}
