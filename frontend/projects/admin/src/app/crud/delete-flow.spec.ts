import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ApiClient, ApiError } from '@flexipos/shared';
import { DeleteFlow } from './delete-flow';
import { FORBIDDEN_MESSAGE, describeApiError } from './describe-api-error';
import { envelope, refusal } from './testing/crud-testing';

interface Row {
  id: string;
  name: string;
}

const ROW: Row = { id: 'C1', name: 'Drinks' };

describe('DeleteFlow (T-036 AC-19, AC-20)', () => {
  let http: HttpTestingController;
  let reloads: number;
  let flow: DeleteFlow<Row>;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
    const api = TestBed.inject(ApiClient);
    reloads = 0;
    flow = new DeleteFlow<Row>(
      (row) => api.delete(`things/${row.id}`),
      () => (reloads += 1),
    );
  });

  afterEach(() => http.verify());

  it('asks first: nothing is sent until confirm', () => {
    flow.ask(ROW);
    expect(flow.pending()).toBe(ROW);
    http.expectNone(() => true);
  });

  it('sends exactly one DELETE however often confirm is pressed', () => {
    flow.ask(ROW);
    flow.confirm();
    flow.confirm();
    expect(flow.busy()).toBe(true);
    const req = http.expectOne('/api/v1/things/C1');
    expect(req.request.method).toBe('DELETE');
    req.flush(envelope(null));
    expect(flow.busy()).toBe(false);
  });

  it('reloads and closes the question on success', () => {
    flow.ask(ROW);
    flow.confirm();
    http.expectOne('/api/v1/things/C1').flush(envelope(null));
    expect(reloads).toBe(1);
    expect(flow.pending()).toBeNull();
    expect(flow.error()).toBeNull();
  });

  it('cancel sends nothing and closes the question', () => {
    flow.ask(ROW);
    flow.cancel();
    expect(flow.pending()).toBeNull();
    http.expectNone(() => true);
    flow.confirm();
    http.expectNone(() => true);
  });

  it('a 409 shows the server message, does not reload, and can be tried again', () => {
    flow.ask(ROW);
    flow.confirm();
    http
      .expectOne('/api/v1/things/C1')
      .flush(
        ...refusal(
          409,
          'catalog.in_use',
          undefined,
          undefined,
          'Category Drinks cannot be deleted: it holds the active product WATER-500.',
        ),
      );
    expect(reloads).toBe(0);
    // The raw refusal is held; the error panel words it.
    expect(flow.error()).toBeInstanceOf(ApiError);
    expect(flow.error()?.status).toBe(409);
    expect(describeApiError(flow.error()).message).toContain('WATER-500');
    expect(describeApiError(flow.error()).requestId).toBe('req-refused');
    expect(flow.pending()).toBeNull();
    expect(flow.busy()).toBe(false);

    flow.ask(ROW);
    expect(flow.error()).toBeNull();
    flow.confirm();
    http.expectOne('/api/v1/things/C1').flush(envelope(null));
    expect(reloads).toBe(1);
  });

  it('a 403 shows the permission text', () => {
    flow.ask(ROW);
    flow.confirm();
    http.expectOne('/api/v1/things/C1').flush(...refusal(403, 'kernel.forbidden'));
    expect(flow.error()?.status).toBe(403);
    expect(describeApiError(flow.error()).message).toBe(FORBIDDEN_MESSAGE);
  });
});
