import { ApiError, asMoney } from '@flexipos/shared';
import { throwError } from 'rxjs';
import { Connectivity } from '../../state/connectivity';
import { SaleStore } from '../../state/sale-store';
import { ShiftStore } from '../../state/shift-store';
import {
  api,
  applyThenLose,
  branchOf,
  chooseMethod,
  gateCall,
  lost,
  openPayment,
  payCash,
  refusal,
  serverSale,
  submitPayment,
  typeAmount,
  typeReference,
} from '../../testing/payment-helpers';
import { byId, clickId, settleFully, storeOf } from '../../testing/sale-screen-helpers';
import { TillHarness, setupTill } from '../../testing/till-harness';

const ULID = /^[0-9A-HJKMNP-TV-Z]{26}$/;

const amountField = (h: TillHarness) => byId<HTMLInputElement>(h, 'pay-amount');
const problem = (h: TillHarness) => h.text('payment-problem');
const methodCodes = (h: TillHarness): string[] =>
  Array.from(h.host.querySelectorAll('[data-testid^="method-"]')).map((b) =>
    (b.getAttribute('data-testid') ?? '').replace('method-', ''),
  );

describe('the payment dialog (AC-41 to AC-46)', () => {
  describe('what it offers (AC-40, AC-41, AC-42)', () => {
    it('lists the server methods minus loyalty and voucher, with the total and the balance', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Payment');
      expect(methodCodes(h)).toEqual(['CASH', 'CARD', 'CREDIT']);
      expect(h.text('pay-total')).toBe('250.00 LKR');
      expect(h.text('pay-balance')).toBe('250.00 LKR');
      expect(byId(h, 'method-CASH').getAttribute('aria-pressed')).toBe('true');
      expect(document.activeElement).toBe(amountField(h));
    });

    it('shows store credit disabled with the reason when no customer is attached (AC-40)', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      expect(byId<HTMLButtonElement>(h, 'method-CREDIT').disabled).toBe(true);
      expect(h.text('credit-reason')).toBe('Attach a customer to use store credit');
    });

    it('lets the cashier choose the customer from the payment dialog, then use store credit', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      await clickId(h, 'pay-customer');
      expect(h.host.querySelectorAll('[role="dialog"]')).toHaveLength(2);
      const query = h.query<HTMLInputElement>('pos-customer-dialog input[name="q"]')!;
      query.value = 'CUS-0001';
      query.dispatchEvent(new Event('input', { bubbles: true }));
      query.closest('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
      await settleFully(h);
      await clickId(h, 'customer-result');
      await settleFully(h);
      expect(h.host.querySelectorAll('[role="dialog"]')).toHaveLength(1);
      expect(byId<HTMLButtonElement>(h, 'method-CREDIT').disabled).toBe(false);
      expect(h.query('[data-testid="credit-reason"]')).toBeNull();
    });

    it('enables store credit when the sale has a customer, and takes it from their balance', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_customer_line'] });
      await openPayment(h);
      expect(byId<HTMLButtonElement>(h, 'method-CREDIT').disabled).toBe(false);
      await chooseMethod(h, 'CREDIT');
      await submitPayment(h);
      expect(serverSale(h).tenders[0]?.method_type).toBe('credit');
      expect(h.text('pay-balance')).toBe('0.00 LKR');
    });

    it('shows the same words when the server refuses customer_required (AC-40)', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      // This till's copy of the sale has a customer the server's does not: the server refuses.
      const copy = JSON.parse(JSON.stringify(serverSale(h)));
      const seeded = h.state.seed.customers[0]!;
      copy.customer = {
        id: seeded.id,
        code: seeded.code,
        name: seeded.name,
        phone: seeded.phone,
        credit_balance: seeded.credit_balance,
      };
      storeOf(h, SaleStore).load(copy);
      await openPayment(h);
      await chooseMethod(h, 'CREDIT');
      await submitPayment(h);
      expect(problem(h)).toBe('Attach a customer to use store credit');
      expect(serverSale(h).tenders).toHaveLength(0);
    });

    it('defaults the amount to the server balance_due string, copied and editable', async () => {
      const h = await setupTill({ given: ['shift_open', 'pending_payment_sale'] });
      await openPayment(h);
      expect(amountField(h).value).toBe('150.0000');
      typeAmount(h, '75');
      await h.settle();
      expect(amountField(h).value).toBe('75');
    });

    it('asks for a reference where the method requires one, and refuses an empty one', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      expect(h.query('[data-testid="pay-reference"]')).toBeNull();
      await chooseMethod(h, 'CARD');
      expect(h.query('[data-testid="pay-reference"]')).not.toBeNull();
      const add = vi.spyOn(api(), 'addTender');
      await submitPayment(h);
      expect(problem(h)).toBe('Reference is required for this payment method');
      expect(add).not.toHaveBeenCalled();
    });

    it.each([
      ['', 'Amount is required'],
      ['abc', 'Amount must be a number such as 12.50'],
      ['0', 'Amount must be more than zero'],
      ['-5', 'Amount cannot be negative'],
      ['1.23456', 'Amount can have at most 4 decimal places'],
    ])('checks the amount %j in the form before anything is sent', async (text, message) => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      const add = vi.spyOn(api(), 'addTender');
      typeAmount(h, text);
      await submitPayment(h);
      expect(problem(h)).toBe(message);
      expect(add).not.toHaveBeenCalled();
    });
  });

  describe('a tender (AC-43, AC-44, AC-45)', () => {
    it('sends only an id, the method and the typed amount, and shows the server figures', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      const add = vi.spyOn(api(), 'addTender');
      await chooseMethod(h, 'CASH');
      typeAmount(h, '100');
      await submitPayment(h);
      const [saleId, body] = add.mock.calls[0]!;
      expect(saleId).toBe(serverSale(h).id);
      expect(Object.keys(body).sort()).toEqual(['id', 'payment_method_id', 'tendered']);
      expect(body.id).toMatch(ULID);
      expect(body.tendered).toBe('100');
      expect(body.payment_method_id).toBe(h.state.seed.paymentMethods[0]?.id);
      expect(h.text('pay-paid')).toBe('100.00 LKR');
      expect(h.text('pay-balance')).toBe('150.00 LKR');
      expect(h.text('tender-method')).toBe('CASH');
      expect(h.text('tender-amount')).toBe('100.00 LKR');
      expect(storeOf(h, SaleStore).status()).toBe('pending_payment');
    });

    it('sends a card reference with the tender', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      const add = vi.spyOn(api(), 'addTender');
      await chooseMethod(h, 'CARD');
      typeReference(h, 'AUTH-77');
      await submitPayment(h);
      expect(add.mock.calls[0]?.[1].reference).toBe('AUTH-77');
      expect(serverSale(h).tenders[0]?.reference).toBe('AUTH-77');
    });

    it('shows the server change_given as "Change" after a cash over-tender (AC-44, P-2)', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      await payCash(h, '300');
      expect(h.text('payment-change')).toBe('Change 50.00 LKR');
      expect(serverSale(h).tenders[0]?.change_given).toBe('50.0000');
      expect(h.text('pay-balance')).toBe('0.00 LKR');
    });

    it('shows "Amount is more than the balance" for a method that gives no change (AC-44)', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      await chooseMethod(h, 'CARD');
      typeAmount(h, '300');
      typeReference(h, 'AUTH-1');
      await submitPayment(h);
      expect(problem(h)).toBe('Amount is more than the balance');
      expect(serverSale(h).tenders).toHaveLength(0);
      expect(h.query('[data-testid="payment-change"]')).toBeNull();
    });

    it('stays open after a part payment, shows pending payment and takes a second method (AC-45)', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      await payCash(h, '100');
      expect(h.query('[role="dialog"]')).not.toBeNull();
      expect(storeOf(h, SaleStore).status()).toBe('pending_payment');
      expect(h.text('pay-balance')).toBe('150.00 LKR');
      expect(amountField(h).value).toBe('150.0000');
      expect(document.activeElement).toBe(amountField(h));
      await chooseMethod(h, 'CARD');
      typeReference(h, 'AUTH-2');
      await submitPayment(h);
      expect(serverSale(h).tenders.map((t) => t.method_code)).toEqual(['CASH', 'CARD']);
      expect(h.text('pay-balance')).toBe('0.00 LKR');
      expect(h.query('[data-testid="pay-complete"]')).not.toBeNull();
    });

    it('shows the server refusal in place and sends nothing more when the sale cannot take it', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      serverSale(h).status = 'voided';
      await payCash(h, '100');
      expect(problem(h)).toBe('That sale cannot be changed that way now (status: voided)');
      expect(h.query('[role="dialog"]')).not.toBeNull();
    });

    it('goes back to the shift prompt when the shift is gone, keeping the cart (AC-15)', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      vi.spyOn(api(), 'addTender').mockImplementation(() =>
        throwError(() => refusal('no_open_shift')),
      );
      await payCash(h, '100');
      expect(h.query('[role="dialog"]')).toBeNull();
      expect(storeOf(h, ShiftStore).shiftOpen()).toBe(false);
      expect(h.query('pos-shift-open-prompt')).not.toBeNull();
      expect(storeOf(h, SaleStore).lineCount()).toBe(1);
    });
  });

  describe('removing a tender (AC-46)', () => {
    it('removes it while the sale is pending_payment and takes the figures from the response', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      await payCash(h, '100');
      await payCash(h, '50');
      expect(h.host.querySelectorAll('[data-testid="tender"]')).toHaveLength(2);
      const remove = vi.spyOn(api(), 'removeTender');
      await clickId(h, 'tender-remove');
      await settleFully(h);
      expect(remove).toHaveBeenCalledWith(serverSale(h).id, expect.any(String));
      expect(h.host.querySelectorAll('[data-testid="tender"]')).toHaveLength(1);
      // The first tender (100) went, the second (50) stays.
      expect(h.text('tender-amount')).toBe('50.00 LKR');
      expect(h.text('pay-balance')).toBe('200.00 LKR');
      expect(storeOf(h, SaleStore).status()).toBe('pending_payment');
    });

    it('returns the sale to draft when the last tender goes, as the server reports', async () => {
      const h = await setupTill({ given: ['shift_open', 'pending_payment_sale'] });
      await openPayment(h);
      await clickId(h, 'tender-remove');
      await settleFully(h);
      expect(serverSale(h).status).toBe('draft');
      expect(storeOf(h, SaleStore).status()).toBe('draft');
      expect(h.query('[data-testid="tender-remove"]')).toBeNull();
      expect(h.text('pay-balance')).toBe('250.00 LKR');
    });

    it('reloads the sale when the tender was already gone on the server', async () => {
      const h = await setupTill({ given: ['shift_open', 'pending_payment_sale'] });
      await openPayment(h);
      const stored = serverSale(h);
      stored.tenders.splice(0, 1);
      stored.status = 'draft';
      stored.paid_total = asMoney('0.0000');
      stored.balance_due = asMoney('250.0000');
      await clickId(h, 'tender-remove');
      await settleFully(h);
      expect(problem(h)).toBe('That record was not found');
      expect(h.query('[data-testid="tender"]')).toBeNull();
      expect(storeOf(h, SaleStore).status()).toBe('draft');
    });
  });

  describe('a submit in flight (AC-50)', () => {
    it('turns the submit control off and ignores a second submit, so one tender is sent', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      const gate = gateCall(api().addTender.bind(api()));
      const add = vi.spyOn(api(), 'addTender').mockImplementation(gate.wrapped);
      typeAmount(h, '100');
      byId(h, 'pay-amount')
        .closest('form')
        ?.dispatchEvent(new Event('submit', { cancelable: true }));
      byId(h, 'pay-amount')
        .closest('form')
        ?.dispatchEvent(new Event('submit', { cancelable: true }));
      await settleFully(h);
      expect(add).toHaveBeenCalledTimes(1);
      expect(byId<HTMLButtonElement>(h, 'pay-submit').disabled).toBe(true);
      gate.release();
      await settleFully(h);
      expect(serverSale(h).tenders).toHaveLength(1);
      expect(byId<HTMLButtonElement>(h, 'pay-submit').disabled).toBe(false);
    });

    it('does the same for Complete', async () => {
      const h = await setupTill({ given: ['shift_open', 'paid_sale'] });
      await openPayment(h);
      const gate = gateCall(api().completeSale.bind(api()));
      const complete = vi.spyOn(api(), 'completeSale').mockImplementation(gate.wrapped);
      byId(h, 'pay-complete').click();
      byId(h, 'pay-complete').click();
      await settleFully(h);
      expect(complete).toHaveBeenCalledTimes(1);
      expect(byId<HTMLButtonElement>(h, 'pay-complete').disabled).toBe(true);
      gate.release();
      await settleFully(h);
      expect(serverSale(h).status).toBe('completed');
    });

    it('turns every write control off while the till cannot reach the server (AC-74)', async () => {
      const h = await setupTill({ given: ['shift_open', 'pending_payment_sale'] });
      await openPayment(h);
      storeOf(h, Connectivity).reportFailure(lost());
      await settleFully(h);
      expect(byId<HTMLButtonElement>(h, 'pay-submit').disabled).toBe(true);
      expect(byId<HTMLButtonElement>(h, 'tender-remove').disabled).toBe(true);
    });
  });

  describe('a lost answer (AC-49, D-11)', () => {
    it('reads the sale back and shows a tender that was recorded, sending nothing again', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      h.state.faults.dropAnswerOf.push('sales.add-tender');
      await payCash(h, '100');
      expect(problem(h)).toBe('Cannot reach the server: sale actions are paused');
      expect(serverSale(h).tenders).toHaveLength(1);
      expect(h.text('pay-balance')).toBe('250.00 LKR');
      await storeOf(h, Connectivity).retryNow();
      await settleFully(h);
      const add = vi.spyOn(api(), 'addTender');
      const read = vi.spyOn(api(), 'getSale');
      await submitPayment(h);
      expect(read).toHaveBeenCalledTimes(1);
      expect(add).not.toHaveBeenCalled();
      expect(serverSale(h).tenders).toHaveLength(1);
      expect(h.text('pay-balance')).toBe('150.00 LKR');
      expect(h.text('tender-amount')).toBe('100.00 LKR');
    });

    it('reads the sale back first even when the cashier changed the amount after a lost answer (W-1)', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      h.state.faults.dropAnswerOf.push('sales.add-tender');
      await payCash(h, '100');
      expect(serverSale(h).tenders).toHaveLength(1);
      await storeOf(h, Connectivity).retryNow();
      await settleFully(h);
      const add = vi.spyOn(api(), 'addTender');
      const read = vi.spyOn(api(), 'getSale');
      typeAmount(h, '50');
      await submitPayment(h);
      expect(read).toHaveBeenCalledTimes(1);
      expect(add).not.toHaveBeenCalled();
      expect(serverSale(h).tenders).toHaveLength(1);
      expect(h.text('pay-balance')).toBe('150.00 LKR');
    });

    it('sends the changed amount once the read-back shows the earlier tender was not recorded (W-1)', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      h.state.faults.unreachable = true;
      await payCash(h, '100');
      h.state.faults.unreachable = false;
      await storeOf(h, Connectivity).retryNow();
      await settleFully(h);
      const add = vi.spyOn(api(), 'addTender');
      const read = vi.spyOn(api(), 'getSale');
      typeAmount(h, '50');
      await submitPayment(h);
      expect(read).toHaveBeenCalledTimes(1);
      expect(add).toHaveBeenCalledTimes(1);
      expect(serverSale(h).tenders).toHaveLength(1);
      expect(h.text('pay-balance')).toBe('200.00 LKR');
    });

    it('re-sends the same id and payload when the tender never arrived', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      const add = vi.spyOn(api(), 'addTender');
      h.state.faults.unreachable = true;
      await payCash(h, '100');
      expect(serverSale(h).tenders).toHaveLength(0);
      h.state.faults.unreachable = false;
      await storeOf(h, Connectivity).retryNow();
      await settleFully(h);
      await submitPayment(h);
      expect(add).toHaveBeenCalledTimes(2);
      expect(add.mock.calls[1]?.[1]).toEqual(add.mock.calls[0]?.[1]);
      expect(serverSale(h).tenders).toHaveLength(1);
      expect(serverSale(h).tenders[0]?.id).toBe(add.mock.calls[0]?.[1].id);
    });

    it('uses a new id for a different submission', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      const add = vi.spyOn(api(), 'addTender');
      await payCash(h, '100');
      await payCash(h, '100');
      expect(add.mock.calls[0]?.[1].id).not.toBe(add.mock.calls[1]?.[1].id);
      expect(serverSale(h).tenders).toHaveLength(2);
    });

    it('keeps the id after an unreadable server error, since the tender may have been recorded', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      const add = vi.spyOn(api(), 'addTender');
      add.mockImplementationOnce(() => throwError(() => new ApiError(500, 'boom')));
      await payCash(h, '100');
      await submitPayment(h);
      expect(add.mock.calls[1]?.[1].id).toBe(add.mock.calls[0]?.[1].id);
      expect(serverSale(h).tenders).toHaveLength(1);
    });

    it('does not reuse the id after a refusal, which recorded nothing', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      await openPayment(h);
      const add = vi.spyOn(api(), 'addTender');
      await chooseMethod(h, 'CARD');
      typeAmount(h, '300');
      typeReference(h, 'AUTH-1');
      await submitPayment(h);
      await submitPayment(h);
      expect(add.mock.calls[0]?.[1].id).not.toBe(add.mock.calls[1]?.[1].id);
    });
  });
});

describe('completing the sale from the dialog (AC-47, AC-48)', () => {
  it('shows Complete once nothing is owed and completes with the server call', async () => {
    const h = await setupTill({ given: ['shift_open', 'paid_sale'] });
    await openPayment(h);
    expect(h.query('[data-testid="pay-amount"]')).toBeNull();
    expect(document.activeElement).toBe(byId(h, 'pay-complete'));
    await clickId(h, 'pay-complete');
    await settleFully(h);
    expect(serverSale(h).status).toBe('completed');
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(storeOf(h, SaleStore).current()).toBeNull();
  });

  it('completes a zero-total sale that has a line without any tender', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_zero_priced_line'] });
    await openPayment(h);
    await clickId(h, 'pay-complete');
    await settleFully(h);
    expect(serverSale(h).status).toBe('completed');
    expect(serverSale(h).tenders).toHaveLength(0);
  });

  it('keeps the dialog open and shows the remaining amount when completion is refused balance_due', async () => {
    const h = await setupTill({ given: ['shift_open', 'paid_sale'] });
    await openPayment(h);
    // The server's balance moved after this till loaded the sale.
    serverSale(h).balance_due = asMoney('80.0000');
    serverSale(h).paid_total = asMoney('170.0000');
    await clickId(h, 'pay-complete');
    await settleFully(h);
    expect(problem(h)).toBe('Balance remaining: 80.00');
    expect(h.query('[role="dialog"]')).not.toBeNull();
    expect(h.text('pay-balance')).toBe('80.00 LKR');
    expect(h.query('[data-testid="pay-amount"]')).not.toBeNull();
    expect(serverSale(h).status).not.toBe('completed');
  });

  it('names the cause when store credit is refused, and lets the cashier fix the tenders and retry', async () => {
    const h = await setupTill({ given: ['shift_open', 'credit_short_sale'] });
    await openPayment(h);
    await clickId(h, 'pay-complete');
    await settleFully(h);
    expect(problem(h)).toBe('Store credit refused (insufficient_credit)');
    expect(storeOf(h, SaleStore).status()).toBe('pending_payment');
    await clickId(h, 'tender-remove');
    await settleFully(h);
    await payCash(h, '250');
    await clickId(h, 'pay-complete');
    await settleFully(h);
    expect(serverSale(h).status).toBe('completed');
  });

  it('says the discount has been used up when its limit is reached', async () => {
    const h = await setupTill({ given: ['shift_open', 'used_up_discount_sale'] });
    await openPayment(h);
    await clickId(h, 'pay-complete');
    await settleFully(h);
    expect(problem(h)).toBe('That discount has been used up');
    expect(storeOf(h, SaleStore).status()).toBe('pending_payment');
    expect(h.query('[role="dialog"]')).not.toBeNull();
  });

  it('names the cause when the sale number cannot be allocated, and a retry then completes', async () => {
    const h = await setupTill({ given: ['shift_open', 'paid_sale'] });
    await openPayment(h);
    const complete = vi
      .spyOn(api(), 'completeSale')
      .mockImplementationOnce(() =>
        throwError(() =>
          refusal('numbering_refused', { field: 'cause', detail: 'sequence_locked' }),
        ),
      );
    await clickId(h, 'pay-complete');
    await settleFully(h);
    expect(problem(h)).toBe('The sale number could not be allocated (sequence_locked)');
    expect(storeOf(h, SaleStore).status()).toBe('pending_payment');
    await clickId(h, 'pay-complete');
    await settleFully(h);
    expect(complete).toHaveBeenCalledTimes(2);
    expect(serverSale(h).status).toBe('completed');
  });

  it('shows the completed sale when the answer to Complete was lost after the server finished it', async () => {
    const h = await setupTill({ given: ['shift_open', 'paid_sale'] });
    await openPayment(h);
    const real = api().completeSale.bind(api());
    vi.spyOn(api(), 'completeSale').mockImplementation(applyThenLose(real));
    await clickId(h, 'pay-complete');
    await settleFully(h);
    expect(serverSale(h).status).toBe('completed');
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(h.text('completion-number')).toContain('INV-000001');
  });

  it('is not offered for a sale with no lines (AC-38)', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    h.press('F2');
    await settleFully(h);
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(branchOf(h).sales).toHaveLength(0);
  });
});
