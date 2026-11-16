import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ApiClient } from './api-client';
import { ApiResult } from './api-list';
import { ApiError } from './envelope';

describe('ApiClient.getWithMeta() (T-036 AC-13)', () => {
  let api: ApiClient;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(ApiClient);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('returns data and meta together, with pagination', () => {
    let got: ApiResult<{ id: number }[]> | undefined;
    api.getWithMeta<{ id: number }[]>('things').subscribe((r) => (got = r));
    http.expectOne('/api/v1/things').flush({
      success: true,
      data: [{ id: 1 }],
      message: '',
      errors: [],
      meta: { request_id: 'req-1', pagination: { page: 2, per_page: 25, total: 51 } },
    });
    expect(got?.data).toEqual([{ id: 1 }]);
    expect(got?.meta.request_id).toBe('req-1');
    expect(got?.meta.pagination).toEqual({ page: 2, per_page: 25, total: 51 });
  });

  it('returns meta without pagination when the server sent none', () => {
    let got: ApiResult<unknown> | undefined;
    api.getWithMeta('things/1').subscribe((r) => (got = r));
    http.expectOne('/api/v1/things/1').flush({
      success: true,
      data: { id: 1 },
      message: '',
      errors: [],
      meta: { request_id: 'r' },
    });
    expect(got?.meta.pagination).toBeUndefined();
    expect(got?.data).toEqual({ id: 1 });
  });

  it('turns a success:false envelope into an ApiError carrying the request id', () => {
    let err: unknown;
    api.getWithMeta('things').subscribe({ error: (e) => (err = e) });
    http.expectOne('/api/v1/things').flush({
      success: false,
      data: null,
      message: 'Nope.',
      errors: [],
      meta: { request_id: 'req-9' },
    });
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).requestId).toBe('req-9');
    expect((err as ApiError).message).toBe('Nope.');
  });

  it('turns an HTTP 422 envelope into an ApiError with field items', () => {
    let err: unknown;
    api.getWithMeta('things').subscribe({ error: (e) => (err = e) });
    http.expectOne('/api/v1/things').flush(
      {
        success: false,
        data: null,
        message: 'Invalid.',
        errors: [{ code: 'kernel.validation_failed', field: 'search', detail: 'Too long.' }],
        meta: { request_id: 'req-422' },
      },
      { status: 422, statusText: 'Unprocessable' },
    );
    const apiErr = err as ApiError;
    expect(apiErr.status).toBe(422);
    expect(apiErr.fieldDetail('search')).toBe('Too long.');
    expect(apiErr.requestId).toBe('req-422');
  });

  it('rejects a body that is not an envelope', () => {
    let err: unknown;
    api.getWithMeta('things').subscribe({ error: (e) => (err = e) });
    http.expectOne('/api/v1/things').flush({ hello: 'world' });
    expect(err).toBeInstanceOf(ApiError);
  });

  it('passes query params through and drops undefined ones', () => {
    api
      .getWithMeta('things', { page: 3, per_page: 25, search: 'abc', type: undefined })
      .subscribe();
    const req = http.expectOne((r) => r.url === '/api/v1/things');
    expect(req.request.params.get('page')).toBe('3');
    expect(req.request.params.get('per_page')).toBe('25');
    expect(req.request.params.get('search')).toBe('abc');
    expect(req.request.params.has('type')).toBe(false);
    req.flush({ success: true, data: [], message: '', errors: [], meta: { request_id: 'r' } });
  });
});
