import { TestBed } from '@angular/core/testing';
import {
  SALES_MOCK_STATE,
  SalesApi,
  asMoney,
  branchState,
  createMockState,
} from '@flexipos/shared';
import { NEVER, firstValueFrom } from 'rxjs';
import { Connectivity } from '../../state/connectivity';
import { ShiftStore } from '../../state/shift-store';
import { byId, clickId, storeOf, typeInto } from '../../testing/sale-screen-helpers';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from '../../testing/till-harness';

async function submitCash(h: TillHarness, text: string): Promise<void> {
  typeInto(byId<HTMLInputElement>(h, 'opening-cash'), text);
  await clickId(h, 'opening-cash-submit');
}

function shiftsOf(h: TillHarness) {
  return branchState(h.state, HARNESS_TENANT_ID, 'BR001').shifts;
}

describe('ShiftOpenPrompt (AC-9, AC-10, AC-11)', () => {
  it('shows the prompt with its field focused when the terminal has no open shift (AC-9)', async () => {
    const h = await setupTill();
    expect(h.query('[data-testid="shift-open-prompt"]')).not.toBeNull();
    expect(document.activeElement).toBe(byId(h, 'opening-cash'));
  });

  it('is not shown when a shift is open', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    expect(h.query('[data-testid="shift-open-prompt"]')).toBeNull();
  });

  it('opens the shift, shows its number in the header and hides the prompt (AC-10)', async () => {
    const h = await setupTill();
    await submitCash(h, '5000');
    expect(h.text('shift')).toBe('Shift SH-000001');
    expect(h.query('[data-testid="shift-open-prompt"]')).toBeNull();
    expect(shiftsOf(h)).toHaveLength(1);
    expect(shiftsOf(h)[0]?.data.opening_cash).toBe('5000.0000');
    expect(document.activeElement).toBe(byId(h, 'scan-field'));
  });

  it('accepts zero and trims the spaces around what was typed', async () => {
    const h = await setupTill();
    await submitCash(h, ' 0 ');
    expect(storeOf(h, ShiftStore).shiftOpen()).toBe(true);
    expect(shiftsOf(h)[0]?.data.opening_cash).toBe('0.0000');
  });

  it.each([
    ['', 'Opening cash is required'],
    ['abc', 'Opening cash must be a number such as 12.50'],
    ['-5', 'Opening cash cannot be negative'],
    ['12.34567', 'Opening cash can have at most 4 decimal places'],
  ])(
    'refuses "%s" in the form, naming the field, before any call (AC-10)',
    async (text, message) => {
      const h = await setupTill();
      const open = vi.spyOn(TestBed.inject(SalesApi), 'openShift');
      await submitCash(h, text);
      expect(byId(h, 'opening-cash-error').textContent?.trim()).toBe(message);
      expect(open).not.toHaveBeenCalled();
      expect(shiftsOf(h)).toHaveLength(0);
      expect(h.query('[data-testid="shift-open-prompt"]')).not.toBeNull();
    },
  );

  it('clears the message when the cashier types again', async () => {
    const h = await setupTill();
    await submitCash(h, 'abc');
    typeInto(byId<HTMLInputElement>(h, 'opening-cash'), '1');
    await h.settle();
    expect(h.query('[data-testid="opening-cash-error"]')).toBeNull();
  });

  it('uses the shift that is already open when the server says so (AC-11)', async () => {
    const h = await setupTill();
    // The shift was opened somewhere else after this page loaded: the prompt is still showing.
    await firstValueFrom(TestBed.inject(SalesApi).openShift({ opening_cash: asMoney('100') }));
    expect(h.query('[data-testid="shift-open-prompt"]')).not.toBeNull();
    await submitCash(h, '200');
    expect(h.text('shift')).toBe('Shift SH-000001');
    expect(h.query('[data-testid="shift-open-prompt"]')).toBeNull();
    expect(h.query('[data-testid="message-bar"]')).toBeNull();
    expect(shiftsOf(h)).toHaveLength(1);
    expect(shiftsOf(h)[0]?.data.opening_cash).toBe('100.0000');
  });

  it('ignores a second submit while the first is in flight', async () => {
    const h = await setupTill();
    const open = vi.spyOn(TestBed.inject(SalesApi), 'openShift').mockReturnValue(NEVER);
    typeInto(byId<HTMLInputElement>(h, 'opening-cash'), '10');
    const form = byId(h, 'opening-cash').closest('form');
    form?.dispatchEvent(new Event('submit', { cancelable: true }));
    await h.settle();
    form?.dispatchEvent(new Event('submit', { cancelable: true }));
    await h.settle();
    expect(open).toHaveBeenCalledTimes(1);
    expect(byId<HTMLButtonElement>(h, 'opening-cash-submit').disabled).toBe(true);
  });

  it('reads the shift again when the network comes back, instead of staying blank', async () => {
    const state = createMockState();
    state.faults.unreachable = true;
    const h = await setupTill({
      given: ['shift_open'],
      providers: [{ provide: SALES_MOCK_STATE, useValue: state }],
    });
    expect(storeOf(h, ShiftStore).loaded()).toBe(false);
    expect(h.query('[data-testid="network-banner"]')).not.toBeNull();
    expect(h.query('[data-testid="shift-open-prompt"]')).toBeNull();
    state.faults.unreachable = false;
    await storeOf(h, Connectivity).retryNow();
    await h.settle();
    expect(storeOf(h, ShiftStore).loaded()).toBe(true);
    expect(h.text('shift')).toBe('Shift SH-000001');
    expect(h.query('[data-testid="network-banner"]')).toBeNull();
  });
});
