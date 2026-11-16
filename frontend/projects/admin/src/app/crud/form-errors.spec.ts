import { FormControl, Validators } from '@angular/forms';
import { ApiError } from '@flexipos/shared';
import { FORBIDDEN_MESSAGE } from './describe-api-error';
import { clientErrorFor, mapServerErrors, notBlank } from './form-errors';

const KNOWN = ['code', 'name', 'parent_id'];

describe('mapServerErrors (T-036 AC-17)', () => {
  it('puts an item with a known field on that field', () => {
    const err = new ApiError(422, 'Invalid.', [
      { code: 'catalog.duplicate', field: 'code', detail: 'Code is used.' },
    ]);
    expect(mapServerErrors(err, KNOWN)).toEqual({ fields: { code: 'Code is used.' }, summary: [] });
  });

  it('puts an item with an unknown field in the summary', () => {
    const err = new ApiError(422, 'Invalid.', [
      { code: 'x', field: 'mystery', detail: 'Something odd.' },
    ]);
    expect(mapServerErrors(err, KNOWN)).toEqual({ fields: {}, summary: ['Something odd.'] });
  });

  it('puts an item with a null or missing field in the summary', () => {
    const err = new ApiError(409, 'Blocked.', [
      { code: 'catalog.in_use', field: null, detail: 'Has children.' },
      { code: 'y', detail: 'Another.' },
    ]);
    expect(mapServerErrors(err, KNOWN).summary).toEqual(['Has children.', 'Another.']);
  });

  it('falls back to the refusal message when an item has no detail', () => {
    const err = new ApiError(409, 'Blocked by Drinks.', [{ code: 'catalog.in_use' }]);
    expect(mapServerErrors(err, KNOWN).summary).toEqual(['Blocked by Drinks.']);
  });

  it('puts the message in the summary when there are no items at all', () => {
    expect(mapServerErrors(new ApiError(500, 'Server broke.'), KNOWN)).toEqual({
      fields: {},
      summary: ['Server broke.'],
    });
  });

  it('keeps every message when one field gets several', () => {
    const err = new ApiError(422, 'Invalid.', [
      { code: 'a', field: 'name', detail: 'First.' },
      { code: 'b', field: 'name', detail: 'Second.' },
    ]);
    expect(mapServerErrors(err, KNOWN).fields['name']).toBe('First. Second.');
  });

  it('shows only the permission text for a 403', () => {
    const err = new ApiError(403, 'No.', [
      { code: 'kernel.forbidden', field: 'code', detail: 'x' },
    ]);
    expect(mapServerErrors(err, KNOWN)).toEqual({ fields: {}, summary: [FORBIDDEN_MESSAGE] });
  });
});

describe('clientErrorFor and notBlank', () => {
  it('names the field for a missing value', () => {
    const c = new FormControl('', [notBlank]);
    expect(clientErrorFor(c, 'Code')).toBe('Code is required.');
  });

  it('treats spaces only as missing', () => {
    expect(clientErrorFor(new FormControl('   ', [notBlank]), 'Name')).toBe('Name is required.');
  });

  it('reports too long, too small and too large', () => {
    expect(clientErrorFor(new FormControl('abcdef', [Validators.maxLength(3)]), 'SKU')).toBe(
      'SKU can be at most 3 characters.',
    );
    expect(clientErrorFor(new FormControl(-1, [Validators.min(0)]), 'Precision')).toBe(
      'Precision must be at least 0.',
    );
    expect(clientErrorFor(new FormControl(9, [Validators.max(4)]), 'Precision')).toBe(
      'Precision must be at most 4.',
    );
  });

  it('is null for a valid control', () => {
    expect(clientErrorFor(new FormControl('ok', [notBlank]), 'Code')).toBeNull();
  });
});
