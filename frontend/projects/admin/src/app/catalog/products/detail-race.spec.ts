import { CATALOG_PERMISSIONS } from '@flexipos/shared';
import { envelope, pageEnvelope } from '../../crud/testing/crud-testing';
import { TEE, TEE_S, Screen } from './products-testing';

const P = CATALOG_PERMISSIONS.products;

/**
 * QA (T-036 round 2, R-1): the detail page follows the route id. A request still in flight
 * for the old id must be cancelled so its late reply cannot overwrite the new product, and
 * the old product must be gone while the new one loads.
 */
describe('product detail follows the id (QA, review R-1)', () => {
  let s: Screen;

  beforeEach(() => {
    s = new Screen();
  });
  afterEach(() => {
    s.http.verify();
  });

  it('cancels the old product request and never shows the old product while the new one loads', async () => {
    await s.open('/catalog/products/PRO2', [P.view]);
    const first = s.one('GET', 'products/PRO2');
    first.flush(envelope(TEE));
    await s.settle();
    s.http.expectOne((r) => r.params.get('parent_id') === 'PRO2').flush(pageEnvelope([TEE_S]));
    await s.settle();
    expect(s.field('h1').textContent).toContain('T-shirt');

    // Go to a second product, whose reply is still pending: the first product must be gone.
    await s.harness.navigateByUrl('/catalog/products/PRO3');
    await s.settle();
    const second = s.one('GET', 'products/PRO3');
    expect(s.field('h1')).toBeNull();

    // Navigate again before PRO3 answers: its request must be cancelled.
    await s.harness.navigateByUrl('/catalog/products/PRO2');
    await s.settle();
    expect(second.cancelled).toBe(true);
    s.one('GET', 'products/PRO2').flush(envelope(TEE));
    await s.settle();
    s.http.expectOne((r) => r.params.get('parent_id') === 'PRO2').flush(pageEnvelope([]));
    await s.settle();
    expect(s.field('h1').textContent).toContain('T-shirt');
  });
});
