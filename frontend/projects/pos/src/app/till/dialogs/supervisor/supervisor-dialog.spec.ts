import { Router } from '@angular/router';
import { TestBed } from '@angular/core/testing';
import { ApiError, SalesApi, branchState } from '@flexipos/shared';
import { firstValueFrom } from 'rxjs';
import { ApprovalOutcome, MAX_APPROVAL_ATTEMPTS, withApproval } from '../../state/approval-flow';
import {
  CASHIER,
  NON_APPROVER,
  SUPERVISOR,
  VOID_SUMMARY,
  clickButton,
  codeField,
  flowOf,
  pinField,
  storedText,
  supervisorMessage,
  typeCredentials,
} from '../../testing/approval-helpers';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from '../../testing/till-harness';
import { SUPERVISOR_IDLE_MS } from './supervisor-dialog';

const SALE_ID = '01JMCKS0000000000000000001';
const GIVEN = ['shift_open', 'completed_sale_open_shift'];
const VOID_REQUEST = {
  type: 'void',
  reference_type: 'sale',
  reference_id: SALE_ID,
  payload: null,
} as const;

function approvals(h: TillHarness) {
  return branchState(h.state, HARNESS_TENANT_ID, 'BR001').approvals;
}

/** Starts an approval and lets the supervisor dialog open. */
async function ask(h: TillHarness): Promise<{ outcome: Promise<ApprovalOutcome> }> {
  const outcome = flowOf(h).requestAndDecide({ ...VOID_REQUEST }, VOID_SUMMARY);
  await h.settle();
  return { outcome };
}

describe('the supervisor dialog and the approval flow (AC-65 to AC-71)', () => {
  afterEach(() => vi.useRealTimers());

  it('shows what is being approved, in a dialog that focuses the code field (AC-65)', async () => {
    const h = await setupTill({ given: GIVEN });
    void (await ask(h));
    expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Supervisor approval');
    expect(h.text('approval-summary')).toContain('Void sale INV-000001');
    expect(h.text('approval-summary')).toContain('500.00 LKR');
    expect(document.activeElement).toBe(codeField(h));
    expect(pinField(h).type).toBe('password');
    expect(approvals(h)[0]?.data).toMatchObject({
      type: 'void',
      reference_type: 'sale',
      reference_id: SALE_ID,
      status: 'pending',
    });
  });

  it('approves with the supervisor PIN and hands back the approval id (AC-66)', async () => {
    const h = await setupTill({ given: GIVEN });
    const { outcome } = await ask(h);
    typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
    clickButton(h, 'Approve');
    await h.settle();
    const result = await outcome;
    const stored = approvals(h)[0];
    expect(result).toEqual({ kind: 'approved', approvalId: stored?.data.id });
    expect(stored?.data.status).toBe('approved');
    // Deciding consumes nothing: only the action that names the approval does.
    expect(stored?.data.consumed_at).toBeNull();
    expect(h.query('[role="dialog"]')).toBeNull();
  });

  it('submits on Enter in the PIN field', async () => {
    const h = await setupTill({ given: GIVEN });
    const { outcome } = await ask(h);
    typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
    pinField(h).form?.requestSubmit();
    await h.settle();
    expect((await outcome).kind).toBe('approved');
  });

  it('keeps the dialog open on a wrong PIN, clears the field and says so without naming the cause (AC-67)', async () => {
    const h = await setupTill({ given: GIVEN });
    const { outcome } = await ask(h);
    typeCredentials(h, SUPERVISOR.code, '0000');
    clickButton(h, 'Approve');
    await h.settle();
    expect(h.query('[role="dialog"]')).not.toBeNull();
    expect(supervisorMessage(h)).toBe('Code or PIN not recognised');
    expect(pinField(h).value).toBe('');
    expect(codeField(h).value).toBe(SUPERVISOR.code);
    expect(document.activeElement).toBe(pinField(h));
    // An unknown code reads the same.
    typeCredentials(h, 'NOBODY', SUPERVISOR.pin);
    clickButton(h, 'Approve');
    await h.settle();
    expect(supervisorMessage(h)).toBe('Code or PIN not recognised');
    // And another attempt works: the till imposes no lockout of its own.
    typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
    clickButton(h, 'Approve');
    await h.settle();
    expect((await outcome).kind).toBe('approved');
  });

  it('refuses the cashier approving their own sale, naming which refusal it is (AC-68)', async () => {
    const h = await setupTill({ given: GIVEN });
    const { outcome } = await ask(h);
    typeCredentials(h, CASHIER.code, CASHIER.pin);
    clickButton(h, 'Approve');
    await h.settle();
    expect(supervisorMessage(h)).toContain('This person cannot approve this action');
    expect(supervisorMessage(h)).toContain('nobody may approve their own work');
    expect(supervisorMessage(h)).toContain('Hand over to another supervisor');
    expect(h.query('[role="dialog"]')).not.toBeNull();
    expect(approvals(h)[0]?.data.status).toBe('pending');

    // Handing over: the code is cleared for the next person, in the same dialog.
    expect(codeField(h).value).toBe('');
    expect(document.activeElement).toBe(codeField(h));
    typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
    clickButton(h, 'Approve');
    await h.settle();
    expect((await outcome).kind).toBe('approved');
  });

  it('says a supervisor without the permission is not authorised (AC-68)', async () => {
    const h = await setupTill({ given: GIVEN });
    void (await ask(h));
    typeCredentials(h, NON_APPROVER.code, NON_APPROVER.pin);
    clickButton(h, 'Approve');
    await h.settle();
    expect(supervisorMessage(h)).toContain('This person cannot approve this action');
    expect(supervisorMessage(h)).toContain('not authorised to approve');
    expect(h.query('[role="dialog"]')).not.toBeNull();
  });

  it('records a rejection and returns to the sale with "Approval rejected" (AC-69)', async () => {
    const h = await setupTill({ given: GIVEN });
    const { outcome } = await ask(h);
    typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
    clickButton(h, 'Reject');
    await h.settle();
    expect(await outcome).toEqual({ kind: 'rejected' });
    expect(approvals(h)[0]?.data.status).toBe('rejected');
    expect(h.text('message-bar')).toContain('Approval rejected');
    expect(h.query('[role="dialog"]')).toBeNull();
  });

  it('consumes nothing when the cashier cancels with Esc or Cancel (AC-71)', async () => {
    const h = await setupTill({ given: GIVEN });
    const { outcome: first } = await ask(h);
    typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
    h.press('Escape');
    await h.settle();
    expect(await first).toEqual({ kind: 'cancelled' });
    expect(approvals(h)[0]?.data).toMatchObject({ status: 'pending', consumed_at: null });
    expect(h.query('[role="dialog"]')).toBeNull();

    const { outcome: second } = await ask(h);
    clickButton(h, 'Cancel');
    await h.settle();
    expect(await second).toEqual({ kind: 'cancelled' });
    expect(approvals(h)[1]?.data).toMatchObject({ status: 'pending', consumed_at: null });
  });

  it('asks for the code and PIN before calling anything', async () => {
    const h = await setupTill({ given: GIVEN });
    void (await ask(h));
    typeCredentials(h, SUPERVISOR.code, '');
    clickButton(h, 'Approve');
    await h.settle();
    expect(supervisorMessage(h)).toBe('Enter the supervisor code and PIN');
    expect(approvals(h)[0]?.data.status).toBe('pending');
  });

  it('tells the cashier when the approval cannot be requested, and approves nothing', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const outcome = flowOf(h).requestAndDecide(
      { ...VOID_REQUEST, reference_id: '01JMCKS0000000000000000009' },
      VOID_SUMMARY,
    );
    await h.settle();
    expect(await outcome).toEqual({ kind: 'cancelled' });
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(h.text('message-bar')).toContain('That record was not found');
  });

  describe('the PIN is transient (AC-66)', () => {
    it('is cleared on submit before the answer comes back', async () => {
      const h = await setupTill({ given: GIVEN, latencyMs: 50 });
      void (await ask(h));
      typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
      clickButton(h, 'Approve');
      expect(pinField(h).value).toBe('');
    });

    it('is cleared by a cancel, which also tears the dialog down', async () => {
      const h = await setupTill({ given: GIVEN });
      void (await ask(h));
      const field = pinField(h);
      typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
      expect(field.value).toBe(SUPERVISOR.pin);
      clickButton(h, 'Cancel');
      await h.settle();
      expect(field.value).toBe('');
    });

    it('is cleared after 60 seconds idle, and activity restarts the clock', async () => {
      const h = await setupTill({ given: GIVEN });
      vi.useFakeTimers();
      void (await ask(h));
      typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
      vi.advanceTimersByTime(SUPERVISOR_IDLE_MS - 1000);
      // A key press is activity: the clock starts again.
      codeField(h).dispatchEvent(new KeyboardEvent('keydown', { key: 'a', bubbles: true }));
      vi.advanceTimersByTime(SUPERVISOR_IDLE_MS - 1);
      expect(pinField(h).value).toBe(SUPERVISOR.pin);
      vi.advanceTimersByTime(1);
      expect(pinField(h).value).toBe('');
      await h.settle();
      expect(supervisorMessage(h)).toContain('cleared after 60 seconds');
      expect(h.query('[role="dialog"]')).not.toBeNull();
    });

    it('is never stored, logged or put in the address, on any path', async () => {
      const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((m) =>
        vi.spyOn(console, m).mockImplementation(() => undefined),
      );
      const paths: [string, string, string][] = [
        ['approve', SUPERVISOR.code, SUPERVISOR.pin],
        ['wrong', SUPERVISOR.code, '0000'],
        ['self', CASHIER.code, CASHIER.pin],
        ['reject', SUPERVISOR.code, SUPERVISOR.pin],
      ];
      for (const [name, code, pin] of paths) {
        const h = await setupTill({ given: GIVEN });
        const { outcome } = await ask(h);
        typeCredentials(h, code, pin);
        clickButton(h, name === 'reject' ? 'Reject' : 'Approve');
        await h.settle();
        if (name === 'wrong' || name === 'self') {
          clickButton(h, 'Cancel');
          await h.settle();
        }
        await outcome;
        const approvalId = approvals(h)[0]?.data.id ?? 'no-approval';
        for (const secret of [pin, approvalId]) {
          expect(storedText(), `${name} stored ${secret}`).not.toContain(secret);
          expect(TestBed.inject(Router).url, `${name} url`).not.toContain(secret);
          expect(h.host.innerHTML, `${name} markup`).not.toContain(`value="${secret}"`);
        }
        for (const spy of spies) {
          expect(JSON.stringify(spy.mock.calls), `${name} console`).not.toContain(pin);
        }
      }
      spies.forEach((spy) => spy.mockRestore());
    });
  });

  describe('withApproval (AC-65, AC-70)', () => {
    const build = () => ({ request: { ...VOID_REQUEST }, summary: VOID_SUMMARY });

    it('runs the action once with the approval id, which then cannot be used again', async () => {
      const h = await setupTill({ given: GIVEN });
      const api = h.fixture.debugElement.injector.get(SalesApi);
      const seen: string[] = [];
      const done = flowOf(h).withApproval(build, async (approvalId) => {
        seen.push(approvalId);
        return firstValueFrom(
          api.voidSale(SALE_ID, { reason: 'Customer returned it', approval_id: approvalId }),
        );
      });
      await h.settle();
      typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
      clickButton(h, 'Approve');
      await h.settle();
      const result = await done;
      expect(result.kind).toBe('done');
      expect(result.kind === 'done' && result.value.status).toBe('voided');
      expect(seen).toEqual([approvals(h)[0]?.data.id]);
      expect(approvals(h)[0]?.data.consumed_at).not.toBeNull();
    });

    it('does not run the action when the supervisor rejects or the cashier cancels', async () => {
      const h = await setupTill({ given: GIVEN });
      const act = vi.fn(() => Promise.resolve('acted'));
      const rejected = flowOf(h).withApproval(build, act);
      await h.settle();
      typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
      clickButton(h, 'Reject');
      await h.settle();
      expect(await rejected).toEqual({ kind: 'rejected' });
      const cancelled = flowOf(h).withApproval(build, act);
      await h.settle();
      h.press('Escape');
      await h.settle();
      expect(await cancelled).toEqual({ kind: 'cancelled' });
      expect(act).not.toHaveBeenCalled();
    });

    it('discards a stale approval, says a new one is needed and asks again (AC-70)', async () => {
      const h = await setupTill({ given: GIVEN });
      const seen: string[] = [];
      const done = flowOf(h).withApproval(build, (approvalId) => {
        seen.push(approvalId);
        if (seen.length === 1) {
          return Promise.reject(
            new ApiError(403, 'used', [
              { code: 'sales.approval_already_used', field: null, detail: '' },
            ]),
          );
        }
        return Promise.resolve('acted');
      });
      await h.settle();
      typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
      clickButton(h, 'Approve');
      await h.settle();

      // The first approval was thrown away; a second one is being asked for.
      expect(h.text('message-bar')).toContain('a new approval is needed');
      expect(approvals(h).length).toBe(2);
      expect(h.query('[role="dialog"]')).not.toBeNull();
      typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
      clickButton(h, 'Approve');
      await h.settle();

      expect(await done).toEqual({ kind: 'done', value: 'acted' });
      expect(seen.length).toBe(2);
      expect(seen[0]).not.toBe(seen[1]);
      expect(seen[0]).toBe(approvals(h)[0]?.data.id);
      expect(seen[1]).toBe(approvals(h)[1]?.data.id);
    });

    it('stops asking after a few stale answers in a row', async () => {
      const flow = {
        requestAndDecide: vi.fn(() =>
          Promise.resolve<ApprovalOutcome>({ kind: 'approved', approvalId: 'x' }),
        ),
      };
      const stale = vi.fn();
      const act = vi.fn(() =>
        Promise.reject(
          new ApiError(403, 'mismatch', [
            { code: 'sales.approval_mismatch', field: null, detail: '' },
          ]),
        ),
      );
      const result = await withApproval(flow, build, act, stale);
      expect(result).toEqual({ kind: 'cancelled' });
      expect(act).toHaveBeenCalledTimes(MAX_APPROVAL_ATTEMPTS);
      expect(stale).toHaveBeenCalledTimes(MAX_APPROVAL_ATTEMPTS);
    });

    it('throws any other failure of the action to the caller, after one attempt', async () => {
      const flow = {
        requestAndDecide: vi.fn(() =>
          Promise.resolve<ApprovalOutcome>({ kind: 'approved', approvalId: 'x' }),
        ),
      };
      const boom = new ApiError(403, 'closed', [
        { code: 'sales.shift_closed', field: null, detail: '' },
      ]);
      await expect(withApproval(flow, build, () => Promise.reject(boom))).rejects.toBe(boom);
      expect(flow.requestAndDecide).toHaveBeenCalledTimes(1);
    });
  });
});
