import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/** Stands in for an area whose backend does not exist yet; its text comes from the route data. */
@Component({
  selector: 'adm-placeholder-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>{{ title() }}</h1>
    <p class="fp-muted">Not built yet. Arrives with {{ arrivesWith() }}.</p>
  `,
})
export class PlaceholderPage {
  readonly title = input('');
  readonly arrivesWith = input('');
}
