import { inject } from '@angular/core';
import {
  BARCODE_RESOLVER,
  BarcodeResolver,
  LINE_PRICER,
  LinePricer,
  SALES_MOCK_DEMO,
  ServerBarcodeResolver,
  ServerLinePricer,
} from '@flexipos/shared';
import { Observable, finalize } from 'rxjs';
import { allById, byId, serverSales, settleFully, storeOf } from '../testing/sale-screen-helpers';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from '../testing/till-harness';
import { SaleStore } from './sale-store';
import { ScanQueue } from './scan-queue';

/** What the till asked the two ports to do, in order, with how many calls were in flight. */
class Recorder {
  readonly events: string[] = [];
  inFlight = 0;
  mostInFlight = 0;

  track<T>(label: string, source: Observable<T>): Observable<T> {
    this.events.push(`${label} start`);
    this.inFlight += 1;
    this.mostInFlight = Math.max(this.mostInFlight, this.inFlight);
    return source.pipe(
      finalize(() => {
        this.inFlight -= 1;
        this.events.push(`${label} end`);
      }),
    );
  }
}

async function setupSlow(recorder: Recorder): Promise<TillHarness> {
  const h = await setupTill({
    given: ['shift_open'],
    latencyMs: 50,
    providers: [
      {
        provide: BARCODE_RESOLVER,
        useFactory: (): BarcodeResolver => {
          const real = inject(ServerBarcodeResolver);
          return { resolve: (code) => recorder.track(`resolve ${code}`, real.resolve(code)) };
        },
      },
      {
        provide: LINE_PRICER,
        useFactory: (): LinePricer => {
          const real = inject(ServerLinePricer);
          return {
            addLine: (saleId, request) =>
              recorder.track(`add ${request.product_id}`, real.addLine(saleId, request)),
            updateLine: (saleId, lineId, request) => real.updateLine(saleId, lineId, request),
            quoteRefund: (request) => real.quoteRefund(request),
          };
        },
      },
    ],
  });
  // The shift answers after the mock's latency; wait for it on the real clock.
  await new Promise((resolve) => setTimeout(resolve, 120));
  await h.settle();
  return h;
}

describe('ScanQueue (AC-28, D-10)', () => {
  afterEach(() => vi.useRealTimers());

  it('takes three codes typed within 50 ms: pending rows at once, calls in arrival order one at a time, a refusal does not drop the rest', async () => {
    const recorder = new Recorder();
    const h = await setupSlow(recorder);
    expect(storeOf(h, SaleStore).lineCount()).toBe(0);
    vi.useFakeTimers();

    const rice = SALES_MOCK_DEMO.barcode;
    const unknown = '0000999';
    const soap = '4790000000042';
    for (const code of [rice, unknown, soap]) {
      h.typeAndEnter('[data-testid="scan-field"]', code);
      await vi.advanceTimersByTimeAsync(15);
    }
    // 45 ms in: nothing has been answered yet, and all three are on screen as "adding".
    h.fixture.detectChanges();
    const pending = allById(h, 'pending-scan').map((row) => row.textContent?.trim());
    expect(pending).toEqual([`Adding ${rice}`, `Adding ${unknown}`, `Adding ${soap}`]);
    expect(storeOf(h, SaleStore).lineCount()).toBe(0);
    expect(recorder.events).toEqual([`resolve ${rice} start`]);

    await vi.advanceTimersByTimeAsync(600);
    await settleFully(h);

    // One call at a time, strictly in arrival order, and the unknown code did not stop the soap.
    expect(recorder.mostInFlight).toBe(1);
    const resolves = recorder.events.filter((e) => e.startsWith('resolve') && e.endsWith('start'));
    expect(resolves).toEqual([
      `resolve ${rice} start`,
      `resolve ${unknown} start`,
      `resolve ${soap} start`,
    ]);
    const startsAndEnds = recorder.events;
    for (let i = 1; i < startsAndEnds.length; i += 2) {
      expect(startsAndEnds[i]?.endsWith('end'), startsAndEnds.join(' | ')).toBe(true);
    }
    expect(allById(h, 'pending-scan')).toHaveLength(0);

    const sale = serverSales(h, HARNESS_TENANT_ID, 'BR001');
    expect(sale).toHaveLength(1);
    expect(sale[0]?.lines.map((l) => l.product_name)).toEqual(['Rice 1kg', 'Laundry Soap']);
    expect(
      storeOf(h, SaleStore)
        .lines()
        .map((l) => l.product_name),
    ).toEqual(['Rice 1kg', 'Laundry Soap']);
    // The notifier shows one message at a time: the refusal waits for "Added Rice 1kg" to have
    // been on screen for its 4 seconds, then takes its turn.
    await vi.advanceTimersByTimeAsync(4000);
    await settleFully(h);
    expect(h.text('assertive-region')).toBe(`Code not found: ${unknown}`);
    expect(byId(h, 'message-bar').textContent).toContain('Code not found');
  });

  it('shows the pending row within the same turn, independent of the network', async () => {
    const recorder = new Recorder();
    const h = await setupSlow(recorder);
    vi.useFakeTimers();
    const queue = storeOf(h, ScanQueue);
    queue.submit(SALES_MOCK_DEMO.barcode);
    expect(queue.pending().map((p) => p.code)).toEqual([SALES_MOCK_DEMO.barcode]);
    await vi.advanceTimersByTimeAsync(600);
    expect(queue.pending()).toEqual([]);
  });

  it('shows the same code scanned twice as two rows and adds it twice', async () => {
    const recorder = new Recorder();
    const h = await setupSlow(recorder);
    vi.useFakeTimers();
    const queue = storeOf(h, ScanQueue);
    queue.submit(SALES_MOCK_DEMO.barcode);
    queue.submit(SALES_MOCK_DEMO.barcode);
    expect(queue.pending()).toHaveLength(2);
    expect(new Set(queue.pending().map((p) => p.key)).size).toBe(2);
    await vi.advanceTimersByTimeAsync(800);
    await settleFully(h);
    expect(storeOf(h, SaleStore).lineCount()).toBe(2);
  });
});
