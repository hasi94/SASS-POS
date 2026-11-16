import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

/** Where a person lands after opening a route they may not use (T-036 AC-6, AC-56). */
@Component({
  selector: 'adm-forbidden-page',
  imports: [RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>Not permitted</h1>
    <p>You do not have permission to open this page.</p>
    <p><a routerLink="/dashboard">Back to the dashboard</a></p>
  `,
})
export class ForbiddenPage {}
