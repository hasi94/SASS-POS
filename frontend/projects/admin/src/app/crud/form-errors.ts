import { ElementRef, Injector, afterNextRender, computed, inject, signal } from '@angular/core';
import { AbstractControl, ValidationErrors } from '@angular/forms';
import { ApiError } from '@flexipos/shared';
import { FORBIDDEN_MESSAGE } from './describe-api-error';

/** Server refusals split for a form (T-036 AC-17). */
export interface MappedErrors {
  /** Message by field name, for fields the form knows. */
  fields: Record<string, string>;
  /** Everything else, for the summary at the top of the form. */
  summary: string[];
}

/**
 * Places each `errors[]` item of a refusal: an item whose `field` the form knows goes to
 * that field; an item with no field or an unknown field goes to the summary, and so does
 * the refusal's own message when it carries no items. A 403 is always the permission text.
 */
export function mapServerErrors(err: ApiError, knownFields: readonly string[]): MappedErrors {
  if (err.status === 403) {
    return { fields: {}, summary: [FORBIDDEN_MESSAGE] };
  }
  const fields: Record<string, string> = {};
  const summary: string[] = [];
  for (const item of err.errors) {
    const text = item.detail || err.message;
    if (item.field && knownFields.includes(item.field)) {
      fields[item.field] = fields[item.field] ? `${fields[item.field]} ${text}` : text;
    } else {
      summary.push(text);
    }
  }
  if (err.errors.length === 0) {
    summary.push(err.message);
  }
  return { fields, summary };
}

/** `Validators.required` that also rejects text made only of spaces. */
export function notBlank(control: AbstractControl): ValidationErrors | null {
  const v: unknown = control.value;
  return v === null || v === undefined || (typeof v === 'string' && v.trim() === '')
    ? { required: true }
    : null;
}

/** The first client-side message for a control, named after its label; null when valid. */
export function clientErrorFor(control: AbstractControl, label: string): string | null {
  const errors = control.errors;
  if (!errors) {
    return null;
  }
  if (errors['required']) {
    return `${label} is required.`;
  }
  const maxlength = errors['maxlength'] as { requiredLength: number } | undefined;
  if (maxlength) {
    return `${label} can be at most ${maxlength.requiredLength} characters.`;
  }
  const min = errors['min'] as { min: number } | undefined;
  if (min) {
    return `${label} must be at least ${min.min}.`;
  }
  const max = errors['max'] as { max: number } | undefined;
  if (max) {
    return `${label} must be at most ${max.max}.`;
  }
  const custom = Object.values(errors).find((v): v is string => typeof v === 'string');
  return custom ?? `${label} is not valid.`;
}

/**
 * Moves focus to the first invalid control inside `root`, else to the error summary. A person
 * who submits a form that is blocked lands on the problem instead of staying on the button.
 */
export function focusFirstProblem(root: HTMLElement): void {
  const target =
    root.querySelector<HTMLElement>('[aria-invalid="true"]') ??
    root.querySelector<HTMLElement>('.adm-form-summary');
  target?.focus();
}

/**
 * The error state of one form page (T-036 review W-1, W-8): messages found by the page's own
 * checks (`blockWith`), messages from a server refusal (`refuse`), and the summary for what no
 * field can take. A client message wins over a server one for the same field.
 */
export class FormErrors {
  private readonly client = signal<Record<string, string>>({});
  private readonly server = signal<Record<string, string>>({});
  private readonly summaryMessages = signal<string[]>([]);

  /** Message by field name, for the field components. */
  readonly fields = computed<Record<string, string>>(() => ({
    ...this.server(),
    ...this.client(),
  }));
  /** The refusals no field took. */
  readonly summary = this.summaryMessages.asReadonly();

  constructor(
    private readonly knownFields: readonly string[],
    private readonly focusProblem: () => void = () => {},
  ) {}

  /**
   * For a page component, in a field initialiser: focus after a blocked submit goes to the
   * first `[aria-invalid="true"]` control of the page, else to the summary, once the new
   * messages are in the DOM.
   */
  static forPage(knownFields: readonly string[]): FormErrors {
    const host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
    const injector = inject(Injector);
    return new FormErrors(knownFields, () =>
      afterNextRender(() => focusFirstProblem(host), { injector }),
    );
  }

  /** Forgets the last server refusal; call at the start of a save. */
  startSave(): void {
    this.server.set({});
    this.summaryMessages.set([]);
  }

  /** Forgets everything, for a form that was reset. */
  clearAll(): void {
    this.startSave();
    this.client.set({});
  }

  /** Shows these client-side messages. Returns true (and moves focus) when any exist. */
  blockWith(problems: Record<string, string>): boolean {
    this.client.set(problems);
    const blocked = Object.keys(problems).length > 0;
    if (blocked) {
      this.focusProblem();
    }
    return blocked;
  }

  /** Places a server refusal on its fields, and the rest in the summary. */
  refuse(err: ApiError): void {
    const mapped = mapServerErrors(err, this.knownFields);
    this.server.set(mapped.fields);
    this.summaryMessages.set(mapped.summary);
  }
}
