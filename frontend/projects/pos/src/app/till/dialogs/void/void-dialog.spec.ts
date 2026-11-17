import { SaleStore } from '../../state/sale-store';
import {
  CASHIER,
  SUPERVISOR,
  clickButton,
  supervisorMessage,
  typeCredentials,
} from '../../testing/approval-helpers';
import { TillHarness, setupTill } from '../../testing/till-harness';
import {
  dialogsOf,
  findSaleRows,
  loadSale,
  mockSales,
  reasonField,
  settleAll,
  storeOf,
  topHeading,
  typeReason,
} from '../../testing/void-helpers';

async function openVoid(h: TillHarness): Promise<void> {
  h.press('F8');
  await h.settle();
}

describe('the void dialog (AC-55 to AC-58)', () => {
  describe('a sale that is not completed (AC-55)', () => {
    it('asks to find a sale when the cart holds none', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await openVoid(h);
      expect(topHeading(h)).toBe('Void a sale');
      expect(h.text('void-no-sale')).toContain('no sale in the cart');
      expect(h.query('pos-void-dialog input[name="reason"]')).toBeNull();
    });

    it('rejects an empty reason in the form and sends nothing', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      loadSale(h);
      await openVoid(h);
      expect(h.text('void-sale')).toContain('S-0001');
      clickButton(h, 'Void sale');
      await h.settle();
      expect(h.text('void-problem')).toBe('Give a reason for the void');
      typeReason(h, '   ');
      clickButton(h, 'Void sale');
      await h.settle();
      expect(h.text('void-problem')).toBe('Give a reason for the void');
      expect(mockSales(h)[0]?.data.status).toBe('draft');
    });

    it('voids a draft with a reason alone, shows the result and clears the cart', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      loadSale(h);
      await openVoid(h);
      expect(document.activeElement).toBe(reasonField(h));
      typeReason(h, 'Customer left');
      clickButton(h, 'Void sale');
      await h.settle();
      expect(mockSales(h)[0]?.data).toMatchObject({
        status: 'voided',
        void_reason: 'Customer left',
      });
      expect(h.text('void-result')).toContain('S-0001 is voided');
      expect(storeOf(h, SaleStore).current()).toBeNull();
      // No supervisor was asked, and no approval exists.
      expect(h.query('pos-supervisor-dialog')).toBeNull();
      clickButton(h, 'Close');
      await h.settle();
      expect(h.query('[role="dialog"]')).toBeNull();
    });

    it('voids a held sale and a part-paid sale, reversing the tender on the server', async () => {
      for (const given of [
        'held_from_draft',
        'held_from_pending_payment',
        'pending_payment_sale',
      ]) {
        const h = await setupTill({ given: ['shift_open', given] });
        loadSale(h);
        await openVoid(h);
        typeReason(h, 'Mistake');
        clickButton(h, 'Void sale');
        await h.settle();
        const sale = mockSales(h)[0]?.data;
        expect(sale?.status, given).toBe('voided');
        expect(storeOf(h, SaleStore).current(), given).toBeNull();
        if (given !== 'held_from_draft') {
          expect(
            sale?.tenders.map((t) => t.amount),
            given,
          ).toEqual(['100.0000', '-100.0000']);
        }
      }
    });

    it('closes on Esc and leaves the sale alone', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      loadSale(h);
      await openVoid(h);
      h.press('Escape');
      await h.settle();
      expect(h.query('[role="dialog"]')).toBeNull();
      expect(mockSales(h)[0]?.data.status).toBe('draft');
      expect(storeOf(h, SaleStore).current()).not.toBeNull();
    });

    it('shows the server refusal in words and leaves the cart and the sale unchanged (AC-58)', async () => {
      const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
      loadSale(h);
      // The sale was voided elsewhere while this till still shows it as a draft.
      const stored = mockSales(h)[0];
      if (stored) {
        stored.data.status = 'voided';
      }
      await openVoid(h);
      typeReason(h, 'Mistake');
      clickButton(h, 'Void sale');
      await h.settle();
      expect(h.text('void-problem')).toBe(
        'That sale cannot be changed that way now (status: voided)',
      );
      expect(h.query('[role="dialog"]')).not.toBeNull();
      expect(storeOf(h, SaleStore).current()?.id).toBe(stored?.data.id);
      expect(h.query('[data-testid="start-refund"]')).toBeNull();
    });
  });

  describe('a completed sale (AC-56)', () => {
    const given = ['shift_open', 'completed_sale_open_shift'];

    it("is found among this shift's sales, approved by a supervisor and voided with the approval", async () => {
      const h = await setupTill({ given });
      await openVoid(h);
      clickButton(h, 'Find sale');
      await h.settle();
      expect(topHeading(h)).toBe('Find a sale');
      expect(findSaleRows(h)[0]).toContain('INV-000001');
      h.query<HTMLButtonElement>('pos-find-sale-dialog button.row')?.click();
      await h.settle();
      expect(topHeading(h)).toBe('Void a sale');
      expect(h.text('void-sale')).toContain('INV-000001');
      expect(h.text('void-sale')).toContain('completed');
      typeReason(h, 'Customer returned the goods');
      clickButton(h, 'Void sale');
      await h.settle();

      // The supervisor is asked, with what they are approving.
      expect(topHeading(h)).toBe('Supervisor approval');
      expect(h.text('approval-summary')).toContain('Void sale INV-000001');
      expect(h.text('approval-summary')).toContain('Reason: Customer returned the goods');
      typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
      clickButton(h, 'Approve');
      await settleAll(h);

      expect(h.text('void-result')).toContain('INV-000001 is voided');
      const sale = mockSales(h)[0]?.data;
      expect(sale).toMatchObject({ status: 'voided', void_reason: 'Customer returned the goods' });
      const approval = h.state.branches.values().next().value?.approvals[0];
      expect(approval?.data).toMatchObject({
        type: 'void',
        reference_type: 'sale',
        reference_id: sale?.id,
        status: 'approved',
      });
      expect(approval?.data.consumed_at).not.toBeNull();
    });

    it('leaves the sale as it was when the supervisor is refused or cancelled (AC-58)', async () => {
      const h = await setupTill({ given });
      loadSale(h);
      await openVoid(h);
      typeReason(h, 'Customer returned the goods');
      clickButton(h, 'Void sale');
      await h.settle();

      // The cashier cannot approve their own sale: refused, the dialog stays for a hand-over.
      typeCredentials(h, CASHIER.code, CASHIER.pin);
      clickButton(h, 'Approve');
      await settleAll(h);
      expect(supervisorMessage(h)).toContain('This person cannot approve this action');
      expect(mockSales(h)[0]?.data.status).toBe('completed');

      // A wrong PIN is refused the same way for every cause.
      typeCredentials(h, SUPERVISOR.code, '0000');
      clickButton(h, 'Approve');
      await settleAll(h);
      expect(supervisorMessage(h)).toBe('Code or PIN not recognised');

      // Cancelled: back at the void dialog, which is still open with the same sale.
      h.press('Escape');
      await h.settle();
      expect(topHeading(h)).toBe('Void a sale');
      expect(h.text('void-sale')).toContain('INV-000001');
      expect(mockSales(h)[0]?.data.status).toBe('completed');
      expect(storeOf(h, SaleStore).current()?.status).toBe('completed');
    });

    it('says the approval was rejected and changes nothing', async () => {
      const h = await setupTill({ given });
      loadSale(h);
      await openVoid(h);
      typeReason(h, 'Customer returned the goods');
      clickButton(h, 'Void sale');
      await h.settle();
      typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
      clickButton(h, 'Reject');
      await settleAll(h);
      expect(h.text('void-problem')).toBe('Approval rejected: the sale is unchanged');
      expect(mockSales(h)[0]?.data.status).toBe('completed');
    });
  });

  describe('a sale of a closed shift (AC-57)', () => {
    it('explains, leaves the approval unused and offers a refund of that sale', async () => {
      const h = await setupTill({
        given: ['shift_open', 'completed_sale_closed_shift', 'shift_open'],
      });
      const sale = loadSale(h);
      await openVoid(h);
      typeReason(h, 'Customer returned the goods');
      clickButton(h, 'Void sale');
      await h.settle();
      typeCredentials(h, SUPERVISOR.code, SUPERVISOR.pin);
      clickButton(h, 'Approve');
      await settleAll(h);

      expect(h.text('void-problem')).toBe("That sale's shift is closed");
      expect(mockSales(h)[0]?.data.status).toBe('completed');
      const approval = h.state.branches.values().next().value?.approvals[0];
      expect(approval?.data.consumed_at).toBeNull();

      clickButton(h, 'Start a refund');
      await h.settle();
      expect(topHeading(h)).toBe('Refund');
      expect(dialogsOf(h).current()?.data).toEqual({ saleId: sale.id });
      expect(dialogsOf(h).dialogs().length).toBe(1);
    });
  });

  it('can be opened with the sale to void, without touching the cart', async () => {
    const h = await setupTill({ given: ['shift_open', 'held_from_draft', 'draft_sale_with_line'] });
    const held = mockSales(h)[0]?.data;
    if (!held) {
      throw new Error('The scenario made no sale.');
    }
    loadSale(h, 1);
    void dialogsOf(h).open('void', { sale: held });
    await h.settle();
    expect(h.text('void-sale')).toContain('S-0001');
    typeReason(h, 'Mistake');
    clickButton(h, 'Void sale');
    await h.settle();
    expect(mockSales(h)[0]?.data.status).toBe('voided');
    // The cart holds a different sale, which stays.
    expect(storeOf(h, SaleStore).current()?.id).toBe(mockSales(h)[1]?.data.id);
  });
});
