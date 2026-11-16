import { ChangeDetectionStrategy, Component } from '@angular/core';

/**
 * Product search (T-037 AC-18). Stub: step B2 fills it in. The input carries
 * `data-till-search-field` so the `/` and `Ctrl+K` keys can focus it.
 */
@Component({
  selector: 'pos-product-search',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <label class="field">
      <span>Search products</span>
      <input type="search" autocomplete="off" data-till-search-field data-testid="search-field" />
    </label>
  `,
  styles: `
    .field {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    input {
      font: inherit;
      min-height: 44px;
      padding: 8px 12px;
      border: 1px solid var(--fp-border);
      border-radius: var(--fp-radius);
      background: var(--fp-bg);
      color: var(--fp-text);
    }
  `,
})
export class ProductSearch {}
