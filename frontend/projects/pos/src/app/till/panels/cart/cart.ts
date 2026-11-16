import { ChangeDetectionStrategy, Component } from '@angular/core';

/**
 * The cart (T-037 AC-29 to AC-38). Stub: step B4 fills it in with the lines and the totals, all
 * of them the server's strings.
 */
@Component({
  selector: 'pos-cart',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section aria-labelledby="pos-cart-title">
      <h2 id="pos-cart-title">Cart</h2>
      <p class="fp-muted">Scan or choose a product</p>
    </section>
  `,
  styles: `
    h2 {
      margin: 0 0 8px;
      font-size: 1.1rem;
    }
  `,
})
export class Cart {}
