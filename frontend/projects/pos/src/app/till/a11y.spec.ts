import { SALES_MOCK_DEMO } from '@flexipos/shared';
import { Connectivity } from './state/connectivity';
import { DialogHost } from './state/dialog-host';
import { SaleStore } from './state/sale-store';
import { byId, scan, serverSales, settleFully, storeOf } from './testing/sale-screen-helpers';
import { HARNESS_TENANT_ID, TillHarness, setupTill } from './testing/till-harness';
import { ApiError } from '@flexipos/shared';
import { topHeading } from './testing/void-helpers';

/**
 * Accessibility of the till (T-037 AC-83, AC-84): every control has an accessible name, every
 * state shown with colour also has words, and the status messages sit in live regions. Contrast
 * and layout at 1024 by 768 need a rendering engine; jsdom has none (see the plan, Q-7).
 */

const CONTROLS = 'button, input:not([type="hidden"]), select, textarea, [role="button"]';

/** What a screen reader would call the element: aria-label, aria-labelledby, a label, its text, a title. */
function accessibleName(el: HTMLElement): string {
  const label = el.getAttribute('aria-label')?.trim();
  if (label) {
    return label;
  }
  const by = el.getAttribute('aria-labelledby');
  if (by) {
    const text = by
      .split(/\s+/)
      .map((id) => el.ownerDocument.getElementById(id)?.textContent?.trim() ?? '')
      .join(' ')
      .trim();
    if (text) {
      return text;
    }
  }
  if (
    el instanceof HTMLInputElement ||
    el instanceof HTMLSelectElement ||
    el instanceof HTMLTextAreaElement
  ) {
    const fromLabels = Array.from(el.labels ?? [])
      .map((l) => l.textContent?.trim() ?? '')
      .join(' ')
      .trim();
    if (fromLabels) {
      return fromLabels;
    }
  }
  if (el instanceof HTMLButtonElement || el.getAttribute('role') === 'button') {
    const text = el.textContent?.trim();
    if (text) {
      return text;
    }
  }
  return el.getAttribute('title')?.trim() ?? '';
}

/** The controls in `root` that have no accessible name, described so a failure says which. */
function unnamed(root: ParentNode): string[] {
  return Array.from(root.querySelectorAll<HTMLElement>(CONTROLS))
    .filter((el) => accessibleName(el) === '')
    .map(
      (el) =>
        `${el.tagName.toLowerCase()}${el.dataset['testid'] ? `[${el.dataset['testid']}]` : ''}`,
    );
}

async function sale(
  given: string[] = ['shift_open', 'draft_sale_with_line'],
): Promise<TillHarness> {
  const h = await setupTill({ given });
  const stored = serverSales(h, HARNESS_TENANT_ID, 'BR001')[0];
  if (stored) {
    storeOf(h, SaleStore).load(JSON.parse(JSON.stringify(stored)));
    storeOf(h, SaleStore).select(stored.lines[0]?.id ?? null);
    await h.settle();
  }
  return h;
}

describe('Accessible names (AC-83)', () => {
  it('names every control on the sale screen, line actions included', async () => {
    const h = await sale();
    expect(h.host.querySelectorAll(CONTROLS).length).toBeGreaterThan(8);
    expect(unnamed(h.host)).toEqual([]);
  });

  it('names every control on the shift prompt and the product grid error', async () => {
    const h = await setupTill();
    expect(h.query('[data-testid="shift-open-prompt"]')).not.toBeNull();
    expect(unnamed(h.host)).toEqual([]);
  });

  it('names every control of every dialog', async () => {
    const h = await sale();
    const dialogs = storeOf(h, DialogHost);
    const lineId = storeOf(h, SaleStore).lines()[0]!.id;
    const opened: [string, unknown][] = [
      ['payment', undefined],
      ['recall', undefined],
      ['customer', undefined],
      ['refund', undefined],
      ['refund', { mode: 'goodwill' }],
      ['void', undefined],
      ['shift-menu', undefined],
      ['help', undefined],
      ['find-sale', { purpose: 'void' }],
      ['line-edit', { lineId }],
      ['price-override', { lineId }],
      ['discount-picker', { lineId }],
      [
        'supervisor',
        { approvalId: 'A1', summary: { title: 'Void sale', lines: ['Total 1.00 LKR'] } },
      ],
      ['confirm', { heading: 'Sure?', message: 'Really?', confirmLabel: 'Yes', cancelLabel: 'No' }],
    ];
    let checked = 0;
    for (const [kind, data] of opened) {
      void dialogs.open(kind as never, data);
      await settleFully(h);
      const dialog = h.query('[role="dialog"]');
      expect(dialog, kind).not.toBeNull();
      expect(dialog?.getAttribute('aria-modal'), kind).toBe('true');
      expect(accessibleName(dialog as HTMLElement), kind).not.toBe('');
      expect(unnamed(dialog as HTMLElement), `${kind}: ${topHeading(h)}`).toEqual([]);
      checked += 1;
      dialogs.closeAll();
      await settleFully(h);
    }
    expect(checked).toBe(opened.length);
  });

  it('names the weight prompt', async () => {
    const h = await sale(['shift_open']);
    await scan(h, SALES_MOCK_DEMO.weighablePlu);
    await settleFully(h);
    expect(topHeading(h)).toBe('Weight');
    expect(unnamed(h.query('[role="dialog"]') as HTMLElement)).toEqual([]);
  });

  it('labels the regions of the page', async () => {
    const h = await sale();
    expect(byId(h, 'cart-lines').getAttribute('aria-label') ?? '').not.toBe('');
    expect(h.query('[data-testid="tiles"]')?.getAttribute('aria-label')).toBe('Products');
  });
});

describe('Status is never by colour alone (AC-83, AC-84)', () => {
  it('writes the network state in words, in an alert', async () => {
    const h = await sale();
    storeOf(h, Connectivity).reportFailure(new ApiError(0, 'down'));
    await h.settle();
    const banner = byId(h, 'network-banner');
    expect(banner.getAttribute('role')).toBe('alert');
    expect(banner.textContent).toContain('Cannot reach the server');
  });

  it('writes the sale state, a zero price and an overridden price in words', async () => {
    const h = await sale(['shift_open']);
    await scan(h, SALES_MOCK_DEMO.zeroPricedPlu);
    await settleFully(h);
    expect(h.text('zero-price')).toBe('Zero price');
    // A part-paid sale says so in the cart header, not just by a colour.
    const store = storeOf(h, SaleStore);
    const current = JSON.parse(JSON.stringify(store.current()));
    current.status = 'pending_payment';
    store.load(current);
    await h.settle();
    expect(h.host.querySelector('pos-cart')?.textContent).toContain('part paid');
  });

  it('announces messages through a polite and an assertive live region', async () => {
    const h = await sale(['shift_open']);
    const polite = byId(h, 'polite-region');
    const assertive = byId(h, 'assertive-region');
    expect(polite.getAttribute('aria-live')).toBe('polite');
    expect(
      assertive.getAttribute('role') === 'alert' ||
        assertive.getAttribute('aria-live') === 'assertive',
    ).toBe(true);
    await scan(h, SALES_MOCK_DEMO.barcode);
    await settleFully(h);
    expect(polite.textContent).toContain('Added Rice 1kg');
    await scan(h, 'no-such-code');
    await settleFully(h);
    expect(assertive.textContent).toContain('Code not found: no-such-code');
  });

  it('shows a message with its text and not only a colour', async () => {
    const h = await sale(['shift_open']);
    h.press('F2');
    await h.settle();
    const bar = byId(h, 'message-bar');
    expect((bar.textContent ?? '').trim()).not.toBe('');
  });
});
