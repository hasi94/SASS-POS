import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ApiError, SalesApi } from '@flexipos/shared';
import { of, throwError } from 'rxjs';
import { Connectivity, REPROBE_MS } from './connectivity';

const PROBE = '/api/v1/shifts/current';
const answer = { success: true, data: null, message: '', errors: [], meta: { request_id: 'r' } };

describe('Connectivity (AC-74)', () => {
  let conn: Connectivity;
  let http: HttpTestingController;

  beforeEach(() => {
    vi.useFakeTimers();
    TestBed.configureTestingModule({
      providers: [Connectivity, provideHttpClient(), provideHttpClientTesting()],
    });
    conn = TestBed.inject(Connectivity);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  const unreachable = () => new ApiError(0, 'Cannot reach the server.');

  it('starts online', () => {
    expect(conn.online()).toBe(true);
  });

  it('goes offline on a status 0 error', () => {
    conn.reportFailure(unreachable());
    expect(conn.online()).toBe(false);
  });

  it('stays online for a refusal: the server answered', () => {
    conn.reportFailure(new ApiError(403, 'Refused.', [{ code: 'sales.no_open_shift' }]));
    conn.reportFailure(new ApiError(500, 'Boom.'));
    expect(conn.online()).toBe(true);
  });

  it('ignores an error that is not an ApiError', () => {
    conn.reportFailure(new Error('x'));
    expect(conn.online()).toBe(true);
  });

  it('comes back online on a later success', () => {
    conn.reportFailure(unreachable());
    conn.reportSuccess();
    expect(conn.online()).toBe(true);
    vi.advanceTimersByTime(REPROBE_MS * 2);
    http.expectNone(PROBE);
  });

  it('asks the server again every five seconds, and not before', async () => {
    conn.reportFailure(unreachable());
    vi.advanceTimersByTime(REPROBE_MS - 1);
    http.expectNone(PROBE);
    vi.advanceTimersByTime(1);
    http.expectOne(PROBE).flush(answer);
    await vi.advanceTimersByTimeAsync(0);
    expect(conn.online()).toBe(true);
  });

  it('keeps trying while the server stays away', async () => {
    conn.reportFailure(unreachable());
    vi.advanceTimersByTime(REPROBE_MS);
    http.expectOne(PROBE).error(new ProgressEvent('error'));
    await vi.advanceTimersByTimeAsync(0);
    expect(conn.online()).toBe(false);
    vi.advanceTimersByTime(REPROBE_MS);
    http.expectOne(PROBE).flush(answer);
    await vi.advanceTimersByTimeAsync(0);
    expect(conn.online()).toBe(true);
  });

  it('counts a refusal to the probe as the server being back', async () => {
    conn.reportFailure(unreachable());
    vi.advanceTimersByTime(REPROBE_MS);
    http
      .expectOne(PROBE)
      .flush(
        { success: false, data: null, message: 'x', errors: [], meta: { request_id: 'r' } },
        { status: 500, statusText: 'Server Error' },
      );
    await vi.advanceTimersByTimeAsync(0);
    expect(conn.online()).toBe(true);
  });

  it('does not count an error that is not an answer from the server as the server being back', async () => {
    conn.reportFailure(unreachable());
    vi.spyOn(TestBed.inject(SalesApi), 'currentShift').mockReturnValue(
      throwError(() => new TypeError('boom')),
    );
    vi.advanceTimersByTime(REPROBE_MS);
    await vi.advanceTimersByTimeAsync(0);
    expect(conn.online()).toBe(false);
  });

  it('retries at once on request', async () => {
    conn.reportFailure(unreachable());
    const retry = conn.retryNow();
    http.expectOne(PROBE).flush(answer);
    await retry;
    expect(conn.online()).toBe(true);
  });

  it('sends nothing but the probe while offline: it never queues', () => {
    conn.reportFailure(unreachable());
    vi.advanceTimersByTime(REPROBE_MS - 1);
    // No request of any kind has been issued for later.
    http.expectNone(() => true);
  });

  describe('track', () => {
    it('passes a value through and reports success', () => {
      conn.reportFailure(unreachable());
      let got: number | undefined;
      conn.track(of(7)).subscribe((v) => (got = v));
      expect(got).toBe(7);
      expect(conn.online()).toBe(true);
    });

    it('passes an error through and reports a status 0 failure', () => {
      let err: unknown;
      conn.track(throwError(() => unreachable())).subscribe({ error: (e) => (err = e) });
      expect(err).toBeInstanceOf(ApiError);
      expect(conn.online()).toBe(false);
    });
  });
});
