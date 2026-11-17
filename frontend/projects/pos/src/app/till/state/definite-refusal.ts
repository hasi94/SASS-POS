import { ApiError, refusalOf } from '@flexipos/shared';

/**
 * True only when the server answered a write in its own words with a refusal that says nothing was
 * recorded: a 4xx carrying one of our codes, other than `replay_conflict` (which says an entry with
 * this id already exists). Everything else (no answer, a 5xx, a reply that is not an envelope, an
 * unknown or missing code) leaves it unknown whether the write was recorded, so the same ids must
 * be used again and a fresh id must never be made.
 */
export function definitelyRefused(err: unknown): boolean {
  if (!(err instanceof ApiError) || err.status < 400 || err.status >= 500) {
    return false;
  }
  const code = refusalOf(err);
  return code !== null && code !== 'replay_conflict';
}
