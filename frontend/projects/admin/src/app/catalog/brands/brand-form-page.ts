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
import { ApiError, Brand, BrandWrite } from '@flexipos/shared';
import { ApiErrorPanel } from '../../crud/api-error-panel';
import { FieldError } from '../../crud/field-error';
import { FormErrorSummary } from '../../crud/form-error-summary';
import { FormErrors } from '../../crud/form-errors';
import { SubmitState } from '../../crud/submit-state';
import { CatalogApi } from '../catalog-api';

const FIELDS = ['code', 'name'] as const;
const LIST_URL = '/catalog/brands';

/** Create or edit one brand (T-036 AC-33). A soft-deleted brand opens read-only (D-11). */
@Component({
  selector: 'adm-brand-form-page',
  imports: [ApiErrorPanel, FieldError, FormErrorSummary, ReactiveFormsModule, RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>{{ id() ? 'Edit brand' : 'New brand' }}</h1>
    @if (loadError(); as err) {
      <adm-api-error-panel [error]="err" (retry)="load()" />
    } @else if (loading()) {
      <p class="fp-muted" role="status">Loading…</p>
    } @else {
      @if (readOnly()) {
        <p><span class="adm-badge">Deleted</span> This brand is deleted and cannot be changed.</p>
      }
      <adm-form-error-summary [messages]="summary()" />
      <form class="adm-form-grid" [formGroup]="form" (ngSubmit)="save()" novalidate>
        <label
          >Code
          <input
            formControlName="code"
            [attr.aria-invalid]="errors()['code'] ? 'true' : null"
            [attr.aria-describedby]="errors()['code'] ? 'brand-code-error' : null"
          />
        </label>
        <adm-field-error [id]="'brand-code-error'" [message]="errors()['code']" />
        <label
          >Name
          <input
            formControlName="name"
            [attr.aria-invalid]="errors()['name'] ? 'true' : null"
            [attr.aria-describedby]="errors()['name'] ? 'brand-name-error' : null"
          />
        </label>
        <adm-field-error [id]="'brand-name-error'" [message]="errors()['name']" />
        @if (!readOnly()) {
          <div class="adm-actions">
            <button type="submit" class="fp-primary" [disabled]="state.busy()">
              {{ state.busy() ? 'Saving…' : 'Save' }}
            </button>
            <a [routerLink]="listUrl">Cancel</a>
          </div>
        } @else {
          <a [routerLink]="listUrl">Back to brands</a>
        }
      </form>
    }
  `,
})
export class BrandFormPage implements OnInit {
  /** The route's `:id`; absent on the create form. */
  readonly id = input<string>();

  private readonly api = inject(CatalogApi);
  private readonly router = inject(Router);
  protected readonly state = new SubmitState();
  protected readonly listUrl = LIST_URL;

  protected readonly form = new FormGroup({
    code: new FormControl('', { nonNullable: true }),
    name: new FormControl('', { nonNullable: true }),
  });
  protected readonly brand = signal<Brand | null>(null);
  protected readonly loading = signal(false);
  protected readonly loadError = signal<ApiError | null>(null);
  protected readonly readOnly = computed(() => !!this.brand()?.deleted_at);

  private readonly formErrors = FormErrors.forPage(FIELDS);
  protected readonly errors = this.formErrors.fields;
  protected readonly summary = this.formErrors.summary;

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
    this.api.brands.get(id).subscribe({
      next: (b) => {
        this.brand.set(b);
        this.form.patchValue({ code: b.code, name: b.name });
        if (b.deleted_at) {
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
    const body: BrandWrite = { code: v.code.trim(), name: v.name.trim() };
    const id = this.id();
    this.state.run(id ? this.api.brands.update(id, body) : this.api.brands.create(body), {
      next: () => void this.router.navigateByUrl(LIST_URL),
      error: (err) => {
        this.formErrors.refuse(err);
      },
    });
  }
}
