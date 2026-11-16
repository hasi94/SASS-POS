import { ApiError } from '@flexipos/shared';
import { FORBIDDEN_MESSAGE, describeApiError } from './describe-api-error';

describe('describeApiError (T-036 AC-8, AC-16)', () => {
  it('gives every 403 the permission text, whatever the server said', () => {
    const view = describeApiError(new ApiError(403, 'Forbidden by policy X.', [], 'req-1'));
    expect(view).toEqual({ message: FORBIDDEN_MESSAGE, requestId: 'req-1' });
    expect(FORBIDDEN_MESSAGE).toBe('You do not have permission to see or change this.');
  });

  it('shows the error message for other statuses, with the request id', () => {
    expect(describeApiError(new ApiError(500, 'Server broke.', [], 'req-2'))).toEqual({
      message: 'Server broke.',
      requestId: 'req-2',
    });
  });

  it('shows "Cannot reach the server." for status 0', () => {
    expect(describeApiError(new ApiError(0, 'Cannot reach the server.')).message).toBe(
      'Cannot reach the server.',
    );
  });

  it('has no request id when the server sent none', () => {
    expect(describeApiError(new ApiError(404, 'Gone.')).requestId).toBeNull();
  });

  it('falls back to a generic message for something that is not an error', () => {
    expect(describeApiError('boom')).toEqual({ message: 'Something went wrong.', requestId: null });
  });
});
