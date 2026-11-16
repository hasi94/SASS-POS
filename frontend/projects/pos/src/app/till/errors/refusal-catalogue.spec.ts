import { ApiError, SALES_REFUSAL_CODES } from '@flexipos/shared';
import { NETWORK_MESSAGE, REFUSAL_CATALOGUE, describeError } from './refusal-catalogue';

function refused(
  code: string,
  field: string | null = null,
  detail = 'machine detail',
  status = 403,
): ApiError {
  return new ApiError(status, 'Refused.', [{ code, field, detail }], 'req-42');
}

describe('refusal catalogue (AC-72, AC-76)', () => {
  it('has a plain message and a next step for every refusal code', () => {
    expect(Object.keys(REFUSAL_CATALOGUE).sort()).toEqual([...SALES_REFUSAL_CODES].sort());
    for (const code of SALES_REFUSAL_CODES) {
      const item = REFUSAL_CATALOGUE[code];
      const text = item.message({ fact: null });
      expect(text.trim().length).toBeGreaterThan(0);
      expect(item.next.label.trim().length).toBeGreaterThan(0);
      expect(item.next.kind).toBeTruthy();
      // Not a code token dressed up as a message.
      expect(text).not.toBe(code);
      expect(text).not.toMatch(/^[a-z]+(_[a-z]+)+$/);
    }
  });

  it('describes each code through describeError, never empty', () => {
    for (const code of SALES_REFUSAL_CODES) {
      const message = describeError(refused(`sales.${code}`));
      expect(message.kind).toBe('refusal');
      expect(message.code).toBe(code);
      expect(message.text.trim().length).toBeGreaterThan(0);
      expect(message.next).toBeDefined();
    }
  });

  describe('AC-76 table, row by row', () => {
    const rows: [string, string, string, string][] = [
      ['no_open_shift', 'Open a shift first', 'open-shift', 'sales.no_open_shift'],
      ['shift_closed', "That sale's shift is closed", 'start-refund', 'sales.shift_closed'],
      ['shift_not_open', 'The shift is not open', 'open-shift', 'sales.shift_not_open'],
      ['exceeds_balance', 'More than the balance', 'edit-amount', 'sales.exceeds_balance'],
      ['customer_required', 'Attach a customer first', 'open-customer', 'sales.customer_required'],
      [
        'sale_not_editable',
        'Payment has started: remove the tenders to edit lines',
        'reload-sale',
        'sales.sale_not_editable',
      ],
      [
        'approval_required',
        'A supervisor must approve this',
        'supervisor',
        'sales.approval_required',
      ],
      ['pin_invalid', 'Code or PIN not recognised', 'retry', 'sales.pin_invalid'],
      ['fully_refunded', 'Already fully refunded', 'none', 'sales.fully_refunded'],
      ['unresolvable_price', 'No price for this product', 'none', 'sales.unresolvable_price'],
      [
        'unit_unresolvable',
        'This unit does not apply to the product',
        'choose-unit',
        'sales.unit_unresolvable',
      ],
      [
        'usage_limit_reached',
        'That discount has been used up',
        'remove-discount',
        'sales.usage_limit_reached',
      ],
      [
        'replay_conflict',
        'This entry conflicts with an earlier one',
        'reload-sale',
        'sales.replay_conflict',
      ],
    ];
    it.each(rows)('%s', (_code, text, next, wire) => {
      const message = describeError(refused(wire));
      expect(message.text).toBe(text);
      expect(message.next?.kind).toBe(next);
    });

    it('balance_due names the remaining amount from the server', () => {
      const message = describeError(refused('sales.balance_due', 'balance_due', '1250.0000'));
      expect(message.text).toBe('Balance remaining: 1,250.00');
      expect(message.next?.kind).toBe('stay-in-payment');
    });

    it('balance_due without a fact still says something', () => {
      expect(describeError(refused('sales.balance_due')).text).toBe(
        'Balance remaining on the sale',
      );
    });

    it('over_refund names the remaining quantity or the remaining money', () => {
      const byQuantity = describeError(
        refused('sales.over_refund', 'refundable_quantity', '2.5000'),
      );
      expect(byQuantity.text).toBe('More than is left to refund: 2.5 remaining');
      expect(byQuantity.next?.kind).toBe('edit-quantity');
      const byAmount = describeError(refused('sales.over_refund', 'refundable_amount', '80.0000'));
      expect(byAmount.text).toBe('More than is left to refund: 80.00 remaining');
    });

    it('product_not_sellable names the failed fact', () => {
      const message = describeError(
        refused('sales.product_not_sellable', 'failed_fact', 'is_active'),
      );
      expect(message.text).toBe('This product cannot be sold (is_active)');
    });

    it('credit_refused names the cause the ledger gave', () => {
      const message = describeError(refused('sales.credit_refused', 'cause', 'currency_mismatch'));
      expect(message.text).toBe('Store credit refused (currency_mismatch)');
      expect(message.next?.kind).toBe('change-method');
    });

    it('numbering_refused names its cause', () => {
      const message = describeError(refused('sales.numbering_refused', 'cause', 'number_too_long'));
      expect(message.text).toContain('number_too_long');
    });

    it('invalid_transition names the status', () => {
      const message = describeError(refused('sales.invalid_transition', 'status', 'completed'));
      expect(message.text).toBe('That sale cannot be changed that way now (status: completed)');
      expect(message.next?.kind).toBe('reload-sale');
    });

    it('in_use says so, with the count when there is one', () => {
      expect(describeError(refused('sales.in_use')).text).toBe('Payment method in use');
      expect(describeError(refused('sales.in_use', 'count', '3')).text).toBe(
        'Payment method in use (3)',
      );
    });

    it('validation names the failing field and quotes the server message', () => {
      const message = describeError(
        refused('kernel.validation_failed', 'opening_cash', 'Must be a number.', 422),
      );
      expect(message.text).toBe('opening_cash: Must be a number.');
      expect(message.next?.kind).toBe('fix-field');
    });

    it('reads not_found from the kernel and from the catalogue', () => {
      expect(describeError(refused('kernel.not_found')).code).toBe('not_found');
      expect(describeError(refused('catalog.not_found')).code).toBe('not_found');
    });

    it('treats not_refundable as the same sale-not-found message as AC-59', () => {
      expect(describeError(refused('sales.not_refundable')).text).toBe(
        'Sale not found at this branch',
      );
    });
  });

  describe('the generic case (AC-73)', () => {
    it('says something went wrong, with the status and the request id', () => {
      const message = describeError(new ApiError(500, 'Server blew up.', [], 'req-9'));
      expect(message.text).toBe('Something went wrong');
      expect(message.detail).toBe('Status 500, Request req-9');
      expect(message.code).toBeNull();
    });

    it('does the same for a code that is not in the union', () => {
      const message = describeError(refused('sales.something_new', null, 'raw server words', 403));
      expect(message.text).toBe('Something went wrong');
      expect(message.detail).toBe('Status 403, Request req-42');
      expect(message.text).not.toContain('raw server words');
      expect(message.detail).not.toContain('raw server words');
    });

    it('leaves out the request id when there is none', () => {
      const message = describeError(new ApiError(502, 'Bad gateway.'));
      expect(message.detail).toBe('Status 502');
    });

    it('copes with something that is not an ApiError at all', () => {
      const message = describeError(new Error('stack trace words'));
      expect(message.text).toBe('Something went wrong');
      expect(message.text).not.toContain('stack trace words');
      expect(message.detail).toBeUndefined();
    });

    it('never gives an empty message', () => {
      for (const err of [new ApiError(500, ''), new ApiError(0, ''), null, undefined, 'x', 42]) {
        expect(describeError(err).text.trim().length).toBeGreaterThan(0);
      }
    });
  });

  it('turns a status 0 error into the network message with a retry (AC-74)', () => {
    const message = describeError(new ApiError(0, 'Cannot reach the server.'));
    expect(message.text).toBe(NETWORK_MESSAGE);
    expect(message.text).toBe('Cannot reach the server: sale actions are paused');
    expect(message.next?.kind).toBe('retry');
  });
});
