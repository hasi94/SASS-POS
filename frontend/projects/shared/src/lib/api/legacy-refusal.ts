import { ApiError } from './envelope';
import { isEnvelope } from './envelope';

/**
 * The flat, pre-envelope auth refusal body this backend sends (T-021 D-1,
 * until FP-369 moves auth onto the envelope): `{"error":"<token>"}`, or
 * `{"error":"invalid_request","reason":"<text>"}`, or
 * `{"error":"tenant_not_servable","status":"<tenant status>"}`.
 * See docs/specs/T-025-frontend-bootstrap.md AC-3 for the full table of the
 * seven real shapes.
 */
export interface LegacyRefusal {
  error: string;
  reason?: string;
  status?: string;
}

/** True when `body` looks like a legacy refusal and is not an envelope. */
export function isLegacyRefusal(body: unknown): body is LegacyRefusal {
  return (
    typeof body === 'object' &&
    body !== null &&
    typeof (body as Record<string, unknown>)['error'] === 'string' &&
    !isEnvelope(body)
  );
}

/**
 * Client-side message for each known refusal token, because the legacy
 * bodies carry no human text of their own. Never includes anything from the
 * request (NFR: security) — only the server-supplied `reason`/`status`.
 */
const MESSAGES: Record<string, (body: LegacyRefusal) => string> = {
  invalid_credentials: () => 'Email or password is wrong.',
  no_access: () => 'You do not have access to that company.',
  tenant_not_servable: (b) => `That company cannot be opened while its status is ${b.status}.`,
  employee_unavailable: () => 'Your employee account in that company is not active.',
  wrong_token_type: () => 'Sign in again to change company.',
  signing_unavailable: () => 'Sign-in is temporarily unavailable. Try again later.',
  invalid_request: (b) => b.reason ?? 'The request could not be read.',
};

function messageFor(body: LegacyRefusal): string {
  const build = MESSAGES[body.error];
  return build ? build(body) : `The request was refused (${body.error}).`;
}

/** Turns a legacy refusal body into an {@link ApiError} carrying the real token. */
export function legacyRefusalToApiError(
  status: number,
  body: LegacyRefusal,
  requestId: string | null,
): ApiError {
  return new ApiError(
    status,
    messageFor(body),
    [{ code: body.error, field: null, detail: body.reason ?? body.status ?? undefined }],
    requestId,
  );
}
