import {
  HttpClient,
  HttpErrorResponse,
  provideHttpClient,
  withInterceptors,
} from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { authInterceptor } from '../auth/auth.interceptor';
import { SESSION_STORAGE_KEY } from '../auth/session-storage';
import { MOCK_USER } from '../mock/mock-backend';
import { SALES_CALLS, callPath } from '../sales/contract/calls';
import {
  FIXTURE_BRANCH_ID,
  FIXTURE_TENANT_ID,
  seedSession,
} from '../sales/contract/testing/replay';
import { ApprovalBinding, checkApproval } from './handlers/approvals';
import { SALES_MOCK_LATENCY_MS, SALES_MOCK_STATE, MockState, branchState } from './mock-state';
import { seedId } from './mock-seed';
import { SCENARIO_REFUND_ID, SCENARIO_REFUND_TENDER_ID, applyGiven } from './scenarios';
import { salesMockInterceptor } from './sales-mock.interceptor';

/**
 * The Sales rules the till depends on that no single fixture pins (T-037 AC-5): the sale state
 * machine walked end to end, one open shift per terminal, single-use approvals and their branch
 * binding, tender arithmetic, store credit, refund idempotency and the refund money cap. The
 * fixtures pin each answer; this pins the rules between answers.
 */

interface Answer {
  status: number;
  body: { data: unknown; errors: { code: string; field: string | null; detail: string }[] };
}

interface World {
  state: MockState;
  send(method: 'GET' | 'POST' | 'PUT' | 'DELETE', path: string, body?: unknown): Promise<Answer>;
}

const S = (n: number): string => seedId('S', n);
const Q = (n: number): string => seedId('Q', n);
const Y = (n: number): string => seedId('Y', n);
const Z = (n: number): string => seedId('Z', n);
const A = (n: number): string => seedId('A', n);
const M = (n: number): string => seedId('M', n);
const K = (n: number): string => seedId('K', n);
const P = (n: number): string => seedId('P', n);
const N = (n: number): string => seedId('N', n);

function world(given: string[]): World {
  sessionStorage.clear();
  seedSession();
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    providers: [
      provideRouter([]),
      provideHttpClient(withInterceptors([authInterceptor, salesMockInterceptor])),
      { provide: SESSION_STORAGE_KEY, useValue: 'flexipos.session' },
      { provide: SALES_MOCK_LATENCY_MS, useValue: 0 },
    ],
  });
  const state = TestBed.inject(SALES_MOCK_STATE);
  applyGiven(state, FIXTURE_TENANT_ID, FIXTURE_BRANCH_ID, MOCK_USER.name, given);
  const http = TestBed.inject(HttpClient);
  return {
    state,
    send: async (method, path, body) => {
      try {
        const response = await firstValueFrom(
          http.request(method, `/api/v1/${path}`, { body, observe: 'response' }),
        );
        return { status: response.status, body: response.body as Answer['body'] };
      } catch (err) {
        if (err instanceof HttpErrorResponse) {
          return { status: err.status, body: err.error as Answer['body'] };
        }
        throw err;
      }
    },
  };
}

interface SaleData {
  id: string;
  status: string;
  held_from_status: string | null;
  paid_total: string;
  balance_due: string;
  total: string;
  number: string | null;
  tenders: { id: string; amount: string; change_given: string }[];
  lines: {
    id: string;
    refundable_quantity: string;
    refunded_quantity: string;
    line_total: string;
  }[];
}

function sale(answer: Answer): SaleData {
  return answer.body.data as SaleData;
}

function code(answer: Answer): string | undefined {
  return answer.body.errors[0]?.code;
}

/** Asks for an approval, has the supervisor approve it, and returns its id. */
async function approve(w: World, body: Record<string, unknown>): Promise<string> {
  const asked = await w.send('POST', 'approvals', body);
  const id = (asked.body.data as { id: string }).id;
  const decided = await w.send('POST', `approvals/${id}/decide`, {
    decision: 'approve',
    method: 'pin_local',
    employee_code: 'S100',
    pin: '2468',
  });
  expect(decided.status).toBe(200);
  return id;
}

const cashTender = (id: string, tendered: string) => ({
  id,
  payment_method_id: M(1),
  tendered,
});

describe('sales mock: scenario ids written as literals', () => {
  it('match what seedId makes, since scenarios.ts holds no top-level call', () => {
    expect(SCENARIO_REFUND_ID).toBe(seedId('Z', 1));
    expect(SCENARIO_REFUND_TENDER_ID).toBe(seedId('Y', 1));
  });
});

describe('sales mock: the sale state machine', () => {
  it('walks draft, pending payment, held, recalled, completed, then refused every other move', async () => {
    const w = world(['shift_open']);
    const created = await w.send('POST', 'sales', {});
    expect(sale(created).status).toBe('draft');
    const id = sale(created).id;
    const lined = await w.send('POST', `sales/${id}/lines`, {
      product_id: P(1),
      unit_id: N(1),
      quantity: '2.0000',
    });
    expect(sale(lined).total).toBe('500.0000');

    const part = await w.send('POST', `sales/${id}/tenders`, cashTender(Y(1), '200.0000'));
    expect(part.status).toBe(201);
    const afterPart = await w.send('GET', `sales/${id}`);
    expect(sale(afterPart).status).toBe('pending_payment');
    expect(sale(afterPart).balance_due).toBe('300.0000');

    // Lines are frozen once payment has started.
    const edit = await w.send('POST', `sales/${id}/lines`, {
      product_id: P(1),
      unit_id: N(1),
      quantity: '1.0000',
    });
    expect(code(edit)).toBe('sales.sale_not_editable');

    const held = await w.send('POST', `sales/${id}/hold`, {});
    expect(sale(held).status).toBe('held');
    expect(sale(held).held_from_status).toBe('pending_payment');
    expect(code(await w.send('POST', `sales/${id}/hold`, {}))).toBe('sales.invalid_transition');
    const recalled = await w.send('POST', `sales/${id}/recall`, {});
    expect(sale(recalled).status).toBe('pending_payment');
    expect(sale(recalled).held_from_status).toBeNull();

    // Not yet paid in full.
    expect(code(await w.send('POST', `sales/${id}/complete`, {}))).toBe('sales.balance_due');
    await w.send('POST', `sales/${id}/tenders`, cashTender(Y(2), '300.0000'));
    const completed = await w.send('POST', `sales/${id}/complete`, {});
    expect(sale(completed).status).toBe('completed');
    expect(sale(completed).number).toBe('INV-000001');

    // A completed sale takes no hold, recall, tender or second completion.
    for (const path of ['hold', 'recall', 'complete']) {
      expect(code(await w.send('POST', `sales/${id}/${path}`, {}))).toBe(
        'sales.invalid_transition',
      );
    }
    expect(code(await w.send('POST', `sales/${id}/tenders`, cashTender(Y(3), '1.0000')))).toBe(
      'sales.invalid_transition',
    );
  });

  it('names the status in an invalid transition', async () => {
    const w = world(['shift_open', 'completed_sale_open_shift']);
    const answer = await w.send('POST', `sales/${S(1)}/hold`, {});
    expect(answer.body.errors[0]).toMatchObject({ field: 'status', detail: 'completed' });
  });

  it('refunds a completed sale in two steps and moves it to partially_refunded then refunded', async () => {
    const w = world(['shift_open', 'completed_sale_open_shift']);
    const first = await approve(w, {
      type: 'refund',
      reference_type: 'sale',
      reference_id: S(1),
      payload: null,
    });
    const one = await w.send('POST', 'refunds', {
      id: Z(1),
      reference_sale_id: S(1),
      approval_id: first,
      lines: [{ refund_of_item_id: Q(1), quantity: '1.0000' }],
      tenders: [{ id: Y(1), payment_method_id: M(1), amount: '250.0000' }],
    });
    expect(one.status).toBe(201);
    expect((one.body.data as { original: { status: string } }).original.status).toBe(
      'partially_refunded',
    );

    const second = await approve(w, {
      type: 'refund',
      reference_type: 'sale',
      reference_id: S(1),
      payload: null,
    });
    const two = await w.send('POST', 'refunds', {
      id: Z(2),
      reference_sale_id: S(1),
      approval_id: second,
      lines: [{ refund_of_item_id: Q(1), quantity: '1.0000' }],
      tenders: [{ id: Y(2), payment_method_id: M(1), amount: '250.0000' }],
    });
    expect((two.body.data as { original: { status: string } }).original.status).toBe('refunded');
    const original = await w.send('GET', `sales/${S(1)}`);
    expect(sale(original).status).toBe('refunded');
    expect(sale(original).lines[0]?.refundable_quantity).toBe('0.0000');
    expect(sale(original).lines[0]?.refunded_quantity).toBe('2.0000');
  });
});

describe('sales mock: one open shift per terminal', () => {
  it('refuses a second open shift, and allows one after the first is closed', async () => {
    const w = world([]);
    const opened = await w.send('POST', 'shifts', { opening_cash: '1000.0000' });
    expect(opened.status).toBe(201);
    expect(code(await w.send('POST', 'shifts', { opening_cash: '1000.0000' }))).toBe(
      'sales.shift_already_open',
    );
    const shiftId = (opened.body.data as { id: string }).id;
    const closed = await w.send('POST', `shifts/${shiftId}/close`, { counted_cash: '1000.0000' });
    expect(closed.status).toBe(200);
    const again = await w.send('POST', 'shifts', { opening_cash: '500.0000' });
    expect(again.status).toBe(201);
    const current = await w.send('GET', 'shifts/current');
    expect((current.body.data as { id: string }).id).not.toBe(shiftId);
  });

  it('refuses a sale, a recall and a refund while no shift is open', async () => {
    const w = world([]);
    expect(code(await w.send('POST', 'sales', {}))).toBe('sales.no_open_shift');
  });
});

describe('sales mock: approvals are single use and bound', () => {
  it('lets one approval authorise exactly one action', async () => {
    const w = world(['shift_open', 'completed_sale_open_shift']);
    const id = await approve(w, {
      type: 'refund',
      reference_type: 'sale',
      reference_id: S(1),
      payload: null,
    });
    const request = (refundId: string, tenderId: string) => ({
      id: refundId,
      reference_sale_id: S(1),
      approval_id: id,
      lines: [{ refund_of_item_id: Q(1), quantity: '1.0000' }],
      tenders: [{ id: tenderId, payment_method_id: M(1), amount: '250.0000' }],
    });
    expect((await w.send('POST', 'refunds', request(Z(1), Y(1)))).status).toBe(201);
    const reused = await w.send('POST', 'refunds', request(Z(2), Y(2)));
    expect(code(reused)).toBe('sales.approval_already_used');
    const stored = branchState(w.state, FIXTURE_TENANT_ID, FIXTURE_BRANCH_ID).approvals[0];
    expect(stored?.data.consumed_at).not.toBeNull();
  });

  it('does not consume an approval when the action is refused', async () => {
    const w = world(['shift_open', 'completed_sale_closed_shift', 'approved_void_approval']);
    const refused = await w.send('POST', `sales/${S(1)}/void`, { reason: 'x', approval_id: A(1) });
    expect(code(refused)).toBe('sales.shift_closed');
    const stored = branchState(w.state, FIXTURE_TENANT_ID, FIXTURE_BRANCH_ID).approvals[0];
    expect(stored?.data.consumed_at).toBeNull();
  });

  it('refuses a decided approval a second decision', async () => {
    const w = world(['shift_open', 'completed_sale_open_shift', 'pending_void_approval']);
    const decide = (decision: string) =>
      w.send('POST', `approvals/${A(1)}/decide`, {
        decision,
        method: 'pin_local',
        employee_code: 'S100',
        pin: '2468',
      });
    expect((await decide('reject')).status).toBe(200);
    expect(code(await decide('approve'))).toBe('sales.approval_not_pending');
    // A rejected approval cannot authorise a void.
    const refused = await w.send('POST', `sales/${S(1)}/void`, { reason: 'x', approval_id: A(1) });
    expect(code(refused)).toBe('sales.approval_not_approved');
  });

  it('binds an approval to the branch it was requested at (T-030 AC-24A)', () => {
    const w = world([]);
    const here = branchState(w.state, FIXTURE_TENANT_ID, FIXTURE_BRANCH_ID);
    const there = branchState(w.state, FIXTURE_TENANT_ID, 'BR-ELSEWHERE');
    here.approvals.push({
      branchId: here.branchId,
      requestedById: seedId('E', 1),
      data: {
        id: A(1),
        type: 'void',
        reference_type: 'sale',
        reference_id: S(1),
        payload: null,
        status: 'approved',
        requested_by_name: 'x',
        decided_by_name: 'y',
        decided_at: null,
        consumed_at: null,
      },
    });
    const binding: ApprovalBinding = {
      type: 'void',
      reference_type: 'sale',
      reference_id: S(1),
      payload: null,
    };
    const atOtherBranch = checkApproval(there, A(1), binding);
    expect('ok' in atOtherBranch && atOtherBranch.ok === false && atOtherBranch.code).toBe(
      'sales.approval_mismatch',
    );
    expect('ok' in checkApproval(here, A(1), binding)).toBe(false);
  });
});

describe('sales mock: tenders', () => {
  it('returns the sale to draft only when the last tender goes, recomputing the figures', async () => {
    const w = world(['shift_open', 'draft_sale_with_five_rice']);
    await w.send('POST', `sales/${S(1)}/tenders`, cashTender(Y(1), '300.0000'));
    await w.send('POST', `sales/${S(1)}/tenders`, cashTender(Y(2), '200.0000'));
    const one = await w.send('DELETE', `sales/${S(1)}/tenders/${Y(1)}`);
    expect(sale(one).status).toBe('pending_payment');
    expect(sale(one).paid_total).toBe('200.0000');
    expect(sale(one).balance_due).toBe('1050.0000');
    const none = await w.send('DELETE', `sales/${S(1)}/tenders/${Y(2)}`);
    expect(sale(none).status).toBe('draft');
    expect(sale(none).paid_total).toBe('0.0000');
    expect(sale(none).balance_due).toBe('1250.0000');
  });

  it('gives no change when the tender equals the balance, and change only above it', async () => {
    const w = world(['shift_open', 'draft_sale_with_five_rice']);
    const exact = await w.send('POST', `sales/${S(1)}/tenders`, cashTender(Y(1), '1250.0000'));
    const data = exact.body.data as { tender: { amount: string; change_given: string } };
    expect(data.tender).toMatchObject({ amount: '1250.0000', change_given: '0.0000' });
    // Nothing is owed now: any further tender is more than the balance.
    const more = await w.send('POST', `sales/${S(1)}/tenders`, cashTender(Y(2), '1.0000'));
    expect(code(more)).toBe('sales.exceeds_balance');
  });

  it('does not store a tender the sale refused, so the figures do not move', async () => {
    const w = world(['shift_open', 'draft_sale_with_five_rice']);
    const refused = await w.send('POST', `sales/${S(1)}/tenders`, {
      id: Y(1),
      payment_method_id: M(2),
      tendered: '2000.0000',
      reference: 'AUTH-1',
    });
    expect(code(refused)).toBe('sales.exceeds_balance');
    const read = await w.send('GET', `sales/${S(1)}`);
    expect(sale(read)).toMatchObject({
      status: 'draft',
      paid_total: '0.0000',
      balance_due: '1250.0000',
    });
    expect(sale(read).tenders).toEqual([]);
  });

  it('asks a card tender for its reference', async () => {
    const w = world(['shift_open', 'draft_sale_with_line']);
    const answer = await w.send('POST', `sales/${S(1)}/tenders`, {
      id: Y(1),
      payment_method_id: M(2),
      tendered: '250.0000',
    });
    expect(code(answer)).toBe('kernel.validation_failed');
    expect(answer.body.errors[0]?.field).toBe('reference');
  });

  it('implicitly recalls a sale held from payment, which needs an open shift', async () => {
    const w = world(['shift_open', 'held_from_pending_payment', 'close_open_shift']);
    const refused = await w.send('POST', `sales/${S(1)}/tenders`, cashTender(Y(1), '150.0000'));
    expect(code(refused)).toBe('sales.no_open_shift');
    const stored = branchState(w.state, FIXTURE_TENANT_ID, FIXTURE_BRANCH_ID).sales[0];
    expect(stored?.data.status).toBe('held');
    expect(stored?.data.tenders.length).toBe(1);
  });
});

describe('sales mock: recall and void write what the rules say', () => {
  it('reassigns a recalled sale to this terminal and its open shift', async () => {
    const w = world(['shift_open', 'held_sale_other_terminal']);
    const branch = branchState(w.state, FIXTURE_TENANT_ID, FIXTURE_BRANCH_ID);
    expect(branch.sales[0]?.terminalId).toBe('TRM-OTHER');
    const answer = await w.send('POST', `sales/${S(1)}/recall`, {});
    expect(answer.status).toBe(200);
    expect(branch.sales[0]?.terminalId).toBe(branch.terminalId);
    expect((answer.body.data as { shift_id: string }).shift_id).toBe(branch.shifts[0]?.data.id);
  });

  it('mirrors every tender when a sale is voided, leaving none to pay back twice', async () => {
    const w = world(['shift_open', 'pending_payment_sale']);
    const answer = await w.send('POST', `sales/${S(1)}/void`, { reason: 'Customer left' });
    const voided = sale(answer);
    expect(voided.status).toBe('voided');
    expect(voided.tenders.map((t) => t.amount)).toEqual(['100.0000', '-100.0000']);
    expect(voided.paid_total).toBe('0.0000');
  });

  it('takes store credit at completion and gives it back on a void', async () => {
    const w = world(['shift_open', 'credit_paid_sale', 'approved_void_approval']);
    const customer = w.state.seed.customers.find((c) => c.id === K(1));
    expect(customer?.credit_balance).toBe('2500.0000');
    await w.send('POST', `sales/${S(1)}/complete`, {});
    expect(customer?.credit_balance).toBe('2250.0000');
    const stored = branchState(w.state, FIXTURE_TENANT_ID, FIXTURE_BRANCH_ID).approvals[0];
    expect(stored?.data.status).toBe('approved');
    await w.send('POST', `sales/${S(1)}/void`, { reason: 'Mistake', approval_id: A(1) });
    expect(customer?.credit_balance).toBe('2500.0000');
  });

  it('counts a discount use once at completion', async () => {
    const w = world(['shift_open', 'draft_sale_with_discounted_line']);
    const discount = w.state.seed.discounts.find((d) => d.id === seedId('D', 1));
    expect(discount?.used_count).toBe(0);
    const total = sale(await w.send('GET', `sales/${S(1)}`)).total;
    await w.send('POST', `sales/${S(1)}/tenders`, cashTender(Y(1), total));
    await w.send('POST', `sales/${S(1)}/complete`, {});
    expect(discount?.used_count).toBe(1);
  });
});

describe('sales mock: refunds', () => {
  const request = (over: Record<string, unknown>) => ({
    id: Z(1),
    reference_sale_id: S(1),
    approval_id: A(1),
    lines: [{ refund_of_item_id: Q(1), quantity: '2.0000' }],
    tenders: [{ id: Y(1), payment_method_id: M(1), amount: '500.0000' }],
    ...over,
  });
  const given = ['shift_open', 'completed_sale_open_shift', 'approved_refund_approval'];

  it('is idempotent by its id: the same request twice makes one refund', async () => {
    const w = world(given);
    const first = await w.send('POST', 'refunds', request({}));
    const second = await w.send('POST', 'refunds', request({}));
    expect(first.status).toBe(201);
    expect(second.status).toBe(200);
    expect(JSON.stringify(second.body.data)).toBe(JSON.stringify(first.body.data));
    const refunds = branchState(w.state, FIXTURE_TENANT_ID, FIXTURE_BRANCH_ID).sales.filter(
      (s) => s.data.type === 'refund',
    );
    expect(refunds.length).toBe(1);
  });

  it('pays a store credit refund back onto the customer', async () => {
    const w = world(['shift_open', 'completed_credit_sale', 'approved_refund_approval']);
    const customer = w.state.seed.customers.find((c) => c.id === K(1));
    expect(customer?.credit_balance).toBe('2250.0000');
    const answer = await w.send(
      'POST',
      'refunds',
      request({
        lines: [{ refund_of_item_id: Q(1), quantity: '1.0000' }],
        tenders: [{ id: Y(1), payment_method_id: M(3), amount: '250.0000' }],
      }),
    );
    expect(answer.status).toBe(201);
    expect(customer?.credit_balance).toBe('2500.0000');
  });

  it('never pays out more than the original line was charged (T-030 AC-27B)', async () => {
    const w = world(given);
    const line = branchState(w.state, FIXTURE_TENANT_ID, FIXTURE_BRANCH_ID).sales[0]?.data.lines[0];
    if (!line) {
      throw new Error('The scenario made no line.');
    }
    line.line_total = '100.0000' as typeof line.line_total;
    const capped = await w.send('POST', 'refunds/quote', {
      reference_sale_id: S(1),
      lines: [{ refund_of_item_id: Q(1), quantity: '1.0000' }],
    });
    expect(code(capped)).toBe('sales.over_refund');
    expect(capped.body.errors[0]).toMatchObject({ field: 'refundable_amount', detail: '100.0000' });
  });

  it('gives the last quantity exactly what is left of the charge, so partial refunds sum to it', async () => {
    const w = world(given);
    const line = branchState(w.state, FIXTURE_TENANT_ID, FIXTURE_BRANCH_ID).sales[0]?.data.lines[0];
    if (!line) {
      throw new Error('The scenario made no line.');
    }
    line.line_total = '499.9999' as typeof line.line_total;
    const quote = await w.send('POST', 'refunds/quote', {
      reference_sale_id: S(1),
      lines: [{ refund_of_item_id: Q(1), quantity: '2.0000' }],
    });
    expect((quote.body.data as { total: string }).total).toBe('499.9999');
  });

  it('prices a refund at what the line was charged, in the line unit, ignoring today list price', async () => {
    const w = world(given);
    const rice = w.state.seed.products.find((p) => p.id === P(1));
    const unit = rice?.units[0];
    if (!unit) {
      throw new Error('The seed has no rice.');
    }
    unit.price = '999.0000';
    const quote = await w.send('POST', 'refunds/quote', {
      reference_sale_id: S(1),
      lines: [{ refund_of_item_id: Q(1), quantity: '1.0000' }],
    });
    // The line was sold at 250.0000 and stays at that price.
    expect((quote.body.data as { total: string }).total).toBe('250.0000');
  });

  it('writes nothing for a quote', async () => {
    const w = world(given);
    const before = JSON.stringify(branchState(w.state, FIXTURE_TENANT_ID, FIXTURE_BRANCH_ID).sales);
    await w.send('POST', 'refunds/quote', {
      reference_sale_id: S(1),
      lines: [{ refund_of_item_id: Q(1), quantity: '1.0000' }],
    });
    expect(JSON.stringify(branchState(w.state, FIXTURE_TENANT_ID, FIXTURE_BRANCH_ID).sales)).toBe(
      before,
    );
    expect(w.state.counters.refund).toBe(0);
  });

  it('refuses a goodwill refund naming a unit the product does not have', async () => {
    const w = world(['shift_open']);
    const answer = await w.send('POST', 'refunds/quote', {
      reference_sale_id: null,
      lines: [{ product_id: P(1), unit_id: N(3), quantity: '1.0000' }],
    });
    expect(code(answer)).toBe('sales.unit_unresolvable');
  });
});

describe('sales mock: every call has a handler', () => {
  it.each(SALES_CALLS.map((c) => [c.id, c] as const))('%s is implemented', async (_id, call) => {
    const w = world(['shift_open']);
    const params: Record<string, string> = {
      id: S(9),
      lineId: Q(9),
      tenderId: Y(9),
    };
    const answer = await w.send(
      call.method,
      callPath(call.id, params),
      call.method === 'GET' ? undefined : {},
    );
    expect(answer.status).not.toBe(501);
    expect(code(answer)).not.toBe('sales.mock_not_implemented');
  });
});
