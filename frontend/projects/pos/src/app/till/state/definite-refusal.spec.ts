import { ApiError } from '@flexipos/shared';
import { definitelyRefused } from './definite-refusal';

const coded = (status: number, code: string): ApiError =>
  new ApiError(status, 'refused', [{ code, field: null }], 'req-1');

describe('definitelyRefused', () => {
  it.each([
    ['a 422 exceeds_balance', coded(422, 'sales.exceeds_balance')],
    ['a 403 approval_already_used', coded(403, 'sales.approval_already_used')],
    ['a 404 not_found', coded(404, 'not_found')],
  ])('is true for %s', (_name, err) => {
    expect(definitelyRefused(err)).toBe(true);
  });

  it.each([
    ['no answer', new ApiError(0, 'Network error')],
    ['a 500', new ApiError(500, 'boom')],
    ['a 504', new ApiError(504, 'timeout')],
    ['a 503 that carries a known code', coded(503, 'sales.exceeds_balance')],
    ['a reply that is not an envelope', new ApiError(200, 'not an API envelope')],
    ['a 422 with an unknown code', coded(422, 'something_new')],
    ['a 422 with no errors', new ApiError(422, 'refused')],
    ['a replay_conflict', coded(403, 'sales.replay_conflict')],
    ['a thrown Error', new Error('boom')],
  ])('is false for %s', (_name, err) => {
    expect(definitelyRefused(err)).toBe(false);
  });
});
