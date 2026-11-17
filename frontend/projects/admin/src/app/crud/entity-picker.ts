import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  OnInit,
  computed,
  forwardRef,
  inject,
  input,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';
import { ApiResult, DEFAULT_PER_PAGE, ListParams } from '@flexipos/shared';
import { Observable, Subject, Subscription, debounceTime, distinctUntilChanged, map } from 'rxjs';
import { ApiErrorView, describeApiError } from './describe-api-error';
import { SEARCH_DEBOUNCE_MS } from './list-view';

export interface PickerOption {
  id: string;
  label: string;
}

let nextPickerId = 0;

/**
 * Choose one row of another list (T-036 plan 1.6; AC-29, AC-36, AC-39, AC-43). A search box
 * asks the server for at most 25 matches (`search`, `per_page=25`, debounced) and a select
 * lists them; nothing is ever loaded unbounded. The form value is the row's id, or null.
 *
 * `filter` hides rows locally (the dimension convenience; the server still decides).
 * `initialLabel` names the current value on an edit form even when that row is not among the
 * first matches. `noneLabel`, when set, adds an option that clears the value ("No parent").
 */
@Component({
  selector: 'adm-entity-picker',
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [
    { provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => EntityPicker), multi: true },
  ],
  template: `
    <div class="adm-picker">
      <label [for]="searchId">Find {{ label() }}</label>
      <input
        type="search"
        [id]="searchId"
        [disabled]="disabled()"
        [attr.aria-describedby]="describedByWithLookup()"
        (input)="typed($any($event.target).value)"
      />
      <label [for]="selectId">{{ label() }}</label>
      <select
        [id]="selectId"
        [disabled]="disabled()"
        [attr.aria-describedby]="describedByWithLookup()"
        [attr.aria-invalid]="invalid() ? 'true' : null"
        (change)="choose($any($event.target).value)"
        (blur)="onTouched()"
      >
        @if (noneLabel(); as none) {
          <option value="" [selected]="value() === null">{{ none }}</option>
        } @else {
          <option value="" [selected]="value() === null">Select…</option>
        }
        @for (o of options(); track o.id) {
          <option [value]="o.id" [selected]="o.id === value()">{{ o.label }}</option>
        }
      </select>
      <p class="fp-error adm-live" role="status" [id]="lookupErrorId">
        {{ lookupError()?.message ?? '' }}
      </p>
    </div>
  `,
})
export class EntityPicker<T> implements ControlValueAccessor, OnInit {
  readonly label = input.required<string>();
  /** Sends one list request; the picker only ever asks for page 1 of 25. */
  readonly search = input.required<(params: ListParams) => Observable<ApiResult<T[]>>>();
  readonly optionOf = input.required<(row: T) => PickerOption>();
  readonly filter = input<((row: T) => boolean) | null>(null);
  readonly initialLabel = input<string | null>(null);
  readonly noneLabel = input<string | null>(null);
  /** Id of the element holding this control's error text, for `aria-describedby`. */
  readonly describedBy = input<string | null>(null);
  readonly invalid = input(false);

  protected readonly searchId = `adm-picker-search-${nextPickerId}`;
  protected readonly selectId = `adm-picker-select-${nextPickerId}`;
  protected readonly lookupErrorId = `adm-picker-lookup-error-${nextPickerId++}`;
  protected readonly value = signal<string | null>(null);
  protected readonly disabled = signal(false);
  private readonly rows = signal<T[]>([]);
  /** Why the lookup failed (a 403 for a person without the list's view key), shown under the select. */
  protected readonly lookupError = signal<ApiErrorView | null>(null);
  protected readonly describedByWithLookup = computed(() => {
    const ids = [this.describedBy(), this.lookupError() ? this.lookupErrorId : null];
    const joined = ids.filter((id): id is string => !!id).join(' ');
    return joined === '' ? null : joined;
  });

  /** The matches (after `filter`), plus the current value when they do not include it. */
  protected readonly options = computed<PickerOption[]>(() => {
    const keep = this.filter();
    const toOption = this.optionOf();
    const found = (keep ? this.rows().filter(keep) : this.rows()).map((row) => toOption(row));
    const current = this.value();
    if (current === null || found.some((o) => o.id === current)) {
      return found;
    }
    return [{ id: current, label: this.initialLabel() ?? current }, ...found];
  });

  private readonly typedText = new Subject<string>();
  private request: Subscription | null = null;
  private onChange: (value: string | null) => void = () => {};
  protected onTouched: () => void = () => {};

  constructor() {
    this.typedText
      .pipe(
        map((text) => text.trim()),
        debounceTime(SEARCH_DEBOUNCE_MS),
        distinctUntilChanged(),
        takeUntilDestroyed(),
      )
      .subscribe((text) => this.load(text));
    inject(DestroyRef).onDestroy(() => this.request?.unsubscribe());
  }

  ngOnInit(): void {
    this.load('');
  }

  writeValue(value: string | null): void {
    this.value.set(value ?? null);
  }

  registerOnChange(fn: (value: string | null) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(isDisabled: boolean): void {
    this.disabled.set(isDisabled);
  }

  protected typed(text: string): void {
    this.typedText.next(text);
  }

  protected choose(id: string): void {
    const next = id === '' ? null : id;
    this.value.set(next);
    this.onChange(next);
    this.onTouched();
  }

  private load(term: string): void {
    this.request?.unsubscribe();
    const params: ListParams = { page: 1, per_page: DEFAULT_PER_PAGE };
    if (term !== '') {
      params.search = term;
    }
    this.request = this.search()(params).subscribe({
      next: (result) => {
        this.lookupError.set(null);
        this.rows.set(result.data);
      },
      error: (err: unknown) => {
        this.rows.set([]);
        this.lookupError.set(describeApiError(err));
      },
    });
  }
}
