import { HttpErrorResponse, HttpHeaders, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ApiClient, toApiError } from './api-client';
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

/**
 * D-7 (docs/decisions/T-025-frontend-bootstrap.md): the real auth refusal
 * bodies are flat `{error:'<token>'}` shapes, never an envelope. Before the
 * fix, `toApiError()` cannot see the token at all and falls through to the
 * generic "The request failed." branch. These cases are the red-first proof
 * required by the plan (Step 1): they must fail against the unfixed code.
 */
describe('toApiError with a pre-envelope auth refusal (D-7, AC-3)', () => {
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

  function legacyError(
    status: number,
    body: Record<string, unknown>,
    requestId: string | null = 'req-legacy',
  ): HttpErrorResponse {
    return new HttpErrorResponse({
      url: '/api/v1/auth/login',
      status,
      error: body,
      headers: requestId ? new HttpHeaders({ 'X-Request-Id': requestId }) : undefined,
    });
  }

  it('401 invalid_credentials', () => {
    const err = toApiError(legacyError(401, { error: 'invalid_credentials' }));
    expect(err.hasCode('invalid_credentials')).toBe(true);
    expect(err.message).not.toBe('The request failed.');
    expect(err.status).toBe(401);
    expect(err.requestId).toBe('req-legacy');
  });

  it('403 wrong_token_type', () => {
    const err = toApiError(legacyError(403, { error: 'wrong_token_type' }));
    expect(err.hasCode('wrong_token_type')).toBe(true);
    expect(err.message).not.toBe('The request failed.');
    expect(err.status).toBe(403);
  });

  it('403 no_access', () => {
    const err = toApiError(legacyError(403, { error: 'no_access' }));
    expect(err.hasCode('no_access')).toBe(true);
    expect(err.message).not.toBe('The request failed.');
    expect(err.status).toBe(403);
  });

  it('403 tenant_not_servable, carrying the tenant status in detail', () => {
    const err = toApiError(
      legacyError(403, { error: 'tenant_not_servable', status: 'suspended' }),
    );
    expect(err.hasCode('tenant_not_servable')).toBe(true);
    expect(err.message).not.toBe('The request failed.');
    expect(err.fieldDetail('tenant_not_servable') ?? err.errors[0]?.detail).toContain('suspended');
    expect(err.status).toBe(403);
  });

  it('403 employee_unavailable', () => {
    const err = toApiError(legacyError(403, { error: 'employee_unavailable' }));
    expect(err.hasCode('employee_unavailable')).toBe(true);
    expect(err.message).not.toBe('The request failed.');
    expect(err.status).toBe(403);
  });

  it('422 invalid_request, carrying the reason in detail', () => {
    const err = toApiError(
      legacyError(422, { error: 'invalid_request', reason: 'email and password are required' }),
    );
    expect(err.hasCode('invalid_request')).toBe(true);
    expect(err.message).toContain('email and password are required');
    expect(err.status).toBe(422);
  });

  it('503 signing_unavailable', () => {
    const err = toApiError(legacyError(503, { error: 'signing_unavailable' }));
    expect(err.hasCode('signing_unavailable')).toBe(true);
    expect(err.message).not.toBe('The request failed.');
    expect(err.status).toBe(503);
  });

  it('end-to-end through api.post(): a real auth failure body surfaces its token', () => {
    let err: unknown;
    api.post('auth/login', { email: 'a@b.c', password: 'nope' }).subscribe({
      error: (e) => (err = e),
    });
    http.expectOne('/api/v1/auth/login').flush(
      { error: 'invalid_credentials' },
      { status: 401, statusText: 'Unauthorized' },
    );
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).hasCode('invalid_credentials')).toBe(true);
  });

  it('control: an envelope error body still takes the envelope branch, not the legacy one', () => {
    // This body has both an envelope shape (success/meta) and, incidentally,
    // an `error` key nested in its own `errors` item code — it must still be
    // read as an envelope, proving the new branch does not misread one.
    const err = toApiError(
      new HttpErrorResponse({
        url: '/api/v1/things',
        status: 422,
        error: {
          success: false,
          data: null,
          message: 'Invalid.',
          errors: [{ code: 'catalog.name_taken', field: 'name', detail: 'Name is taken.' }],
          meta: { request_id: 'req-envelope' },
        },
      }),
    );
    expect(err.hasCode('catalog.name_taken')).toBe(true);
    expect(err.requestId).toBe('req-envelope');
  });
});

describe('ApiClient.postRaw() (D-7, AC-2)', () => {
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

  it('returns a raw, non-enveloped 2xx body unchanged', () => {
    let got: unknown;
    api.postRaw('auth/login', { email: 'a@b.c', password: 'x' }).subscribe((d) => (got = d));
    http
      .expectOne('/api/v1/auth/login')
      .flush({ access_token: 'at', refresh_token: 'rt', tenants: [] });
    expect(got).toEqual({ access_token: 'at', refresh_token: 'rt', tenants: [] });
  });

  it('maps a 401 legacy refusal body through toApiError()', () => {
    let err: unknown;
    api.postRaw('auth/login', {}).subscribe({ error: (e) => (err = e) });
    http
      .expectOne('/api/v1/auth/login')
      .flush({ error: 'invalid_credentials' }, { status: 401, statusText: 'Unauthorized' });
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).hasCode('invalid_credentials')).toBe(true);
  });

  it('post() still rejects a raw, non-enveloped 2xx body (existing behaviour, unchanged)', () => {
    let err: unknown;
    api.post('things').subscribe({ error: (e) => (err = e) });
    http.expectOne('/api/v1/things').flush({ access_token: 'at' });
    expect(err).toBeInstanceOf(ApiError);
  });
});
