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
import { ApiError, ModifierGroup, ModifierGroupWrite } from '@flexipos/shared';
import { ApiErrorPanel } from '../../crud/api-error-panel';
import { FieldError } from '../../crud/field-error';
import { FormErrorSummary } from '../../crud/form-error-summary';
import { FormErrors } from '../../crud/form-errors';
import { SubmitState } from '../../crud/submit-state';
import { CatalogApi } from '../catalog-api';

const FIELDS = ['name', 'min_select', 'max_select'] as const;
const LIST_URL = '/catalog/modifiers';

const isCount = (n: number | null): n is number => n !== null && Number.isInteger(n) && n >= 0;

/**
 * Create or edit a modifier group (T-036 AC-52). An empty maximum means "no limit" and is sent
 * as `null`. A soft-deleted group opens read-only (D-11).
 */
@Component({
  selector: 'adm-modifier-group-form-page',
  imports: [ApiErrorPanel, FieldError, FormErrorSummary, ReactiveFormsModule, RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>{{ id() ? 'Edit modifier group' : 'New modifier group' }}</h1>
    @if (loadError(); as err) {
      <adm-api-error-panel [error]="err" (retry)="load()" />
    } @else if (loading()) {
      <p class="fp-muted" role="status">Loading…</p>
    } @else {
      @if (readOnly()) {
        <p>
          <span class="adm-badge">Deleted</span> This modifier group is deleted and cannot be
          changed.
        </p>
      }
      <adm-form-error-summary [messages]="summary()" />
      <form class="adm-form-grid" [formGroup]="form" (ngSubmit)="save()" novalidate>
        <label
          >Name
          <input
            formControlName="name"
            [attr.aria-invalid]="errors()['name'] ? 'true' : null"
            [attr.aria-describedby]="errors()['name'] ? 'group-name-error' : null"
          />
        </label>
        <adm-field-error [id]="'group-name-error'" [message]="errors()['name']" />
        <label
          >Minimum choices
          <input
            type="number"
            formControlName="min_select"
            min="0"
            step="1"
            [attr.aria-invalid]="errors()['min_select'] ? 'true' : null"
            [attr.aria-describedby]="errors()['min_select'] ? 'group-min-error' : null"
          />
        </label>
        <adm-field-error [id]="'group-min-error'" [message]="errors()['min_select']" />
        <label
          >Maximum choices (empty for no limit)
          <input
            type="number"
            formControlName="max_select"
            min="0"
            step="1"
            [attr.aria-invalid]="errors()['max_select'] ? 'true' : null"
            [attr.aria-describedby]="errors()['max_select'] ? 'group-max-error' : null"
          />
        </label>
        <adm-field-error [id]="'group-max-error'" [message]="errors()['max_select']" />
        @if (!readOnly()) {
          <div class="adm-actions">
            <button type="submit" class="fp-primary" [disabled]="state.busy()">
              {{ state.busy() ? 'Saving…' : 'Save' }}
            </button>
            <a [routerLink]="listUrl">Cancel</a>
          </div>
        } @else {
          <a [routerLink]="listUrl">Back to modifier groups</a>
        }
      </form>
    }
  `,
})
export class ModifierGroupFormPage implements OnInit {
  /** The route's `:id`; absent on the create form. */
  readonly id = input<string>();

  private readonly api = inject(CatalogApi);
  private readonly router = inject(Router);
  protected readonly state = new SubmitState();
  protected readonly listUrl = LIST_URL;

  protected readonly form = new FormGroup({
    name: new FormControl('', { nonNullable: true }),
    min_select: new FormControl<number | null>(0),
    max_select: new FormControl<number | null>(null),
  });
  protected readonly group = signal<ModifierGroup | null>(null);
  protected readonly loading = signal(false);
  protected readonly loadError = signal<ApiError | null>(null);
  protected readonly readOnly = computed(() => !!this.group()?.deleted_at);

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
    this.api.modifierGroups.get(id).subscribe({
      next: (g) => {
        this.group.set(g);
        this.form.patchValue({ name: g.name, min_select: g.min_select, max_select: g.max_select });
        if (g.deleted_at) {
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
    if (v.name.trim() === '') {
      problems['name'] = 'Name is required.';
    }
    if (!isCount(v.min_select)) {
      problems['min_select'] = 'Minimum must be a whole number, 0 or more.';
    }
    if (v.max_select !== null && !isCount(v.max_select)) {
      problems['max_select'] = 'Maximum must be a whole number, 0 or more, or empty for no limit.';
    } else if (isCount(v.min_select) && v.max_select !== null && v.min_select > v.max_select) {
      problems['max_select'] = 'Maximum cannot be smaller than the minimum.';
    }
    if (this.formErrors.blockWith(problems)) {
      return;
    }
    const body: ModifierGroupWrite = {
      name: v.name.trim(),
      min_select: v.min_select as number,
      max_select: v.max_select,
    };
    const id = this.id();
    this.state.run(
      id ? this.api.modifierGroups.update(id, body) : this.api.modifierGroups.create(body),
      {
        next: () => void this.router.navigateByUrl(LIST_URL),
        error: (err) => {
          this.formErrors.refuse(err);
        },
      },
    );
  }
}
