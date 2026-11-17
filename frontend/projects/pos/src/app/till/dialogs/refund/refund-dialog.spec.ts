import { TestBed } from '@angular/core/testing';
import { ApiError, RefundRequest, RefundResult, SalesApi, branchState } from '@flexipos/shared';
import { Observable, throwError } from 'rxjs';
import { RefundStore } from '../../state/refund-store';
import { SUPERVISOR, clickButton, typeCredentials } from '../../testing/approval-helpers';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from '../../testing/till-harness';
import {
  dialogsOf,
  findSaleRows,
  mockSales,
  settleAll,
  storeOf,
  topHeading,
} from '../../testing/void-helpers';

/** The element of the refund dialog with this test id (the till behind has some of the same). */
function inRefund<T extends HTMLElement = HTMLElement>(h: TillHarness, testId: string): T | null {
  return h.query<T>(`pos-refund-dialog [data-testid="${testId}"]`);
}

function refundText(h: TillHarness, testId: string): string {
  return (inRefund(h, testId)?.textContent ?? '').replace(/\s+/g, ' ').trim();
}

function set(field: HTMLInputElement | HTMLSelectElement | null, value: string): void {
  if (!field) {
    throw new Error('That field is not on the screen.');
  }
  field.value = value;
  field.dispatchEvent(
    new Event(field instanceof HTMLSelectElement ? 'change' : 'input', { bubbles: true }),
  );
}

async function openRefund(h: TillHarness, data?: unknown): Promise<void> {
  void dialogsOf(h).open('refund', data);
  await settleAll(h);
}

/** Looks the first sale of the branch up by its number, as a cashier would. */
async function findFirstSale(h: TillHarness): Promise<void> {
  const number = mockSales(h)[0]?.data.number;
  const field = h.query<HTMLInputElement>('pos-refund-dialog input[name="number"]');
  set(field, number ?? 'none');
  field?.form?.requestSubmit();
  await settleAll(h);
}

async function selectLine(h: TillHarness, index: number, quantity?: string): Promise<void> {
  inRefund<HTMLInputElement>(h, `refund-select-${index}`)?.click();
  await settleAll(h);
  if (quantity !== undefined) {
    set(inRefund<HTMLInputElement>(h, `refund-qty-${index}`), quantity);
    await settleAll(h);
  }
}

async function go(h: TillHarness): Promise<void> {
  inRefund<HTMLButtonElement>(h, 'refund-go')?.click();
  await settleAll(h);
}

/** Has the supervisor approve whatever is being asked. */
async function approve(h: TillHarness): Promise<void> {
  expect(topHeading(h)).toBe('Supervisor approval');
  typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
  clickButton(h, 'Approve');
  await settleAll(h);
  await settleAll(h);
}

const refunds = (h: TillHarness) => mockSales(h).filter((s) => s.data.type === 'refund');

function spyCreate(): { calls: RefundRequest[] } {
  const api = TestBed.inject(SalesApi);
  const real = api.createRefund.bind(api);
  const calls: RefundRequest[] = [];
  vi.spyOn(api, 'createRefund').mockImplementation((body): Observable<RefundResult> => {
    calls.push(JSON.parse(JSON.stringify(body)) as RefundRequest);
    return real(body);
  });
  return { calls };
}

describe('the refund dialog', () => {
  describe('loading the sale (AC-59)', () => {
    it('finds a sale by its number and lists its lines as the server gave them', async () => {
      const h = await setupTill({ given: ['shift_open', 'partially_refunded_sale'] });
      await openRefund(h);
      expect(topHeading(h)).toBe('Refund');
      await findFirstSale(h);
      expect(refundText(h, 'refund-sale')).toContain('INV-000001');
      expect(refundText(h, 'refund-sale')).toContain('partially refunded');
      expect(refundText(h, 'refund-figures-0')).toBe('Sold 2 ea · refunded 1 ea · left 1 ea');
      expect(inRefund<HTMLInputElement>(h, 'refund-select-0')?.disabled).toBe(false);
    });

    it('shows a number that is unknown as "Sale not found at this branch"', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      await openRefund(h);
      const field = h.query<HTMLInputElement>('pos-refund-dialog input[name="number"]');
      set(field, 'INV-999999');
      field?.form?.requestSubmit();
      await settleAll(h);
      expect(refundText(h, 'refund-problem')).toBe('Sale not found at this branch');
      expect(inRefund(h, 'refund-sale')).toBeNull();
    });

    it("shows another branch's sale, by number or by id, as not found at this branch", async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_other_branch'] });
      const other = branchState(h.state, HARNESS_TENANT_ID, 'BR-OTHER').sales[0]?.data;
      await openRefund(h);
      const field = h.query<HTMLInputElement>('pos-refund-dialog input[name="number"]');
      set(field, other?.number ?? 'none');
      field?.form?.requestSubmit();
      await settleAll(h);
      expect(refundText(h, 'refund-problem')).toBe('Sale not found at this branch');

      await openRefund(h, { saleId: other?.id });
      expect(refundText(h, 'refund-problem')).toBe('Sale not found at this branch');
      expect(storeOf(h, RefundStore).sale()).toBeNull();
    });

    it("picks one of this shift's sales from the find-sale list", async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      await openRefund(h);
      clickButton(h, 'Pick from this shift');
      await settleAll(h);
      expect(topHeading(h)).toBe('Find a sale');
      expect(findSaleRows(h)[0]).toContain('INV-000001');
      h.query<HTMLButtonElement>('pos-find-sale-dialog button.row')?.click();
      await settleAll(h);
      expect(topHeading(h)).toBe('Refund');
      expect(refundText(h, 'refund-sale')).toContain('INV-000001');
    });

    it('opens on the sale a void handed over (AC-57)', async () => {
      const h = await setupTill({
        given: ['shift_open', 'completed_sale_closed_shift', 'shift_open'],
      });
      const id = mockSales(h)[0]?.data.id;
      await openRefund(h, { saleId: id });
      expect(refundText(h, 'refund-sale')).toContain('INV-000001');
      expect(inRefund(h, 'refund-select-0')).not.toBeNull();
    });

    it('disables a fully refunded line and says so, and sends nothing for it', async () => {
      const h = await setupTill({ given: ['shift_open', 'refunded_sale'] });
      await openRefund(h);
      await findFirstSale(h);
      expect(refundText(h, 'refund-fully-0')).toBe('Fully refunded');
      expect(inRefund<HTMLInputElement>(h, 'refund-select-0')?.disabled).toBe(true);
      inRefund<HTMLInputElement>(h, 'refund-select-0')?.click();
      await settleAll(h);
      expect(inRefund(h, 'refund-qty-0')).toBeNull();
      const quote = vi.spyOn(TestBed.inject(SalesApi), 'quoteRefund');
      await go(h);
      expect(refundText(h, 'refund-problem')).toBe('Choose at least one line to refund');
      expect(quote).not.toHaveBeenCalled();
    });
  });

  describe('the quantity (AC-60)', () => {
    it.each([
      ['', 'Enter a quantity'],
      ['0', 'The quantity must be more than zero'],
      ['-1', 'The quantity must be more than zero'],
      ['1.00001', 'At most 4 decimal places'],
      ['2.0001', 'More than is left to refund (at most 2.0000)'],
      ['3', 'More than is left to refund (at most 2.0000)'],
    ])('rejects %j in the form and calls the server for nothing', async (text, why) => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      await openRefund(h);
      await findFirstSale(h);
      const api = TestBed.inject(SalesApi);
      const quote = vi.spyOn(api, 'quoteRefund');
      const create = vi.spyOn(api, 'createRefund');
      await selectLine(h, 0, text);
      await go(h);
      expect(refundText(h, 'refund-line-problem-0')).toBe(why);
      expect(refundText(h, 'refund-problem')).toBe('Fix the quantities marked below');
      expect(quote).not.toHaveBeenCalled();
      expect(create).not.toHaveBeenCalled();
      expect(inRefund(h, 'refund-quote')).toBeNull();
    });

    it('offers only the unit the line was sold in', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0);
      expect(h.query('pos-refund-dialog select[name="unit"]')).toBeNull();
      const unit = mockSales(h)[0]?.data.lines[0]?.unit_code ?? '';
      expect(inRefund(h, 'refund-qty-0')?.closest('label')?.textContent).toContain(`(${unit})`);
    });

    it('accepts exactly what is left, to the last digit', async () => {
      const h = await setupTill({ given: ['shift_open', 'partially_refunded_sale'] });
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0);
      expect(inRefund<HTMLInputElement>(h, 'refund-qty-0')?.value).toBe('1.0000');
      await go(h);
      expect(inRefund(h, 'refund-quote')).not.toBeNull();
      set(inRefund<HTMLInputElement>(h, 'refund-qty-0'), '1.0001');
      await settleAll(h);
      await go(h);
      expect(refundText(h, 'refund-line-problem-0')).toContain('More than is left to refund');
    });
  });

  describe('a referenced refund (AC-61 to AC-64)', () => {
    it('quotes through the server, asks a supervisor and shows the refund, its total and the new status', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      const { calls } = spyCreate();
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0, '1');
      await go(h);

      // The server's quote, never a figure of the till's own.
      expect(refundText(h, 'refund-quote')).toContain('Rice 1kg');
      expect(refundText(h, 'refund-quote-total')).toBe('250.00 LKR');
      // Only methods that allow a refund; the payout defaults to the quote's total string.
      const options = Array.from(
        h.host.querySelectorAll<HTMLOptionElement>('pos-refund-dialog select option'),
      ).map((o) => o.textContent?.trim());
      expect(options).toEqual(['Cash', 'Card', 'Store credit']);
      expect(inRefund<HTMLInputElement>(h, 'refund-amount')?.value).toBe(
        storeOf(h, RefundStore).quote()?.total,
      );

      await go(h);
      expect(h.text('approval-summary')).toContain('Refund of sale INV-000001');
      await approve(h);

      expect(calls).toHaveLength(1);
      const sent = calls[0];
      expect(sent?.reference_sale_id).toBe(mockSales(h)[0]?.data.id);
      // Original line and quantity only: the till never sends a price.
      expect(sent?.lines).toEqual([
        { refund_of_item_id: mockSales(h)[0]?.data.lines[0]?.id, quantity: '1' },
      ]);
      expect(sent?.tenders).toHaveLength(1);
      expect(sent?.tenders[0]).toMatchObject({ amount: '250.0000', reference: null });

      expect(refundText(h, 'refund-number')).toBe(refunds(h)[0]?.data.number ?? 'missing');
      expect(refundText(h, 'refund-result-lines')).toContain('Rice 1kg');
      expect(refundText(h, 'refund-result-total')).toBe('250.00 LKR');
      expect(refundText(h, 'refund-original-status')).toContain('partially refunded');
      expect(mockSales(h)[0]?.data.status).toBe('partially_refunded');

      const approval = h.state.branches.values().next().value?.approvals[0];
      expect(approval?.data).toMatchObject({
        type: 'refund',
        reference_type: 'sale',
        reference_id: mockSales(h)[0]?.data.id,
      });
      expect(approval?.data.consumed_at).not.toBeNull();

      clickButton(h, 'Close');
      await settleAll(h);
      expect(h.query('[role="dialog"]')).toBeNull();
    });

    it('refunds the whole sale and shows it as refunded', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0);
      await go(h);
      await go(h);
      await approve(h);
      expect(refundText(h, 'refund-result-total')).toBe('500.00 LKR');
      expect(refundText(h, 'refund-original-status')).toContain('refunded');
      expect(refundText(h, 'refund-original-status')).not.toContain('partially');
      expect(mockSales(h)[0]?.data.status).toBe('refunded');
    });

    it('needs no tender for a refund of 0.0000', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_zero_sale'] });
      const { calls } = spyCreate();
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0);
      await go(h);
      expect(refundText(h, 'refund-quote-total')).toBe('0.00 LKR');
      expect(inRefund(h, 'refund-no-tender')).not.toBeNull();
      expect(inRefund(h, 'refund-method')).toBeNull();
      expect(inRefund(h, 'refund-amount')).toBeNull();
      await go(h);
      await approve(h);
      expect(calls[0]?.tenders).toEqual([]);
      expect(refunds(h)).toHaveLength(1);
    });

    it('asks for a reference where the method needs one, before anything is sent', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      const { calls } = spyCreate();
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0, '1');
      await go(h);
      expect(inRefund(h, 'refund-reference')).toBeNull();
      const card = storeOf(h, RefundStore)
        .refundMethods()
        .find((m) => m.code === 'CARD');
      set(inRefund<HTMLSelectElement>(h, 'refund-method'), card?.id ?? '');
      await settleAll(h);
      await go(h);
      expect(refundText(h, 'refund-problem')).toBe('Card needs a reference');
      expect(h.query('pos-supervisor-dialog')).toBeNull();
      set(inRefund<HTMLInputElement>(h, 'refund-reference'), 'AUTH-77');
      await go(h);
      await approve(h);
      expect(calls[0]?.tenders[0]).toMatchObject({
        payment_method_id: card?.id,
        reference: 'AUTH-77',
      });
    });

    it('shows a payout above what is owed as "More than is owed back", then goes through when corrected', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      const { calls } = spyCreate();
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0, '1');
      await go(h);
      set(inRefund<HTMLInputElement>(h, 'refund-amount'), '9999.0000');
      await go(h);
      await approve(h);
      expect(refundText(h, 'refund-problem')).toBe('More than is owed back');
      expect(refunds(h)).toHaveLength(0);
      expect(mockSales(h)[0]?.data.status).toBe('completed');

      // A refusal is a definite answer: the approval is not kept, so the corrected refund asks again (R-1).
      set(inRefund<HTMLInputElement>(h, 'refund-amount'), '250.0000');
      await go(h);
      await approve(h);
      expect(refundText(h, 'refund-number')).toBe(refunds(h)[0]?.data.number ?? 'missing');
      expect(calls).toHaveLength(2);
      expect(calls[1]?.id).not.toBe(calls[0]?.id);
      expect(calls[1]?.approval_id).not.toBe(calls[0]?.approval_id);
      expect(h.state.branches.values().next().value?.approvals).toHaveLength(2);
    });

    it('leaves everything alone when the supervisor rejects or the cashier cancels', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      const create = vi.spyOn(TestBed.inject(SalesApi), 'createRefund');
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0, '1');
      await go(h);
      await go(h);
      typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
      clickButton(h, 'Reject');
      await settleAll(h);
      await settleAll(h);
      expect(refundText(h, 'refund-problem')).toBe('Approval rejected: nothing was refunded');

      await go(h);
      h.press('Escape');
      await settleAll(h);
      expect(topHeading(h)).toBe('Refund');
      expect(create).not.toHaveBeenCalled();
      expect(refunds(h)).toHaveLength(0);
      expect(mockSales(h)[0]?.data.status).toBe('completed');
    });

    it('shows a refusal of the server in words and keeps the form', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0, '1');
      // Another till refunded the sale after this one loaded it.
      const stored = mockSales(h)[0];
      if (stored?.data.lines[0]) {
        stored.data.lines[0].refundable_quantity = '0.0000' as never;
      }
      await go(h);
      expect(refundText(h, 'refund-problem')).toBe('Already fully refunded');
      expect(inRefund(h, 'refund-qty-0')).not.toBeNull();
    });
  });

  describe('a lost answer (AC-61, AC-64)', () => {
    it('reloads the original and re-sends the same refund and tender ids: one refund, one approval', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      const api = TestBed.inject(SalesApi);
      const reload = vi.spyOn(api, 'getSale');
      const { calls } = spyCreate();
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0, '1');
      await go(h);
      h.state.faults.dropAnswerOf.push('refunds.create');
      reload.mockClear();
      await go(h);
      await approve(h);

      expect(calls).toHaveLength(2);
      expect(calls[1]).toEqual(calls[0]);
      expect(calls[1]?.tenders[0]?.id).toBe(calls[0]?.tenders[0]?.id);
      expect(reload).toHaveBeenCalledWith(mockSales(h)[0]?.data.id);
      expect(refunds(h)).toHaveLength(1);
      expect(h.state.branches.values().next().value?.approvals).toHaveLength(1);
      expect(refundText(h, 'refund-number')).toBe(refunds(h)[0]?.data.number ?? 'missing');
      expect(refundText(h, 'refund-result-total')).toBe('250.00 LKR');
    });

    it('keeps the ids and the approval for the next try when the server is still out of reach', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      const api = TestBed.inject(SalesApi);
      const real = api.createRefund.bind(api);
      const calls: RefundRequest[] = [];
      vi.spyOn(api, 'createRefund').mockImplementation((body) => {
        calls.push(JSON.parse(JSON.stringify(body)) as RefundRequest);
        if (calls.length === 1) {
          h.state.faults.unreachable = true;
        }
        return real(body);
      });
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0, '1');
      await go(h);
      await go(h);
      await approve(h);
      expect(refundText(h, 'refund-problem')).toContain('Cannot reach the server');
      expect(refunds(h)).toHaveLength(0);

      // Offline, the submit is disabled (AC-74): the cashier asks the till to look again first.
      expect(h.query<HTMLButtonElement>('[data-testid="refund-go"]')?.disabled).toBe(true);
      h.state.faults.unreachable = false;
      h.query<HTMLButtonElement>('[data-testid="network-banner"] button')?.click();
      await settleAll(h);
      await go(h);
      expect(h.query('pos-supervisor-dialog')).toBeNull();
      expect(calls).toHaveLength(2);
      expect(calls[1]).toEqual(calls[0]);
      expect(refunds(h)).toHaveLength(1);
      expect(h.state.branches.values().next().value?.approvals).toHaveLength(1);
      expect(refundText(h, 'refund-number')).not.toBe('');
    });

    it('reads the original back before a refund of another shape goes out after a lost answer (W-1)', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      const api = TestBed.inject(SalesApi);
      const { calls } = spyCreate();
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0, '1');
      await go(h);
      h.state.faults.dropAnswerOf.push('refunds.create');
      vi.spyOn(api, 'getSale').mockImplementationOnce(() =>
        throwError(() => new ApiError(0, 'Cannot reach the server.')),
      );
      await go(h);
      await approve(h);
      expect(calls).toHaveLength(1);
      expect(refunds(h)).toHaveLength(1);

      h.query<HTMLButtonElement>('[data-testid="network-banner"] button')?.click();
      await settleAll(h);
      set(inRefund<HTMLInputElement>(h, 'refund-amount'), '100.0000');
      await go(h);
      expect(h.query('pos-supervisor-dialog')).toBeNull();
      expect(calls).toHaveLength(1);
      expect(refunds(h)).toHaveLength(1);
      expect(refundText(h, 'refund-problem')).toContain('The sale changed since the last try');
    });

    it('does not reuse an approval after a lost answer once the shape changes (R-1)', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      const api = TestBed.inject(SalesApi);
      const real = api.createRefund.bind(api);
      const calls: RefundRequest[] = [];
      vi.spyOn(api, 'createRefund').mockImplementation((body) => {
        calls.push(JSON.parse(JSON.stringify(body)) as RefundRequest);
        h.state.faults.unreachable = true;
        return real(body);
      });
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0, '1');
      await go(h);
      await go(h);
      await approve(h);
      expect(calls).toHaveLength(1);
      h.state.faults.unreachable = false;
      h.query<HTMLButtonElement>('[data-testid="network-banner"] button')?.click();
      await settleAll(h);
      set(inRefund<HTMLInputElement>(h, 'refund-amount'), '100.0000');
      await go(h);
      expect(topHeading(h)).toBe('Supervisor approval');
    });

    it('keeps the lost ids when the form is changed and changed back, after a failed read-back (R-2)', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      const api = TestBed.inject(SalesApi);
      const { calls } = spyCreate();
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0, '1');
      await go(h);
      h.state.faults.dropAnswerOf.push('refunds.create');
      // The reload after the lost answer, and the read-back of the next shape, both fail.
      vi.spyOn(api, 'getSale')
        .mockImplementationOnce(() => throwError(() => new ApiError(0, 'Cannot reach the server.')))
        .mockImplementationOnce(() =>
          throwError(() => new ApiError(0, 'Cannot reach the server.')),
        );
      await go(h);
      await approve(h);
      expect(calls).toHaveLength(1);

      const recover = async (): Promise<void> => {
        h.query<HTMLButtonElement>('[data-testid="network-banner"] button')?.click();
        await settleAll(h);
      };
      await recover();
      const original = inRefund<HTMLInputElement>(h, 'refund-amount')?.value ?? '';
      set(inRefund<HTMLInputElement>(h, 'refund-amount'), '100.0000');
      await go(h);
      expect(calls).toHaveLength(1);
      await recover();
      set(inRefund<HTMLInputElement>(h, 'refund-amount'), original);
      await go(h);
      // The approval of the lost try is still held for this very shape: nobody is asked again.
      expect(h.query('pos-supervisor-dialog')).toBeNull();
      expect(calls).toHaveLength(2);
      expect(calls[1]?.id).toBe(calls[0]?.id);
      expect(calls[1]?.tenders[0]?.id).toBe(calls[0]?.tenders[0]?.id);
      expect(refunds(h)).toHaveLength(1);
    });

    it('keeps the first baseline while the same shape is unknown, so a changed shape cannot pay out twice (R-1)', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      const { calls } = spyCreate();
      const recover = async (): Promise<void> => {
        h.query<HTMLButtonElement>('[data-testid="network-banner"] button')?.click();
        await settleAll(h);
      };
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0, '1');
      await go(h);
      // Try 1 and its automatic retry are both lost; the reload between them succeeds and shows A.
      h.state.faults.dropAnswerOf.push('refunds.create', 'refunds.create');
      await go(h);
      await approve(h);
      expect(calls).toHaveLength(2);
      expect(refunds(h)).toHaveLength(1);
      await recover();

      // The same refund again, unchanged: lost twice more.
      h.state.faults.dropAnswerOf.push('refunds.create', 'refunds.create');
      await go(h);
      expect(h.query('pos-supervisor-dialog')).toBeNull();
      expect(calls).toHaveLength(4);
      await recover();

      // Another shape: the sale shows A, which the first baseline did not, so nothing goes out.
      set(inRefund<HTMLInputElement>(h, 'refund-amount'), '100.0000');
      await go(h);
      expect(h.query('pos-supervisor-dialog')).toBeNull();
      expect(calls).toHaveLength(4);
      expect(new Set(calls.map((c) => c.id)).size).toBe(1);
      expect(refunds(h)).toHaveLength(1);
      expect(refundText(h, 'refund-problem')).toContain('The sale changed since the last try');
    });

    it('makes new ids when the cashier changes what is refunded', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      const { calls } = spyCreate();
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0, '1');
      await go(h);
      set(inRefund<HTMLInputElement>(h, 'refund-amount'), '9999.0000');
      await go(h);
      await approve(h);
      set(inRefund<HTMLInputElement>(h, 'refund-amount'), '250.0000');
      await go(h);
      await approve(h);
      expect(calls).toHaveLength(2);
      expect(calls[1]?.tenders[0]?.id).not.toBe(calls[0]?.tenders[0]?.id);
    });
  });

  describe('a goodwill refund (AC-63)', () => {
    afterEach(() => vi.useRealTimers());

    it('picks products from the search, has them quoted by the server and asks for the goodwill approval', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const { calls } = spyCreate();
      await openRefund(h);
      clickButton(h, 'Goodwill refund');
      await settleAll(h);
      expect(inRefund(h, 'refund-go')).toBeNull();

      vi.useFakeTimers();
      set(inRefund<HTMLInputElement>(h, 'search-field'), 'rice');
      await vi.advanceTimersByTimeAsync(300);
      vi.useRealTimers();
      await settleAll(h);
      inRefund(h, 'result')?.click();
      await settleAll(h);
      // Picking in this mode touches no cart.
      expect(mockSales(h)).toHaveLength(0);
      expect(refundText(h, 'goodwill-line-0')).toContain('Rice 1kg');
      set(inRefund<HTMLInputElement>(h, 'goodwill-qty-0'), '2');
      await settleAll(h);
      await go(h);
      expect(refundText(h, 'refund-quote-total')).toBe('500.00 LKR');

      await go(h);
      expect(h.text('approval-summary')).toContain('Goodwill refund');
      await approve(h);

      expect(calls[0]?.reference_sale_id).toBeNull();
      expect(calls[0]?.lines[0]).toMatchObject({ quantity: '2' });
      expect(calls[0]?.lines[0]?.refund_of_item_id).toBeUndefined();
      const approval = h.state.branches.values().next().value?.approvals[0];
      expect(approval?.data).toMatchObject({
        type: 'refund',
        reference_type: 'goodwill_refund',
        reference_id: null,
      });
      expect(refundText(h, 'refund-original-status')).toBe('Goodwill refund: no original sale');
      expect(refundText(h, 'refund-result-total')).toBe('500.00 LKR');
      expect(refunds(h)).toHaveLength(1);
    });

    it('re-sends the lost refund under the same id after the form is changed and changed back (R-2)', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const api = TestBed.inject(SalesApi);
      const { calls } = spyCreate();
      await openRefund(h);
      clickButton(h, 'Goodwill refund');
      await settleAll(h);
      vi.useFakeTimers();
      set(inRefund<HTMLInputElement>(h, 'search-field'), 'rice');
      await vi.advanceTimersByTimeAsync(300);
      vi.useRealTimers();
      await settleAll(h);
      inRefund(h, 'result')?.click();
      await settleAll(h);
      set(inRefund<HTMLInputElement>(h, 'goodwill-qty-0'), '2');
      await settleAll(h);
      await go(h);

      h.state.faults.dropAnswerOf.push('refunds.create');
      // A goodwill refund has no original: the reload after the lost answer reads the methods.
      vi.spyOn(api, 'paymentMethods').mockImplementationOnce(() =>
        throwError(() => new ApiError(0, 'Cannot reach the server.')),
      );
      await go(h);
      await approve(h);
      expect(calls).toHaveLength(1);

      const recover = async (): Promise<void> => {
        h.query<HTMLButtonElement>('[data-testid="network-banner"] button')?.click();
        await settleAll(h);
      };
      await recover();
      set(inRefund<HTMLInputElement>(h, 'goodwill-qty-0'), '3');
      await settleAll(h);
      await go(h);
      await go(h);
      expect(calls).toHaveLength(1);
      expect(refundText(h, 'refund-problem')).toContain('Send it again unchanged');

      set(inRefund<HTMLInputElement>(h, 'goodwill-qty-0'), '2');
      await settleAll(h);
      await go(h);
      await go(h);
      // Changing the quantity dropped the held approval: the goodwill refund is approved afresh.
      expect(topHeading(h)).toBe('Supervisor approval');
      await approve(h);
      expect(calls).toHaveLength(2);
      expect(calls[1]?.id).toBe(calls[0]?.id);
      expect(calls[1]?.tenders[0]?.id).toBe(calls[0]?.tenders[0]?.id);
      expect(refunds(h)).toHaveLength(1);
    });

    it('does not let a lost goodwill refund be forgotten by switching modes (W-1)', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      const { calls } = spyCreate();
      await openRefund(h);
      clickButton(h, 'Goodwill refund');
      await settleAll(h);
      vi.useFakeTimers();
      set(inRefund<HTMLInputElement>(h, 'search-field'), 'rice');
      await vi.advanceTimersByTimeAsync(300);
      vi.useRealTimers();
      await settleAll(h);
      inRefund(h, 'result')?.click();
      await settleAll(h);
      set(inRefund<HTMLInputElement>(h, 'goodwill-qty-0'), '2');
      await settleAll(h);
      await go(h);
      expect(inRefund(h, 'refund-waiting')).toBeNull();
      expect(inRefund<HTMLButtonElement>(h, 'mode-referenced')?.disabled).toBe(false);

      h.state.faults.dropAnswerOf.push('refunds.create', 'refunds.create');
      await go(h);
      await approve(h);
      expect(calls).toHaveLength(2);

      const waiting =
        'A refund is waiting for its answer; send it again unchanged or close this dialog';
      expect(refundText(h, 'refund-waiting')).toBe(waiting);
      expect(inRefund<HTMLButtonElement>(h, 'mode-referenced')?.disabled).toBe(true);
      expect(inRefund<HTMLButtonElement>(h, 'mode-goodwill')?.disabled).toBe(true);

      // The store holds the line even when something other than the buttons asks for a switch.
      const store = storeOf(h, RefundStore);
      store.setMode('referenced');
      expect(store.mode()).toBe('goodwill');
      expect(store.goodwillLines()).toHaveLength(1);
      await store.loadSale('another-sale');
      expect(store.sale()).toBeNull();
      expect(store.problem()).toBe(waiting);

      h.query<HTMLButtonElement>('[data-testid="network-banner"] button')?.click();
      await settleAll(h);
      await go(h);
      expect(h.query('pos-supervisor-dialog')).toBeNull();
      expect(calls).toHaveLength(3);
      expect(calls[2]?.id).toBe(calls[0]?.id);
      expect(calls[2]?.tenders[0]?.id).toBe(calls[0]?.tenders[0]?.id);
      expect(refunds(h)).toHaveLength(1);
      expect(refundText(h, 'refund-number')).not.toBe('');
    });

    it('does not load another sale while a referenced refund waits for its answer (W-1)', async () => {
      const h = await setupTill({ given: ['shift_open', 'completed_sale_open_shift'] });
      const api = TestBed.inject(SalesApi);
      await openRefund(h);
      await findFirstSale(h);
      await selectLine(h, 0, '1');
      await go(h);
      h.state.faults.dropAnswerOf.push('refunds.create', 'refunds.create');
      await go(h);
      await approve(h);
      expect(inRefund(h, 'refund-waiting')).not.toBeNull();

      const store = storeOf(h, RefundStore);
      const id = store.sale()?.id;
      const read = vi.spyOn(api, 'getSale');
      await store.loadSale('another-sale');
      expect(read).not.toHaveBeenCalled();
      expect(store.sale()?.id).toBe(id);
      store.setMode('goodwill');
      expect(store.mode()).toBe('referenced');
      // The same sale may be read again: that is how the till looks at it.
      await store.loadSale(id ?? 'none');
      expect(store.sale()?.id).toBe(id);
    });

    it('rejects an empty or zero goodwill quantity in the form', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await openRefund(h);
      clickButton(h, 'Goodwill refund');
      await settleAll(h);
      const store = storeOf(h, RefundStore);
      store.addGoodwill({
        id: 'p1',
        name: 'Thing',
        unit_id: 'u1',
        unit_code: 'ea',
      } as never);
      await settleAll(h);
      const quote = vi.spyOn(TestBed.inject(SalesApi), 'quoteRefund');
      for (const [text, why] of [
        ['', 'Enter a quantity'],
        ['0', 'The quantity must be more than zero'],
      ] as const) {
        set(inRefund<HTMLInputElement>(h, 'goodwill-qty-0'), text);
        await go(h);
        expect(refundText(h, 'goodwill-problem-0')).toBe(why);
      }
      expect(quote).not.toHaveBeenCalled();
    });
  });
});
