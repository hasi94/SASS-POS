import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { ApiClient, ListParams, Unit } from '@flexipos/shared';
import { EntityPicker, PickerOption } from './entity-picker';
import { SEARCH_DEBOUNCE_MS } from './list-view';
import { pageEnvelope } from './testing/crud-testing';

type UnitRow = Pick<Unit, 'id' | 'code' | 'dimension'>;

const UNITS: UnitRow[] = [
  { id: 'U1', code: 'EA', dimension: 'count' },
  { id: 'U2', code: 'KG', dimension: 'mass' },
  { id: 'U3', code: 'G', dimension: 'mass' },
];

@Component({
  selector: 'adm-host',
  imports: [EntityPicker, ReactiveFormsModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <adm-entity-picker
      label="Base unit"
      [formControl]="control"
      [search]="search"
      [optionOf]="optionOf"
      [filter]="filter"
      [initialLabel]="initialLabel"
      [noneLabel]="noneLabel"
    />
  `,
})
class Host {
  readonly control = new FormControl<string | null>(null);
  readonly api = TestBed.inject(ApiClient);
  readonly search = (p: ListParams) => this.api.getWithMeta<UnitRow[]>('catalog/units', p);
  readonly optionOf = (u: UnitRow): PickerOption => ({ id: u.id, label: u.code });
  filter: ((u: UnitRow) => boolean) | null = null;
  initialLabel: string | null = null;
  noneLabel: string | null = null;
}

describe('EntityPicker (T-036 plan 1.6; AC-36, AC-43)', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    vi.useFakeTimers();
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    vi.useRealTimers();
    http.verify();
  });

  function create(setup: (host: Host) => void = () => undefined) {
    const fixture = TestBed.createComponent(Host);
    setup(fixture.componentInstance);
    fixture.detectChanges();
    const first = http.expectOne((r) => r.url === '/api/v1/catalog/units');
    first.flush(pageEnvelope(UNITS));
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    return { fixture, el, first };
  }

  const select = (el: HTMLElement) => el.querySelector('select') as HTMLSelectElement;
  const optionLabels = (el: HTMLElement) =>
    Array.from(select(el).options).map((o) => o.textContent?.trim());

  it('asks for the first 25 on open, with no search text', () => {
    const { first } = create();
    expect(first.request.params.get('per_page')).toBe('25');
    expect(first.request.params.get('page')).toBe('1');
    expect(first.request.params.has('search')).toBe(false);
  });

  it('typing requests search= with per_page=25, after the pause', () => {
    const { fixture, el } = create();
    const input = el.querySelector('input[type="search"]') as HTMLInputElement;
    input.value = 'kg';
    input.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS - 1);
    http.expectNone(() => true);
    vi.advanceTimersByTime(1);
    const req = http.expectOne((r) => r.url === '/api/v1/catalog/units');
    expect(req.request.params.get('search')).toBe('kg');
    expect(req.request.params.get('per_page')).toBe('25');
    req.flush(pageEnvelope([UNITS[1]]));
    fixture.detectChanges();
    expect(optionLabels(el)).toEqual(['Select…', 'KG']);
  });

  it('lists the results as options and writes the chosen id to the form control', () => {
    const { fixture, el } = create();
    expect(optionLabels(el)).toEqual(['Select…', 'EA', 'KG', 'G']);
    select(el).value = 'U2';
    select(el).dispatchEvent(new Event('change'));
    expect(fixture.componentInstance.control.value).toBe('U2');
  });

  it('hides rows the filter rejects, for example another dimension', () => {
    const { el } = create((host) => (host.filter = (u) => u.dimension === 'mass'));
    expect(optionLabels(el)).toEqual(['Select…', 'KG', 'G']);
  });

  it('the none option gives null and carries the configured text', () => {
    const { fixture, el } = create((host) => (host.noneLabel = 'No parent'));
    expect(optionLabels(el)[0]).toBe('No parent');
    select(el).value = 'U1';
    select(el).dispatchEvent(new Event('change'));
    expect(fixture.componentInstance.control.value).toBe('U1');
    select(el).value = '';
    select(el).dispatchEvent(new Event('change'));
    expect(fixture.componentInstance.control.value).toBeNull();
  });

  it('shows the current value by initialLabel even when it is not among the matches', () => {
    const fixture = TestBed.createComponent(Host);
    fixture.componentInstance.initialLabel = 'LB (pound)';
    fixture.componentInstance.control.setValue('U99');
    fixture.detectChanges();
    http.expectOne((r) => r.url === '/api/v1/catalog/units').flush(pageEnvelope(UNITS));
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(optionLabels(el)).toEqual(['Select…', 'LB (pound)', 'EA', 'KG', 'G']);
    expect(select(el).value).toBe('U99');
  });

  it('reflects a value set on the control', () => {
    const { fixture, el } = create();
    fixture.componentInstance.control.setValue('U3');
    fixture.detectChanges();
    expect(select(el).value).toBe('U3');
  });

  it('associates a label with the select and another with the search box', () => {
    const { el } = create();
    const selectLabel = Array.from(el.querySelectorAll('label')).find(
      (l) => l.textContent?.trim() === 'Base unit',
    ) as HTMLLabelElement;
    expect(selectLabel.htmlFor).toBe(select(el).id);
    const searchLabel = Array.from(el.querySelectorAll('label')).find(
      (l) => l.textContent?.trim() === 'Find Base unit',
    ) as HTMLLabelElement;
    expect(searchLabel.htmlFor).toBe((el.querySelector('input[type="search"]') as HTMLElement).id);
  });

  it('disables both controls when the form control is disabled', () => {
    const { fixture, el } = create();
    fixture.componentInstance.control.disable();
    fixture.detectChanges();
    expect(select(el).disabled).toBe(true);
    expect((el.querySelector('input[type="search"]') as HTMLInputElement).disabled).toBe(true);
  });
});
