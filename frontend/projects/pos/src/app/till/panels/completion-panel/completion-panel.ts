import { ChangeDetectionStrategy, Component } from '@angular/core';

/**
 * What the till shows after a sale completes (T-037 AC-47): the number, the total, the tenders
 * and the change due, and "New sale". Stub: step B5 fills it in and decides when it shows.
 */
@Component({
  selector: 'pos-completion-panel',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<h2 hidden>Sale complete</h2>`,
})
export class CompletionPanel {}
