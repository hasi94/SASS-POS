/*
 * The development-only sales backend (T-037). Nothing here may be imported by application code
 * outside an environment file, and nothing in this folder runs at module level, so a production
 * bundle that does not import the interceptor does not contain it (checked by the sentinel grep,
 * see frontend/README.md).
 */
export { salesMockInterceptor } from './sales-mock.interceptor';
export {
  SALES_MOCK_LATENCY_MS,
  SALES_MOCK_STATE,
  MOCK_CLOCK_START,
  createMockState,
  branchState,
} from './mock-state';
export type { BranchState, MockFaults, MockState } from './mock-state';
export { SALES_MOCK_SENTINEL } from './mock-envelope';
export { applyGiven, scenarioTable } from './scenarios';

/** Fictional demonstration values, for the README and for specs. Not credentials. */
export const SALES_MOCK_DEMO = {
  cashier: { code: 'D001', pin: '1111' },
  supervisor: { code: 'S100', pin: '2468' },
  nonApprover: { code: 'C200', pin: '1357' },
  /** Rice 1kg, by barcode and by PLU. */
  barcode: '4790000000011',
  plu: '101',
  /** A box of six waters: carries its own unit and quantity. */
  packBarcode: '4790000000035',
  /** Loose tomatoes, weighable: 1.250 kg on a scale label, and the same item as a price label. */
  weightLabel: '2100201012504',
  priceLabel: '2200201012504',
  weighablePlu: '201',
  zeroPricedPlu: '301',
  customerWithCredit: 'CUS-0001',
  customerWithoutCredit: 'CUS-0002',
} as const;
