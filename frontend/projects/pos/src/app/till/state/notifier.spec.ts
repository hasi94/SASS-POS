import { TestBed } from '@angular/core/testing';
import { ApiError } from '@flexipos/shared';
import { MIN_SHOWN_MS, Notifier, REFUSAL_LINGER_MS } from './notifier';

describe('Notifier (AC-27, AC-84)', () => {
  let notifier: Notifier;

  beforeEach(() => {
    vi.useFakeTimers();
    TestBed.configureTestingModule({ providers: [Notifier] });
    notifier = TestBed.inject(Notifier);
  });

  afterEach(() => vi.useRealTimers());

  const refusal = (text: string) => ({ kind: 'refusal' as const, text });

  it('shows a message at once and takes it off after the minimum', () => {
    notifier.info('Added Rice');
    expect(notifier.current()?.text).toBe('Added Rice');
    vi.advanceTimersByTime(MIN_SHOWN_MS - 1);
    expect(notifier.current()?.text).toBe('Added Rice');
    vi.advanceTimersByTime(1);
    expect(notifier.current()).toBeNull();
  });

  it('still shows a refusal at 3.9 seconds when a newer message arrives, which queues behind it', () => {
    notifier.notify(refusal('Code not found: 123'));
    vi.advanceTimersByTime(3900);
    notifier.success('Added Rice');
    expect(notifier.current()?.text).toBe('Code not found: 123');
    vi.advanceTimersByTime(99);
    expect(notifier.current()?.text).toBe('Code not found: 123');
    vi.advanceTimersByTime(1);
    expect(notifier.current()?.text).toBe('Added Rice');
  });

  it('keeps a refusal nobody replaced or dismissed until the linger time', () => {
    notifier.notify(refusal('No price for this product'));
    vi.advanceTimersByTime(MIN_SHOWN_MS);
    // Nothing is waiting: it lingers until it is dismissed or the linger time is up.
    expect(notifier.current()?.text).toBe('No price for this product');
    vi.advanceTimersByTime(REFUSAL_LINGER_MS - MIN_SHOWN_MS);
    expect(notifier.current()).toBeNull();
  });

  it('dismisses with Esc at any time, and shows the next waiting message', () => {
    notifier.notify(refusal('First'));
    notifier.notify(refusal('Second'));
    vi.advanceTimersByTime(500);
    notifier.dismiss();
    expect(notifier.current()?.text).toBe('Second');
    notifier.dismiss();
    expect(notifier.current()).toBeNull();
    notifier.dismiss();
    expect(notifier.current()).toBeNull();
  });

  it('gives the next message its full minimum after a dismissal', () => {
    notifier.notify(refusal('First'));
    notifier.notify(refusal('Second'));
    notifier.dismiss();
    vi.advanceTimersByTime(MIN_SHOWN_MS - 1);
    expect(notifier.current()?.text).toBe('Second');
  });

  it('keeps every refusal waiting, in order', () => {
    notifier.notify(refusal('One'));
    notifier.notify(refusal('Two'));
    notifier.notify(refusal('Three'));
    const seen: string[] = [];
    for (let i = 0; i < 3; i++) {
      seen.push(notifier.current()?.text ?? '');
      vi.advanceTimersByTime(MIN_SHOWN_MS);
    }
    expect(seen).toEqual(['One', 'Two', 'Three']);
  });

  it('keeps only the latest plain message waiting, so a run of scans builds no backlog', () => {
    notifier.notify(refusal('Busy'));
    for (let i = 1; i <= 50; i++) {
      notifier.success(`Added item ${i}`);
    }
    vi.advanceTimersByTime(MIN_SHOWN_MS);
    expect(notifier.current()?.text).toBe('Added item 50');
    vi.advanceTimersByTime(MIN_SHOWN_MS);
    expect(notifier.current()).toBeNull();
  });

  it('writes refusals to the assertive region and the rest to the polite one', () => {
    notifier.success('Added Rice');
    expect(notifier.polite()).toBe('Added Rice');
    expect(notifier.assertive()).toBe('');
    vi.advanceTimersByTime(MIN_SHOWN_MS);
    notifier.fail(new ApiError(500, 'x', [], 'req-1'));
    expect(notifier.assertive()).toBe('Something went wrong. Status 500, Request req-1');
  });

  it('describes a failed call through the catalogue', () => {
    notifier.fail(
      new ApiError(403, 'x', [{ code: 'sales.no_open_shift', field: null, detail: '' }]),
    );
    expect(notifier.current()?.text).toBe('Open a shift first');
    expect(notifier.current()?.next?.kind).toBe('open-shift');
  });
});
