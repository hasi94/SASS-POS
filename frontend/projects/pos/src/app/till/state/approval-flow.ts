import { Injectable } from '@angular/core';
import { RequestApprovalRequest } from '@flexipos/shared';

/** What the supervisor is shown about the action they are being asked to approve. */
export interface ApprovalSummary {
  title: string;
  lines?: string[];
}

/** How an approval ended (T-037 AC-65 to AC-71). Only `approved` carries an id. */
export type ApprovalOutcome =
  { kind: 'approved'; approvalId: string } | { kind: 'rejected' } | { kind: 'cancelled' };

/**
 * Asks a supervisor to approve an action at this till: request the approval, show the
 * supervisor dialog with the summary, decide, and hand back the outcome.
 *
 * FROZEN INTERFACE (Chunk 0): `requestAndDecide()`. Step A2 replaces the body (and adds the
 * `withApproval` helper beside it); every caller written before then, with a stub of this class,
 * keeps working. Until A2 the body answers `cancelled`: nothing is requested, nothing consumed.
 */
@Injectable()
export class ApprovalFlow {
  requestAndDecide(
    _request: RequestApprovalRequest,
    _summary: ApprovalSummary,
  ): Promise<ApprovalOutcome> {
    return Promise.resolve({ kind: 'cancelled' });
  }
}
