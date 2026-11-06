import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ApiClient } from './api-client';
import { ApiError } from './envelope';

const meta = { request_id: 'req-1' };

describe('ApiClient', () => {
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

  it('unwraps data from a successful envelope', () => {
    let got: unknown;
    api.get('things/1').subscribe((d) => (got = d));
    http
      .expectOne('/api/v1/things/1')
      .flush({ success: true, data: { id: 1 }, message: '', errors: [], meta });
    expect(got).toEqual({ id: 1 });
  });

  it('turns an error envelope into an ApiError carrying the codes', () => {
    let err: unknown;
    api.post('things', {}).subscribe({ error: (e) => (err = e) });
    http.expectOne('/api/v1/things').flush(
      {
        success: false,
        data: null,
        message: 'Invalid.',
        errors: [{ code: 'catalog.name_taken', field: 'name', detail: 'Name is taken.' }],
        meta,
      },
      { status: 422, statusText: 'Unprocessable' },
    );
    expect(err).toBeInstanceOf(ApiError);
    const apiErr = err as ApiError;
    expect(apiErr.status).toBe(422);
    expect(apiErr.hasCode('catalog.name_taken')).toBe(true);
    expect(apiErr.fieldDetail('name')).toBe('Name is taken.');
    expect(apiErr.requestId).toBe('req-1');
  });

  it('reports an unreachable server as status 0', () => {
    let err: unknown;
    api.get('things').subscribe({ error: (e) => (err = e) });
    http.expectOne('/api/v1/things').error(new ProgressEvent('error'));
    expect((err as ApiError).status).toBe(0);
  });

  it('rejects a body that is not an envelope', () => {
    let err: unknown;
    api.get('things').subscribe({ error: (e) => (err = e) });
    http.expectOne('/api/v1/things').flush({ hello: 'world' });
    expect(err).toBeInstanceOf(ApiError);
  });
});
