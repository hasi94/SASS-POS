import { DialogHost } from '../../state/dialog-host';
import { byId, clickId, settleFully, storeOf } from '../../testing/sale-screen-helpers';
import { setupTill } from '../../testing/till-harness';
import { ConfirmDialogData } from './confirm-dialog';

const QUESTION: ConfirmDialogData = {
  heading: 'A sale is in the cart',
  message: 'Hold it first?',
  confirmLabel: 'Hold it',
  cancelLabel: 'Keep it',
};

describe('the confirm dialog (AC-54)', () => {
  it('shows the question and its two answers, with the focus on yes', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    void storeOf(h, DialogHost).open('confirm', QUESTION);
    await settleFully(h);
    expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('A sale is in the cart');
    expect(h.text('confirm-message')).toBe('Hold it first?');
    expect(byId(h, 'confirm-yes').textContent?.trim()).toBe('Hold it');
    expect(byId(h, 'confirm-no').textContent?.trim()).toBe('Keep it');
    expect(document.activeElement).toBe(byId(h, 'confirm-yes'));
  });

  it('answers true for yes, and nothing for no and for Esc', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const host = storeOf(h, DialogHost);
    const answers: unknown[] = [];
    for (const how of ['confirm-yes', 'confirm-no', 'escape']) {
      const answer = host.open('confirm', QUESTION);
      await settleFully(h);
      if (how === 'escape') {
        h.press('Escape');
        await h.settle();
      } else {
        await clickId(h, how);
      }
      answers.push(await answer);
    }
    expect(answers).toEqual([true, undefined, undefined]);
    expect(h.query('[role="dialog"]')).toBeNull();
  });

  it('still shows a heading and two answers when it is opened without a question', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    void storeOf(h, DialogHost).open('confirm');
    await settleFully(h);
    expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Are you sure?');
    expect(byId(h, 'confirm-yes').textContent?.trim()).toBe('Yes');
    expect(byId(h, 'confirm-no').textContent?.trim()).toBe('No');
  });
});
