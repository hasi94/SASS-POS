import { HandlerTable } from '../mock-context';

/**
 * `sales.add-tender` and `sales.remove-tender`. Track A1 implements them (T-037 plan, step A1);
 * until then a request for either is answered 501 by the interceptor.
 */
export function tenderHandlers(): HandlerTable {
  return {};
}
