import { SALES_MOCK_DEMO } from '@flexipos/shared';
import { SaleStore } from '../../state/sale-store';
import { api, branchOf, serverSale } from '../../testing/payment-helpers';
import { byId, clickId, settleFully, storeOf, typeInto } from '../../testing/sale-screen-helpers';
import { TillHarness, setupTill } from '../../testing/till-harness';

async function openCustomer(h: TillHarness): Promise<void> {
  h.press('F6');
  await settleFully(h);
}

async function search(h: TillHarness, text: string): Promise<void> {
  typeInto(byId<HTMLInputElement>(h, 'customer-query'), text);
  byId(h, 'customer-query')
    .closest('form')
    ?.dispatchEvent(new Event('submit', { cancelable: true }));
  await settleFully(h);
}

const results = (h: TillHarness): string[] =>
  Array.from(h.host.querySelectorAll('[data-testid="customer-result"]')).map((r) =>
    (r.textContent ?? '').replace(/\s+/g, ' ').trim(),
  );

describe('the customer dialog (AC-39, AC-40)', () => {
  it('opens on F6 with the focus in the search field', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    await openCustomer(h);
    expect(h.query('[role="dialog"] h2')?.textContent?.trim()).toBe('Customer');
    expect(document.activeElement).toBe(byId(h, 'customer-query'));
  });

  it('finds a customer by code, by phone and by name, as the server answers', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    await openCustomer(h);
    const lookup = vi.spyOn(api(), 'lookupCustomers');
    for (const [text, name] of [
      ['CUS-0001', 'Nimal Perera'],
      ['0712223344', 'Kamala Silva'],
      ['perera', 'Nimal Perera'],
    ] as const) {
      await search(h, text);
      expect(results(h), text).toHaveLength(1);
      expect(results(h)[0], text).toContain(name);
    }
    expect(lookup.mock.calls.map((c) => c[0])).toEqual(['CUS-0001', '0712223344', 'perera']);
  });

  it('refuses an empty search in the form and asks the server nothing', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    await openCustomer(h);
    const lookup = vi.spyOn(api(), 'lookupCustomers');
    await search(h, '   ');
    expect(h.text('customer-problem')).toBe('Enter a code, phone number or name');
    expect(lookup).not.toHaveBeenCalled();
  });

  it('says "Customer not found" when the server knows nobody by that text', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    await openCustomer(h);
    await search(h, 'nobody at all');
    expect(h.text('customer-problem')).toBe('Customer not found');
    expect(results(h)).toEqual([]);
  });

  it('attaches the chosen customer to the sale in the cart and closes', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    await openCustomer(h);
    await search(h, SALES_MOCK_DEMO.customerWithCredit);
    await clickId(h, 'customer-result');
    await settleFully(h);
    expect(serverSale(h).customer?.code).toBe(SALES_MOCK_DEMO.customerWithCredit);
    expect(storeOf(h, SaleStore).current()?.customer?.name).toBe('Nimal Perera');
    expect(h.text('cart-customer')).toContain('Nimal Perera');
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(branchOf(h).sales).toHaveLength(1);
  });

  it('creates the sale with the customer when the cart has none (AC-29)', async () => {
    const h = await setupTill({ given: ['shift_open'] });
    const create = vi.spyOn(api(), 'createSale');
    const attach = vi.spyOn(api(), 'attachCustomer');
    expect(storeOf(h, SaleStore).current()).toBeNull();
    await openCustomer(h);
    await search(h, SALES_MOCK_DEMO.customerWithCredit);
    await clickId(h, 'customer-result');
    await settleFully(h);
    expect(create).toHaveBeenCalledTimes(1);
    expect(create.mock.calls[0]?.[0]).toEqual({ customer_id: serverSale(h).customer?.id });
    expect(attach).not.toHaveBeenCalled();
    expect(branchOf(h).sales).toHaveLength(1);
    expect(storeOf(h, SaleStore).current()?.status).toBe('draft');
    expect(storeOf(h, SaleStore).lineCount()).toBe(0);
    expect(h.text('cart-customer')).toContain('CUS-0001');
  });

  it('says "Customer not found" when the customer vanished between the search and the attach', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    await openCustomer(h);
    await search(h, SALES_MOCK_DEMO.customerWithCredit);
    h.state.seed.customers.splice(0, 1);
    await clickId(h, 'customer-result');
    await settleFully(h);
    expect(h.text('customer-problem')).toBe('Customer not found');
    expect(h.query('[role="dialog"]')).not.toBeNull();
    expect(serverSale(h).customer).toBeNull();
    expect(results(h)).toEqual([]);
  });

  it('shows the refusal in words and keeps the sale as it was once payment has started', async () => {
    const h = await setupTill({ given: ['shift_open', 'pending_payment_sale'] });
    await openCustomer(h);
    await search(h, SALES_MOCK_DEMO.customerWithCredit);
    await clickId(h, 'customer-result');
    await settleFully(h);
    expect(h.text('message-bar')).toContain('Payment has started');
    expect(serverSale(h).customer).toBeNull();
    expect(storeOf(h, SaleStore).current()?.customer).toBeNull();
  });

  it('closes on Esc and attaches nothing', async () => {
    const h = await setupTill({ given: ['shift_open', 'draft_sale_with_line'] });
    const attach = vi.spyOn(api(), 'attachCustomer');
    await openCustomer(h);
    h.press('Escape');
    await h.settle();
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(attach).not.toHaveBeenCalled();
  });

  it('is not offered without an open shift', async () => {
    const h = await setupTill();
    h.press('F6');
    await settleFully(h);
    expect(h.query('[role="dialog"]')).toBeNull();
    expect(h.text('message-bar')).toContain('Open a shift first');
  });
});
