import { DestroyRef, Injectable, inject, signal } from '@angular/core';
import { TillMessage, describeError } from '../errors/refusal-catalogue';

/** A refusal is never taken off the screen before it has been shown this long (AC-84). */
export const MIN_SHOWN_MS = 4000;
/** A refusal nobody has dismissed or replaced goes after this long. */
export const REFUSAL_LINGER_MS = 10000;
/** Most refusals kept waiting behind the one on screen. */
const MAX_WAITING = 10;

/**
 * What the cashier is told, and for how long (T-037 AC-27, AC-84). One message is shown at a
 * time. A refusal stays at least {@link MIN_SHOWN_MS} even when a newer message arrives (the newer
 * one waits its turn), and `Esc` (via {@link dismiss}) removes it at any time. Refusals wait in
 * the order they came and are always shown before any waiting note. A plain note ("Added Rice")
 * is superseded rather than queued, so a run of scans cannot build a backlog, and a refusal
 * arriving while a note is on screen replaces it at once: a plain note can be dropped before
 * {@link MIN_SHOWN_MS} (the minimum protects a refusal only).
 *
 * At most {@link MAX_WAITING} messages wait; on overflow the oldest waiting one is dropped, even
 * if it is a refusal.
 *
 * Each message is also written to a live region: refusals to the assertive one, the rest to the
 * polite one, so a screen reader announces it.
 */
@Injectable()
export class Notifier {
  private readonly shown = signal<TillMessage | null>(null);
  private readonly politeText = signal('');
  private readonly assertiveText = signal('');
  private waiting: TillMessage[] = [];
  private timer: ReturnType<typeof setTimeout> | null = null;

  readonly current = this.shown.asReadonly();
  /** Text for the polite live region. */
  readonly polite = this.politeText.asReadonly();
  /** Text for the assertive (alert) live region. */
  readonly assertive = this.assertiveText.asReadonly();

  constructor() {
    inject(DestroyRef).onDestroy(() => this.stopTimer());
  }

  notify(message: TillMessage): void {
    if (this.shown() === null) {
      this.show(message);
      return;
    }
    if (message.kind === 'refusal' && this.shown()?.kind !== 'refusal') {
      // A plain note ("Added Rice") never holds a refusal back: the refusal replaces it now and
      // the notes behind it are dropped (AC-84 only protects a refusal on screen). No refusal can
      // be waiting here: a refusal waiting is always shown before any note.
      this.stopTimer();
      this.waiting = this.waiting.filter((m) => m.kind === 'refusal');
      this.show(message);
      return;
    }
    if (message.kind !== 'refusal') {
      this.waiting = this.waiting.filter((m) => m.kind === 'refusal');
    }
    this.waiting.push(message);
    if (this.waiting.length > MAX_WAITING) {
      this.waiting.shift();
    }
  }

  info(text: string): void {
    this.notify({ kind: 'info', text });
  }

  success(text: string): void {
    this.notify({ kind: 'success', text });
  }

  /** Shows whatever a failed call threw, through the refusal catalogue. */
  fail(err: unknown): void {
    this.notify(describeError(err));
  }

  /** Removes the message on screen now (the `Esc` key), and shows the next one waiting. */
  dismiss(): void {
    if (this.shown() === null) {
      return;
    }
    this.stopTimer();
    this.advance();
  }

  private show(message: TillMessage): void {
    this.shown.set(message);
    const line = message.detail ? `${message.text}. ${message.detail}` : message.text;
    if (message.kind === 'refusal') {
      this.assertiveText.set(line);
    } else {
      this.politeText.set(line);
    }
    this.timer = setTimeout(() => this.afterMinimum(message), MIN_SHOWN_MS);
  }

  private afterMinimum(message: TillMessage): void {
    this.timer = null;
    if (this.waiting.length > 0) {
      this.advance();
    } else if (message.kind === 'refusal') {
      this.timer = setTimeout(() => this.advance(), REFUSAL_LINGER_MS - MIN_SHOWN_MS);
    } else {
      this.advance();
    }
  }

  private advance(): void {
    this.timer = null;
    // The oldest refusal waiting goes first, then the notes: a refusal is never reordered.
    const at = this.waiting.findIndex((m) => m.kind === 'refusal');
    const [next] = this.waiting.splice(at === -1 ? 0 : at, 1);
    if (next) {
      this.show(next);
    } else {
      this.shown.set(null);
    }
  }

  private stopTimer(): void {
    if (this.timer !== null) {
      clearTimeout(this.timer);
      this.timer = null;
    }
  }
}
