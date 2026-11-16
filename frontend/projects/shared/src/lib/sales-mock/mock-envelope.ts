import { REFUSAL_STATUS, SalesRefusalCode } from '../sales/contract/refusals';
import { MockState } from './mock-state';

/**
 * The one string that proves the sales mock is in a bundle (T-037 D-27, AC-8): every answer the
 * mock gives carries `meta.request_id = 'flexipos-sales-mock-' + a six digit sequence`, so the
 * production-bundle grep looks for it. It sits in code the interceptor always executes, which is
 * in a bundle exactly when the mock is.
 */
export const SALES_MOCK_SENTINEL = 'flexipos-sales-mock';

export interface MockSuccess {
  readonly ok: true;
  readonly status: number;
  readonly data: unknown;
}

export interface MockRefusal {
  readonly ok: false;
  readonly status: number;
  /** The namespaced wire code, for example `sales.no_open_shift` (P-3). */
  readonly code: string;
  readonly message: string;
  readonly field: string | null;
  readonly detail: string;
}

export type MockResult = MockSuccess | MockRefusal;

/** The envelope every `/api/v1` reply is wrapped in (docs/architecture.md section 8). */
export interface MockEnvelope {
  success: boolean;
  data: unknown;
  message: string;
  errors: { code: string; field: string | null; detail: string }[];
  meta: { request_id: string };
}

export function ok(data: unknown, status = 200): MockSuccess {
  return { ok: true, status, data };
}

const KERNEL_CODES: readonly SalesRefusalCode[] = ['not_found', 'validation'];
const PRICING_CODES: readonly SalesRefusalCode[] = [
  'unknown_product',
  'no_effective_rate',
  'not_applicable',
  'inactive',
  'unknown_discount',
  'discount_not_allowed',
];

function wireCode(code: SalesRefusalCode, module?: string): string {
  if (module) {
    return `${module}.${code}`;
  }
  if (code === 'validation') {
    return 'kernel.validation_failed';
  }
  if (KERNEL_CODES.includes(code)) {
    return `kernel.${code}`;
  }
  if (PRICING_CODES.includes(code)) {
    return `pricing.${code}`;
  }
  return `sales.${code}`;
}

export interface RefuseOptions {
  /** The fact the refusal names (P-4): its name, or the failing input for `validation`. */
  field?: string;
  /** The fact's machine value, or the message for `validation`. */
  detail?: string;
  /** Overrides the module prefix of the wire code (the scan miss is `catalog.not_found`). */
  module?: string;
}

/** A refusal: the status comes from `REFUSAL_STATUS`, the wire code is namespaced (P-3). */
export function refuse(
  code: SalesRefusalCode,
  message: string,
  options: RefuseOptions = {},
): MockRefusal {
  return {
    ok: false,
    status: REFUSAL_STATUS[code],
    code: wireCode(code, options.module),
    message,
    field: options.field ?? null,
    detail: options.detail ?? message,
  };
}

/** `validation`: names the failing input and says why (the T-021 native shape). */
export function invalid(field: string, detail: string): MockRefusal {
  return refuse('validation', 'The given data was invalid.', { field, detail });
}

/** The same refusal with a different HTTP status, for the calls that are not a Sales refusal. */
export function unauthenticated(): MockRefusal {
  return {
    ok: false,
    status: 401,
    code: 'kernel.unauthenticated',
    message: 'Unauthenticated.',
    field: null,
    detail: 'Sign in again.',
  };
}

function nextRequestId(state: MockState): string {
  state.requestSeq = state.requestSeq + 1;
  return `${SALES_MOCK_SENTINEL}-${String(state.requestSeq).padStart(6, '0')}`;
}

/** The body of a reply, built after the handler ran. Key order here is part of the fixtures. */
export function envelopeOf(state: MockState, result: MockResult): MockEnvelope {
  const requestId = nextRequestId(state);
  if (result.ok) {
    return {
      success: true,
      data: result.data,
      message: '',
      errors: [],
      meta: { request_id: requestId },
    };
  }
  return {
    success: false,
    data: null,
    message: result.message,
    errors: [{ code: result.code, field: result.field, detail: result.detail }],
    meta: { request_id: requestId },
  };
}
