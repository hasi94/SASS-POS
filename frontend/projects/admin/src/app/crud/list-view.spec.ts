import { ChangeDetectionStrategy, Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ApiError, ApiResult } from '@flexipos/shared';
import { Subject, of, throwError } from 'rxjs';
import { ListController, createListController } from './list-controller';
import { ListColumn, ListView, RowActions } from './list-view';

interface Row {
  name: string;
  depth: number;
}

const COLUMNS: ListColumn<Row>[] = [
  { header: 'Name', cell: (r) => r.name, indent: (r) => r.depth },
  { header: 'Depth', cell: (r) => String(r.depth) },
];

function rows(n: number): Row[] {
  return Array.from({ length: n }, (_, i) => ({ name: `Row ${i + 1}`, depth: i % 2 }));
}

function ok(data: Row[], total: number): ApiResult<Row[]> {
  return { data, meta: { request_id: 'r', pagination: { page: 1, per_page: 25, total } } };
}

@Component({
  selector: 'adm-host',
  imports: [ListView],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<adm-list-view [controller]="list" [columns]="columns" caption="Categories" />`,
})
class PlainHost {
  list!: ListController<Row>;
  readonly columns = COLUMNS;
}

@Component({
  selector: 'adm-host-actions',
  imports: [ListView, RowActions],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <adm-list-view [controller]="list" [columns]="columns" caption="Categories">
      <ng-template [admRowActions]="list.rows()" let-row>
        <button type="button" class="edit">Edit {{ row.name }}</button>
      </ng-template>
    </adm-list-view>
  `,
})
class ActionsHost {
  list!: ListController<Row>;
  readonly columns = COLUMNS;
}

async function render(
  controller: ListController<Row>,
  options: { actions?: boolean } = {},
): Promise<{ el: HTMLElement; settle: () => Promise<void> }> {
  const fixture = options.actions
    ? TestBed.createComponent(ActionsHost)
    : TestBed.createComponent(PlainHost);
  fixture.componentInstance.list = controller;
  fixture.detectChanges();
  await fixture.whenStable();
  return { el: fixture.nativeElement as HTMLElement, settle: () => fixture.whenStable() };
}

describe('ListView (T-036 AC-14, AC-16)', () => {
  it('renders a table with a caption, scoped column headers and one row per item', async () => {
    const c = createListController<Row>(() => of(ok(rows(3), 3)));
    c.load();
    const { el } = await render(c);
    expect(el.querySelector('table caption')?.textContent?.trim()).toBe('Categories');
    const headers = Array.from(el.querySelectorAll('th'));
    expect(headers.map((h) => h.textContent?.trim())).toEqual(['Name', 'Depth']);
    expect(headers.every((h) => h.getAttribute('scope') === 'col')).toBe(true);
    expect(el.querySelectorAll('tbody tr')).toHaveLength(3);
  });

  it('indents a cell by the row depth', async () => {
    const c = createListController<Row>(() => of(ok(rows(2), 2)));
    c.load();
    const { el } = await render(c);
    const [first, second] = Array.from(el.querySelectorAll<HTMLElement>('tbody tr td:first-child'));
    expect(first.style.paddingLeft).toBe('0.75rem');
    expect(second.style.paddingLeft).toBe('2rem');
  });

  it('disables Previous on page 1 and Next on the last page, and says "Page 2 of 3"', async () => {
    const c = createListController<Row>(() => of(ok(rows(2), 60)));
    c.load();
    const { el, settle } = await render(c);
    const buttons = () => Array.from(el.querySelectorAll<HTMLButtonElement>('.adm-pager button'));
    expect(buttons()[0].disabled).toBe(true);
    expect(buttons()[1].disabled).toBe(false);
    expect(el.querySelector('.adm-pager')?.textContent).toContain('Page 1 of 3');

    c.goTo(2);
    await settle();
    expect(el.querySelector('.adm-pager')?.textContent).toContain('Page 2 of 3');
    expect(buttons()[0].disabled).toBe(false);
    expect(buttons()[1].disabled).toBe(false);

    c.goTo(3);
    await settle();
    expect(buttons()[0].disabled).toBe(false);
    expect(buttons()[1].disabled).toBe(true);
  });

  it('moves the page when Next is pressed', async () => {
    const calls: number[] = [];
    const c = createListController<Row>((p) => {
      calls.push(p.page);
      return of(ok(rows(1), 60));
    });
    c.load();
    const { el } = await render(c);
    el.querySelectorAll<HTMLButtonElement>('.adm-pager button')[1].click();
    expect(calls).toEqual([1, 2]);
  });

  it('shows "Nothing here yet" with zero rows', async () => {
    const c = createListController<Row>(() => of(ok([], 0)));
    c.load();
    const { el } = await render(c);
    expect(el.textContent).toContain('Nothing here yet');
    expect(el.querySelectorAll('tbody tr')).toHaveLength(0);
  });

  it('shows a polite loading status while a request is in flight, and no empty state', async () => {
    const pending = new Subject<ApiResult<Row[]>>();
    const c = createListController<Row>(() => pending);
    c.load();
    const { el, settle } = await render(c);
    expect(el.querySelector('[role="status"]')).not.toBeNull();
    expect(el.textContent).not.toContain('Nothing here yet');
    pending.next(ok(rows(1), 1));
    pending.complete();
    await settle();
    expect(el.querySelector('[role="status"]')).toBeNull();
    expect(el.querySelectorAll('tbody tr')).toHaveLength(1);
  });

  it('shows the error panel with Retry instead of the table, and Retry re-sends', async () => {
    let sent = 0;
    const c = createListController<Row>(() => {
      sent += 1;
      return throwError(() => new ApiError(500, 'Server broke.', [], 'req-9'));
    });
    c.load();
    const { el } = await render(c);
    expect(el.querySelector('table')).toBeNull();
    expect(el.querySelector('[role="alert"]')?.textContent).toContain('Server broke.');
    expect(el.textContent).toContain('Request id: req-9');
    (el.querySelector('[role="alert"] button') as HTMLButtonElement).click();
    expect(sent).toBe(2);
  });

  it('has a labelled search box', async () => {
    const c = createListController<Row>(() => of(ok([], 0)));
    const { el } = await render(c);
    const label = el.querySelector('label.adm-search');
    expect(label?.textContent).toContain('Search');
    expect(label?.querySelector('input[type="search"]')).not.toBeNull();
  });

  it('adds an Actions column from an admRowActions template, one cell per row', async () => {
    const c = createListController<Row>(() => of(ok(rows(2), 2)));
    c.load();
    const { el } = await render(c, { actions: true });
    const headers = Array.from(el.querySelectorAll('th')).map((h) => h.textContent?.trim());
    expect(headers).toEqual(['Name', 'Depth', 'Actions']);
    const buttons = Array.from(el.querySelectorAll('button.edit')).map((b) =>
      b.textContent?.trim(),
    );
    expect(buttons).toEqual(['Edit Row 1', 'Edit Row 2']);
  });

  it('control: without an actions template there is no Actions column', async () => {
    const c = createListController<Row>(() => of(ok(rows(1), 1)));
    c.load();
    const { el } = await render(c);
    expect(el.querySelector('button.edit')).toBeNull();
    expect(Array.from(el.querySelectorAll('th')).map((h) => h.textContent?.trim())).not.toContain(
      'Actions',
    );
  });
});
