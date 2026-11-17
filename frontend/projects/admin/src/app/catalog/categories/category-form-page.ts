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
import { ApiError, Category, CategoryWrite, ListParams } from '@flexipos/shared';
import { ApiErrorPanel } from '../../crud/api-error-panel';
import { EntityPicker, PickerOption } from '../../crud/entity-picker';
import { FieldError } from '../../crud/field-error';
import { FormErrorSummary } from '../../crud/form-error-summary';
import { FormErrors } from '../../crud/form-errors';
import { SubmitState } from '../../crud/submit-state';
import { CatalogApi } from '../catalog-api';

const FIELDS = ['code', 'name', 'parent_id'] as const;
const LIST_URL = '/catalog/categories';

/**
 * Create or edit one category (T-036 AC-29 to AC-31). `path` and `depth` are never shown as
 * inputs and never sent: the server computes them. A soft-deleted category opens read-only (D-11).
 */
@Component({
  selector: 'adm-category-form-page',
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
    <h1>{{ id() ? 'Edit category' : 'New category' }}</h1>
    @if (loadError(); as err) {
      <adm-api-error-panel [error]="err" (retry)="load()" />
    } @else if (loading()) {
      <p class="fp-muted" role="status">Loading…</p>
    } @else {
      @if (readOnly()) {
        <p>
          <span class="adm-badge">Deleted</span> This category is deleted and cannot be changed.
        </p>
      }
      <adm-form-error-summary [messages]="summary()" />
      <form class="adm-form-grid" [formGroup]="form" (ngSubmit)="save()" novalidate>
        <label
          >Code
          <input
            formControlName="code"
            [attr.aria-invalid]="errors()['code'] ? 'true' : null"
            [attr.aria-describedby]="errors()['code'] ? 'category-code-error' : null"
          />
        </label>
        <adm-field-error [id]="'category-code-error'" [message]="errors()['code']" />
        <label
          >Name
          <input
            formControlName="name"
            [attr.aria-invalid]="errors()['name'] ? 'true' : null"
            [attr.aria-describedby]="errors()['name'] ? 'category-name-error' : null"
          />
        </label>
        <adm-field-error [id]="'category-name-error'" [message]="errors()['name']" />
        <adm-entity-picker
          formControlName="parent_id"
          label="Parent category"
          noneLabel="No parent"
          [search]="searchParents"
          [optionOf]="optionOf"
          [filter]="notSelf()"
          [initialLabel]="category()?.parent_name ?? null"
          [describedBy]="errors()['parent_id'] ? 'category-parent-error' : null"
          [invalid]="!!errors()['parent_id']"
        />
        <adm-field-error [id]="'category-parent-error'" [message]="errors()['parent_id']" />
        @if (!readOnly()) {
          <div class="adm-actions">
            <button type="submit" class="fp-primary" [disabled]="state.busy()">
              {{ state.busy() ? 'Saving…' : 'Save' }}
            </button>
            <a [routerLink]="listUrl">Cancel</a>
          </div>
        } @else {
          <a [routerLink]="listUrl">Back to categories</a>
        }
      </form>
    }
  `,
})
export class CategoryFormPage implements OnInit {
  /** The route's `:id`; absent on the create form. */
  readonly id = input<string>();

  private readonly api = inject(CatalogApi);
  private readonly router = inject(Router);
  protected readonly state = new SubmitState();
  protected readonly listUrl = LIST_URL;

  protected readonly form = new FormGroup({
    code: new FormControl('', { nonNullable: true }),
    name: new FormControl('', { nonNullable: true }),
    parent_id: new FormControl<string | null>(null),
  });
  protected readonly category = signal<Category | null>(null);
  protected readonly loading = signal(false);
  protected readonly loadError = signal<ApiError | null>(null);
  protected readonly readOnly = computed(() => !!this.category()?.deleted_at);

  private readonly formErrors = FormErrors.forPage(FIELDS);
  protected readonly errors = this.formErrors.fields;
  protected readonly summary = this.formErrors.summary;

  protected readonly searchParents = (params: ListParams) => this.api.categories.list(params);
  protected readonly optionOf = (c: Category): PickerOption => ({
    id: c.id,
    label: c.depth > 0 ? `${c.name} (${c.path})` : c.name,
  });
  /** A category is never offered as its own parent; the server still decides cycles. */
  protected readonly notSelf = computed(() => {
    const own = this.id();
    return (c: Category) => c.id !== own;
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
    this.api.categories.get(id).subscribe({
      next: (c) => {
        this.category.set(c);
        this.form.patchValue({ code: c.code, name: c.name, parent_id: c.parent_id });
        if (c.deleted_at) {
          this.form.disable();
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
    const v = this.form.getRawValue();
    const problems: Record<string, string> = {};
    if (v.code.trim() === '') {
      problems['code'] = 'Code is required.';
    }
    if (v.name.trim() === '') {
      problems['name'] = 'Name is required.';
    }
    if (this.formErrors.blockWith(problems)) {
      return;
    }
    const body: CategoryWrite = {
      code: v.code.trim(),
      name: v.name.trim(),
      parent_id: v.parent_id,
    };
    const id = this.id();
    this.state.run(id ? this.api.categories.update(id, body) : this.api.categories.create(body), {
      next: () => void this.router.navigateByUrl(LIST_URL),
      error: (err) => {
        this.formErrors.refuse(err);
      },
    });
  }
}
