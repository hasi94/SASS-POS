import { ApiError } from '@flexipos/shared';

/** What a screen shows for a failed request (T-036 AC-8, AC-16). */
export interface ApiErrorView {
  message: string;
  /** The server's `meta.request_id`, when it sent one. */
  requestId: string | null;
}

/** The text for any 403, whatever the server said (AC-8). */
export const FORBIDDEN_MESSAGE = 'You do not have permission to see or change this.';

/**
 * Turns whatever a request failed with into text for the screen. A 403 gets the permission
 * message; every other status shows the error's own message (which for status 0 is "Cannot
 * reach the server."). The text is always rendered as text, never as HTML.
 */
export function describeApiError(err: unknown): ApiErrorView {
  if (err instanceof ApiError) {
    return {
      message: err.status === 403 ? FORBIDDEN_MESSAGE : err.message,
      requestId: err.requestId,
    };
  }
  return {
    message: err instanceof Error && err.message ? err.message : 'Something went wrong.',
    requestId: null,
  };
}
