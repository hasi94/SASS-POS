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
