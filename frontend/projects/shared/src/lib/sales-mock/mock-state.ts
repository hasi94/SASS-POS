import { InjectionToken } from '@angular/core';
import { Approval } from '../sales/contract/approval';
import { SalesCallId } from '../sales/contract/calls';
import { Sale } from '../sales/contract/sale';
import { CashMovement, CashShift, ClosedShift } from '../sales/contract/shift';
import { MockSeed, buildSeed, seedId } from './mock-seed';

/**
 * Milliseconds of latency the mock adds to every answer. Zero in specs. The development default
 * is the auth mock's 150 ms so the till feels like a network.
 */
export const SALES_MOCK_LATENCY_MS = /* @__PURE__ */ new InjectionToken<number>(
  'SALES_MOCK_LATENCY_MS',
  { providedIn: 'root', factory: () => 150 },
);

/** What the mock hands out ids for. Each kind counts on its own. */
export type IdKind =
  | 'sale'
  | 'line'
  | 'shift'
  | 'movement'
  | 'tender'
  | 'approval'
  | 'refund'
  | 'reference'
  | 'shiftNumber';

const ID_TAGS: Record<Exclude<IdKind, 'reference' | 'shiftNumber'>, string> = {
  sale: 'S',
  line: 'Q',
  shift: 'H',
  movement: 'V',
  tender: 'T',
  approval: 'A',
  refund: 'R',
};

export interface StoredShift {
  terminalId: string;
  data: CashShift;
  /** Set once the shift is closed: the full result, returned again on a replayed close. */
  closed: ClosedShift | null;
}

export interface StoredMovement {
  /** The request without its id, as JSON: what a replay is compared against. */
  payloadKey: string;
  data: CashMovement;
}

export interface StoredSale {
  terminalId: string;
  data: Sale;
}

export interface StoredApproval {
  data: Approval;
  /** The branch the approval was requested at: part of its binding (T-030 AC-24A). */
  branchId: string;
  requestedById: string;
}

/** Everything the mock keeps for one branch of one tenant. */
export interface BranchState {
  readonly tenantId: string;
  readonly branchId: string;
  /** One terminal per branch (P-8): `TRM-<branchId>`. */
  readonly terminalId: string;
  shifts: StoredShift[];
  movements: StoredMovement[];
  sales: StoredSale[];
  approvals: StoredApproval[];
}

/**
 * Faults a spec can switch on to prove the till copes with a bad network (AC-49, AC-74, AC-75).
 * They never happen on their own.
 */
export interface MockFaults {
  /** Every call fails as if the server were unreachable (status 0), without being applied. */
  unreachable: boolean;
  /** The next call with each of these ids is applied, then its answer is dropped (status 0). */
  dropAnswerOf: SalesCallId[];
}

export interface MockState {
  /** Milliseconds since the epoch of the mock's clock. */
  clockMs: number;
  requestSeq: number;
  counters: Record<IdKind, number>;
  seed: MockSeed;
  branches: Map<string, BranchState>;
  faults: MockFaults;
}

/** The first instant of the mock's clock; it moves one second for every write. */
export const MOCK_CLOCK_START = '2026-01-15T09:00:00Z';

export function createMockState(): MockState {
  return {
    clockMs: Date.parse(MOCK_CLOCK_START),
    requestSeq: 0,
    counters: {
      sale: 0,
      line: 0,
      shift: 0,
      movement: 0,
      tender: 0,
      approval: 0,
      refund: 0,
      reference: 0,
      shiftNumber: 0,
    },
    seed: buildSeed(),
    branches: new Map(),
    faults: { unreachable: false, dropAnswerOf: [] },
  };
}

/** Fresh per `TestBed`; the till and the specs share it through this token. */
export const SALES_MOCK_STATE = /* @__PURE__ */ new InjectionToken<MockState>('SALES_MOCK_STATE', {
  providedIn: 'root',
  factory: createMockState,
});

/** The state of one branch, created on first use. */
export function branchState(state: MockState, tenantId: string, branchId: string): BranchState {
  const key = `${tenantId}/${branchId}`;
  let branch = state.branches.get(key);
  if (!branch) {
    branch = {
      tenantId,
      branchId,
      terminalId: `TRM-${branchId}`,
      shifts: [],
      movements: [],
      sales: [],
      approvals: [],
    };
    state.branches.set(key, branch);
  }
  return branch;
}

/** The next id of a kind: ULID-shaped and deterministic, for example `01JMCKS000...0001`. */
export function nextId(
  state: MockState,
  kind: Exclude<IdKind, 'reference' | 'shiftNumber'>,
): string {
  state.counters[kind] = state.counters[kind] + 1;
  return seedId(ID_TAGS[kind], state.counters[kind]);
}

/** A short human reference for an unnumbered sale (AC-51), for example `S-0001`. */
export function nextReference(state: MockState): string {
  state.counters.reference = state.counters.reference + 1;
  return `S-${String(state.counters.reference).padStart(4, '0')}`;
}

/** The next shift number, for example `SH-000001`. */
export function nextShiftNumber(state: MockState): string {
  state.counters.shiftNumber = state.counters.shiftNumber + 1;
  return `SH-${String(state.counters.shiftNumber).padStart(6, '0')}`;
}

/** Advances the clock one second (a write happened) and returns the new instant. */
export function tick(state: MockState): string {
  state.clockMs = state.clockMs + 1000;
  return new Date(state.clockMs).toISOString();
}

/** The mock's current instant, without moving it. */
export function nowIso(state: MockState): string {
  return new Date(state.clockMs).toISOString();
}

/** The business date of the mock's current instant, `YYYY-MM-DD`. */
export function businessDate(state: MockState): string {
  return nowIso(state).slice(0, 10);
}

/** The open shift of a branch's terminal, if any. */
export function openShiftOf(branch: BranchState): StoredShift | undefined {
  return branch.shifts.find((s) => s.terminalId === branch.terminalId && s.data.status === 'open');
}
