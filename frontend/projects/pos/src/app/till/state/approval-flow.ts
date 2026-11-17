import { Injectable, inject } from '@angular/core';
import {
  ApiError,
  RequestApprovalRequest,
  SalesApi,
  SalesRefusalCode,
  refusalOf,
} from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { Connectivity } from './connectivity';
import { DialogHost } from './dialog-host';
import { Notifier } from './notifier';

/** What the supervisor is shown about the action they are being asked to approve. */
export interface ApprovalSummary {
  title: string;
  lines?: string[];
}

/** How an approval ended (T-037 AC-65 to AC-71). Only `approved` carries an id. */
export type ApprovalOutcome =
  { kind: 'approved'; approvalId: string } | { kind: 'rejected' } | { kind: 'cancelled' };

/** What the supervisor dialog is opened with (`DialogHost.open('supervisor', data)`). */
export interface SupervisorDialogData {
  approvalId: string;
  summary: ApprovalSummary;
}

/** What the supervisor dialog closes with; `undefined` means the cashier cancelled it. */
export type SupervisorDialogResult = 'approved' | 'rejected';

/** One approval to ask for: what the server binds it to, and what the supervisor reads. */
export interface ApprovalAsk {
  request: RequestApprovalRequest;
  summary: ApprovalSummary;
}

/** How a guarded action ended. `done` carries whatever the action returned. */
export type ApprovalResult<T> =
  { kind: 'done'; value: T } | { kind: 'rejected' } | { kind: 'cancelled' };

/** The refusals that say an approval can no longer be used for the action (AC-70). */
export const STALE_APPROVAL_CODES: readonly SalesRefusalCode[] = [
  'approval_already_used',
  'approval_not_approved',
  'approval_not_pending',
  'approval_mismatch',
];

/** Most times a stale approval restarts the flow before the till stops asking. */
export const MAX_APPROVAL_ATTEMPTS = 3;

/** True when the error says the approval the action named cannot be used (AC-70). */
export function isStaleApproval(err: unknown): err is ApiError {
  if (!(err instanceof ApiError)) {
    return false;
  }
  const code = refusalOf(err);
  return code !== null && STALE_APPROVAL_CODES.includes(code);
}

/**
 * Runs `act` with an approval a supervisor has granted, asking for one first (AC-65 to AC-71).
 * `build` is called afresh for each ask, so the binding always reflects the sale as it is now.
 * Each approval id is handed to `act` once and only in this call: it is never kept anywhere else,
 * and a stale answer from the action (`approval_already_used`, `approval_not_approved`,
 * `approval_not_pending`, `approval_mismatch`) discards it, tells the cashier through `onStale`
 * and starts again at the request, up to {@link MAX_APPROVAL_ATTEMPTS} asks. Any other failure of
 * `act` is thrown to the caller unchanged.
 *
 * Takes the flow as a parameter so that a caller works with any object that has
 * `requestAndDecide`, the Chunk 0 interface (a spec passes a stub).
 */
export async function withApproval<T>(
  flow: Pick<ApprovalFlow, 'requestAndDecide'>,
  build: () => ApprovalAsk,
  act: (approvalId: string) => Promise<T>,
  onStale?: (err: ApiError) => void,
): Promise<ApprovalResult<T>> {
  for (let attempt = 1; attempt <= MAX_APPROVAL_ATTEMPTS; attempt++) {
    const ask = build();
    const outcome = await flow.requestAndDecide(ask.request, ask.summary);
    if (outcome.kind !== 'approved') {
      return outcome;
    }
    try {
      return { kind: 'done', value: await act(outcome.approvalId) };
    } catch (err) {
      if (!isStaleApproval(err)) {
        throw err;
      }
      onStale?.(err);
    }
  }
  return { kind: 'cancelled' };
}

/**
 * Asks a supervisor to approve an action at this till: request the approval, show the
 * supervisor dialog with the summary, decide, and hand back the outcome.
 *
 * FROZEN INTERFACE (Chunk 0): `requestAndDecide()`. Nothing is consumed here: the approval is
 * consumed by the action that names it, so a cancelled dialog leaves the sale exactly as it was
 * (AC-71).
 */
@Injectable()
export class ApprovalFlow {
  private readonly api = inject(SalesApi);
  private readonly connectivity = inject(Connectivity);
  private readonly dialogs = inject(DialogHost);
  private readonly notifier = inject(Notifier);

  async requestAndDecide(
    request: RequestApprovalRequest,
    summary: ApprovalSummary,
  ): Promise<ApprovalOutcome> {
    let approvalId: string;
    try {
      const approval = await firstValueFrom(
        this.connectivity.track(this.api.requestApproval(request)),
      );
      approvalId = approval.id;
    } catch (err) {
      this.notifier.fail(err);
      return { kind: 'cancelled' };
    }
    const data: SupervisorDialogData = { approvalId, summary };
    const result = (await this.dialogs.open('supervisor', data)) as
      SupervisorDialogResult | undefined;
    if (result === 'approved') {
      return { kind: 'approved', approvalId };
    }
    if (result === 'rejected') {
      this.notifier.info('Approval rejected');
      return { kind: 'rejected' };
    }
    return { kind: 'cancelled' };
  }

  /** {@link withApproval} on this flow, telling the cashier when an approval went stale. */
  withApproval<T>(
    build: () => ApprovalAsk,
    act: (approvalId: string) => Promise<T>,
  ): Promise<ApprovalResult<T>> {
    return withApproval(this, build, act, (err) => this.notifier.fail(err));
  }
}
