import { HandlerTable } from '../mock-context';

/**
 * `refunds.quote` and `refunds.create`. Track A1 implements them (T-037 plan, step A1); until
 * then a request for either is answered 501 by the interceptor.
 */
export function refundHandlers(): HandlerTable {
  return {};
}
