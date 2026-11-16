import { HandlerTable } from '../mock-context';

/**
 * `sales.complete`, `sales.hold`, `sales.recall` and `sales.void`. Track A1 implements them
 * (T-037 plan, step A1); until then a request for any is answered 501 by the interceptor.
 */
export function lifecycleHandlers(): HandlerTable {
  return {};
}
