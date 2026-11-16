import { ChangeDetectionStrategy, Component } from '@angular/core';

/**
 * The product grid with category tabs (T-037 AC-16 to AC-20). Stub: step B2 fills it in.
 */
@Component({
  selector: 'pos-product-grid',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section aria-labelledby="pos-grid-title">
      <h2 id="pos-grid-title">Products</h2>
      <p class="fp-muted">The product grid arrives with step B2.</p>
    </section>
  `,
  styles: `
    h2 {
      margin: 0 0 8px;
      font-size: 1.1rem;
    }
  `,
})
export class ProductGrid {}
