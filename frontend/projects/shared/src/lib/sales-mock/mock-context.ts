import { SalesCallId } from '../sales/contract/calls';
import { MockResult } from './mock-envelope';
import { BranchState, MockState } from './mock-state';

/** What a handler is given. `branch` is the state of the signed-in session's tenant and branch. */
export interface MockContext {
  readonly state: MockState;
  readonly branch: BranchState;
  /** Path parameters, for example `{ id, lineId }`. */
  readonly params: Readonly<Record<string, string>>;
  /** The request body as an object (empty when there is none). */
  readonly body: Readonly<Record<string, unknown>>;
  /** One query parameter, or null. */
  query(name: string): string | null;
  /** The signed-in person's display name (employee name on a sale or a shift). */
  readonly actorName: string;
}

export type MockHandler = (ctx: MockContext) => MockResult;

/** One handler file's routes. The interceptor merges every table. */
export type HandlerTable = Partial<Record<SalesCallId, MockHandler>>;
