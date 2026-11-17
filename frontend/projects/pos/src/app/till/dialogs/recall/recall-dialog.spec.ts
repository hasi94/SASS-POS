import { Sale } from '@flexipos/shared';
import { throwError } from 'rxjs';
import { RECALL_PAGE_SIZE, SaleStore } from '../../state/sale-store';
import { ShiftStore } from '../../state/shift-store';
import { api, branchOf, recallRows, refusal, serverSale } from '../../testing/payment-helpers';
import { byId, clearNotes, clickId, settleFully, storeOf } from '../../testing/sale-screen-helpers';
import { TillHarness, setupTill } from '../../testing/till-harness';

const THREE_HELD = [
  'shift_open',
  'held_from_draft',
  'held_sale_other_terminal',
  'held_from_pending_payment',
];

async function openRecall(h: TillHarness): Promise<void> {
  h.press('F4');
  await settleFully(h);
}

const selectedIndex = (h: TillHarness): number =>
  Array.from(h.host.querySelectorAll('[data-testid="recall-row"]')).findIndex(
    (r) => r.getAttribute('aria-selected') === 'true',
  );

const dialogCount = (h: TillHarness): number => h.host.querySelectorAll('[role="dialog"]').length;

describe('the recall dialog (AC-52 to AC-54)', () => {
  describe('the list (AC-52)', () => {
    it('lists the held sales of the whole branch from the server, newest first', async () => {
      const h = await setupTill({ given: THREE_HELD });
      const list = vi.spyOn(api(), 'listSales');
      await openRecall(h);
      expect(list.mock.calls[0]?.[0]).toEqual({
        status: ['held'],
        scope: 'branch',
        limit: RECALL_PAGE_SIZE,
      });
      const rows = recallRows(h);
      expect(rows).toHaveLength(3);
      // The sale made at another terminal is there too, with who held it and its total.
      expect(rows[1]).toContain('by Demo Owner');
      expect(rows[1]).toContain('500.00 LKR');
      expect(rows[1]).toContain(serverSale(h, 1).reference);
      expect(rows[2]).toContain(serverSale(h, 0).reference);
      expect(rows[0]).toContain('part paid');
      expect(document.activeElement).toBe(byId(h, 'recall-list'));
    });

    it('shows the customer where the sale has one', async () => {
      const h = await setupTill({ given: THREE_HELD });
      const seeded = h.state.seed.customers[0]!;
      serverSale(h, 0).customer = {
        id: seeded.id,
        code: seeded.code,
        name: seeded.name,
        phone: seeded.phone,
        credit_balance: seeded.credit_balance,
      } as Sale['customer'];
      await openRecall(h);
      expect(recallRows(h)[2]).toContain('Nimal Perera');
      expect(recallRows(h)[1]).not.toContain('Nimal Perera');
    });

    it('says so when nothing is held', async () => {
      const h = await setupTill({ given: ['shift_open'] });
      await openRecall(h);
      expect(h.text('recall-empty')).toBe('There are no held sales at this branch.');
      expect((byId(h, 'recall-go') as HTMLButtonElement).disabled).toBe(true);
    });

    it('is paginated: a page of ten, then the rest on request', async () => {
      const h = await setupTill({ given: ['shift_open', 'held_from_draft'] });
      const branch = branchOf(h);
      const template = branch.sales[0]!;
      for (let n = 1; n < 12; n++) {
        const copy = JSON.parse(JSON.stringify(template.data)) as Sale;
        copy.id = `${copy.id.slice(0, -2)}${String(n).padStart(2, '0')}`;
        copy.reference = `S-90${String(n).padStart(2, '0')}`;
        branch.sales.push({ terminalId: template.terminalId, data: copy });
      }
      await openRecall(h);
      expect(recallRows(h)).toHaveLength(RECALL_PAGE_SIZE);
      await clickId(h, 'recall-more');
      await settleFully(h);
      expect(recallRows(h)).toHaveLength(12);
      expect(h.query('[data-testid="recall-more"]')).toBeNull();
    });

    it('shows the server refusal when the list cannot be read', async () => {
      const h = await setupTill({ given: THREE_HELD });
      vi.spyOn(api(), 'listSales').mockImplementation(() =>
        throwError(() => refusal('validation')),
      );
      await openRecall(h);
      expect(h.text('recall-load-problem')).toBe('The request was not valid');
    });
  });

  describe('keys and recalling (AC-52, AC-53)', () => {
    it('moves with Up and Down, clamped at the ends, and recalls the selected sale with Enter', async () => {
      const h = await setupTill({ given: THREE_HELD });
      await openRecall(h);
      expect(selectedIndex(h)).toBe(0);
      h.press('ArrowUp');
      await h.settle();
      expect(selectedIndex(h)).toBe(0);
      h.press('ArrowDown');
      await h.settle();
      expect(selectedIndex(h)).toBe(1);
      h.press('Enter');
      await settleFully(h);
      const wanted = serverSale(h, 1);
      expect(storeOf(h, SaleStore).current()?.id).toBe(wanted.id);
      expect(storeOf(h, SaleStore).status()).toBe('draft');
      expect(wanted.status).toBe('draft');
      expect(dialogCount(h)).toBe(0);
      expect(serverSale(h, 0).status).toBe('held');
    });

    it('brings a sale held from pending_payment back as pending_payment with its lines locked', async () => {
      const h = await setupTill({ given: THREE_HELD });
      await openRecall(h);
      h.press('Enter');
      await settleFully(h);
      const cart = storeOf(h, SaleStore);
      expect(cart.status()).toBe('pending_payment');
      expect(cart.editable()).toBe(false);
      expect(cart.lockReason()).toBe('Payment has started: remove the tenders to edit lines');
      expect(h.text('total-balance')).toBe('150.00 LKR');
    });

    it('recalls by clicking Recall too', async () => {
      const h = await setupTill({ given: ['shift_open', 'held_from_draft'] });
      await openRecall(h);
      await clickId(h, 'recall-go');
      await settleFully(h);
      expect(storeOf(h, SaleStore).current()?.id).toBe(serverSale(h).id);
    });

    it('closes on Esc and changes nothing', async () => {
      const h = await setupTill({ given: THREE_HELD });
      const recall = vi.spyOn(api(), 'recallSale');
      await openRecall(h);
      h.press('Escape');
      await h.settle();
      expect(dialogCount(h)).toBe(0);
      expect(recall).not.toHaveBeenCalled();
      expect(storeOf(h, SaleStore).current()).toBeNull();
    });

    it('is not offered without an open shift', async () => {
      const h = await setupTill();
      h.press('F4');
      await settleFully(h);
      expect(dialogCount(h)).toBe(0);
    });

    it('hands over to the shift prompt when the server says there is no open shift (AC-15)', async () => {
      const h = await setupTill({ given: THREE_HELD });
      await openRecall(h);
      vi.spyOn(api(), 'recallSale').mockImplementation(() =>
        throwError(() => refusal('no_open_shift')),
      );
      h.press('Enter');
      await settleFully(h);
      expect(dialogCount(h)).toBe(0);
      expect(storeOf(h, ShiftStore).shiftOpen()).toBe(false);
      expect(h.query('pos-shift-open-prompt')).not.toBeNull();
    });

    it('shows a refusal in the dialog and reads the list again when the sale was taken meanwhile', async () => {
      const h = await setupTill({ given: THREE_HELD });
      await openRecall(h);
      serverSale(h, 2).status = 'draft';
      serverSale(h, 2).held_from_status = null;
      await clickId(h, 'recall-go');
      await settleFully(h);
      expect(h.text('recall-problem')).toContain('That sale cannot be changed that way now');
      expect(dialogCount(h)).toBe(1);
      expect(recallRows(h)).toHaveLength(2);
      expect(storeOf(h, SaleStore).current()).toBeNull();
    });
  });

  describe('a sale already in the cart (AC-54)', () => {
    const given = ['shift_open', 'held_from_draft', 'draft_sale_with_five_rice'];

    it('asks, and keeping the sale changes nothing', async () => {
      const h = await setupTill({ given });
      const inCart = storeOf(h, SaleStore).current();
      expect(inCart?.status).toBe('draft');
      await openRecall(h);
      const recall = vi.spyOn(api(), 'recallSale');
      const hold = vi.spyOn(api(), 'holdSale');
      await clickId(h, 'recall-go');
      await settleFully(h);
      expect(dialogCount(h)).toBe(2);
      expect(h.text('confirm-message')).toContain('Hold the sale in the cart');
      await clickId(h, 'confirm-no');
      await settleFully(h);
      expect(dialogCount(h)).toBe(1);
      expect(storeOf(h, SaleStore).current()?.id).toBe(inCart?.id);
      expect(recall).not.toHaveBeenCalled();
      expect(hold).not.toHaveBeenCalled();
      expect(serverSale(h, 0).status).toBe('held');
    });

    it('treats Esc on the question as keeping the sale', async () => {
      const h = await setupTill({ given });
      await openRecall(h);
      const hold = vi.spyOn(api(), 'holdSale');
      await clickId(h, 'recall-go');
      await settleFully(h);
      h.press('Escape');
      await settleFully(h);
      expect(dialogCount(h)).toBe(1);
      expect(hold).not.toHaveBeenCalled();
    });

    it('holds the sale in the cart first when the cashier says so, then recalls', async () => {
      const h = await setupTill({ given });
      const before = storeOf(h, SaleStore).current()!;
      await openRecall(h);
      await clickId(h, 'recall-go');
      await settleFully(h);
      expect(document.activeElement).toBe(byId(h, 'confirm-yes'));
      await clickId(h, 'confirm-yes');
      await settleFully(h);
      const held = branchOf(h).sales.find((s) => s.data.id === before.id)?.data;
      expect(held?.status).toBe('held');
      expect(held?.held_from_status).toBe('draft');
      expect(storeOf(h, SaleStore).current()?.id).toBe(serverSale(h, 0).id);
      expect(storeOf(h, SaleStore).status()).toBe('draft');
      expect(dialogCount(h)).toBe(0);
    });

    it('does not recall when the hold is refused, and the sale stays in the cart', async () => {
      const h = await setupTill({ given });
      const before = storeOf(h, SaleStore).current()!;
      await openRecall(h);
      vi.spyOn(api(), 'holdSale').mockImplementation(() =>
        throwError(() => refusal('invalid_transition')),
      );
      const recall = vi.spyOn(api(), 'recallSale');
      await clickId(h, 'recall-go');
      await settleFully(h);
      await clickId(h, 'confirm-yes');
      await settleFully(h);
      expect(recall).not.toHaveBeenCalled();
      expect(storeOf(h, SaleStore).current()?.id).toBe(before.id);
      expect(serverSale(h, 0).status).toBe('held');
    });

    it('asks nothing of an empty cart, and leaves its empty draft on the server', async () => {
      const h = await setupTill({ given: ['shift_open', 'held_from_draft', 'draft_sale_empty'] });
      const empty = storeOf(h, SaleStore).current()!;
      expect(storeOf(h, SaleStore).lineCount()).toBe(0);
      await openRecall(h);
      await clickId(h, 'recall-go');
      await settleFully(h);
      expect(dialogCount(h)).toBe(0);
      expect(storeOf(h, SaleStore).current()?.id).toBe(serverSale(h, 0).id);
      expect(branchOf(h).sales.find((s) => s.data.id === empty.id)?.data.status).toBe('draft');
    });

    it('never replaces a sale that has lines, even when asked directly', async () => {
      const h = await setupTill({ given });
      const cart = storeOf(h, SaleStore);
      const before = cart.current()!;
      const recall = vi.spyOn(api(), 'recallSale');
      clearNotes(h);
      const outcome = await cart.recall(serverSale(h, 0).id);
      await settleFully(h);
      expect(outcome.ok).toBe(false);
      expect(recall).not.toHaveBeenCalled();
      expect(cart.current()?.id).toBe(before.id);
      expect(h.text('message-bar')).toContain('Hold or finish the sale in the cart first');
    });
  });
});
