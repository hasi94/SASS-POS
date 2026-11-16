import { signal } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiErrorView, describeApiError } from './describe-api-error';
import { SubmitState } from './submit-state';

/**
 * The delete interaction of one list (T-036 AC-19, AC-20): ask, then confirm or cancel.
 * `confirm()` sends the delete once (a second click while it is in flight does nothing) and
 * reloads the list on success. A refusal keeps the row, closes the question and shows the
 * server's message, which names the blocking reference.
 */
export class DeleteFlow<T> {
  private readonly pendingRow = signal<T | null>(null);
  private readonly failure = signal<ApiErrorView | null>(null);
  private readonly submit = new SubmitState();

  /** The row being asked about, or null when no question is open. */
  readonly pending = this.pendingRow.asReadonly();
  /** The last refusal, until the next question is opened. */
  readonly error = this.failure.asReadonly();
  readonly busy = this.submit.busy;

  constructor(
    private readonly remove: (row: T) => Observable<unknown>,
    private readonly reload: () => void,
  ) {}

  ask(row: T): void {
    this.failure.set(null);
    this.pendingRow.set(row);
  }

  confirm(): void {
    const row = this.pendingRow();
    if (row === null) {
      return;
    }
    this.submit.run(this.remove(row), {
      next: () => {
        this.pendingRow.set(null);
        this.reload();
      },
      error: (err) => {
        this.pendingRow.set(null);
        this.failure.set(describeApiError(err));
      },
    });
  }

  cancel(): void {
    this.pendingRow.set(null);
  }
}
