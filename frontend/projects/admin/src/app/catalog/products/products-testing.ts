import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  TestRequest,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, Routes, provideRouter, withComponentInputBinding } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { Brand, Category, Product, ProductBarcode, Unit } from '@flexipos/shared';
import { pageEnvelope, seedSession } from '../../crud/testing/crud-testing';

/**
 * Spec-only scaffolding of the products screens (T-036 chunk 3). Never imported by
 * production code, so no build bundles it.
 */

export const API = '/api/v1/catalog';

export const UNIT_EA: Unit = {
  id: 'UNI1',
  code: 'EA',
  name: 'Each',
  dimension: 'count',
  precision: 0,
  base_unit_id: null,
  base_unit_code: null,
  factor: null,
  is_active: true,
};
export const UNIT_CASE: Unit = { ...UNIT_EA, id: 'UNI2', code: 'CASE', name: 'Case' };
export const UNIT_KG: Unit = {
  ...UNIT_EA,
  id: 'UNI3',
  code: 'KG',
  name: 'Kilogram',
  dimension: 'mass',
};
export const UNITS = [UNIT_EA, UNIT_CASE, UNIT_KG];

export const CATEGORY: Category = {
  id: 'CAT1',
  code: 'DRINK',
  name: 'Drinks',
  parent_id: null,
  parent_name: null,
  path: 'CAT1',
  depth: 0,
  is_active: true,
  deleted_at: null,
};
export const BRAND: Brand = {
  id: 'BRA1',
  code: 'ACME',
  name: 'Acme',
  is_active: true,
  deleted_at: null,
};

export const WATER: Product = {
  id: 'PRO1',
  type: 'simple',
  parent_id: null,
  sku: 'WATER-500',
  name: 'Spring water',
  category_id: 'CAT1',
  category_name: 'Drinks',
  brand_id: 'BRA1',
  brand_name: 'Acme',
  stock_unit_id: 'UNI1',
  stock_unit_code: 'EA',
  purchase_unit_id: 'UNI2',
  sales_unit_id: null,
  is_weighable: false,
  price_mode: 'per_unit',
  plu_code: '4011',
  track_stock: true,
  tracking_mode: 'quantity',
  is_active: true,
  variant_options: null,
  variant_values: null,
  version: 3,
  deleted_at: null,
};
export const TEE: Product = {
  ...WATER,
  id: 'PRO2',
  type: 'variant_parent',
  sku: 'TEE',
  name: 'T-shirt',
  plu_code: null,
  purchase_unit_id: null,
  brand_id: null,
  brand_name: null,
  variant_options: ['size'],
  version: 1,
};
export const TEE_S: Product = {
  ...TEE,
  id: 'PRO3',
  type: 'variant',
  parent_id: 'PRO2',
  sku: 'TEE-S',
  name: 'T-shirt S',
  variant_options: null,
  variant_values: { size: 'S' },
};

export const BARCODE_1: ProductBarcode = {
  id: 'BCD1',
  product_id: 'PRO1',
  barcode: '5012345678900',
  unit_id: null,
  unit_code: null,
  quantity: '1.0000',
  is_primary: true,
};
export const BARCODE_2: ProductBarcode = {
  id: 'BCD2',
  product_id: 'PRO1',
  barcode: '5012345678917',
  unit_id: 'UNI2',
  unit_code: 'CASE',
  quantity: '24.0000',
  is_primary: false,
};

const testRoutes: Routes = [
  { path: 'catalog', loadChildren: () => import('../catalog.routes') },
  {
    path: 'forbidden',
    loadComponent: () => import('../../shell/forbidden-page').then((m) => m.ForbiddenPage),
  },
];

/** One screen under test: the real catalog routes, a seeded session and a fake backend. */
export class Screen {
  http!: HttpTestingController;
  router!: Router;
  harness!: RouterTestingHarness;

  async open(url: string, permissions: readonly string[]): Promise<void> {
    sessionStorage.clear();
    seedSession(permissions);
    TestBed.configureTestingModule({
      providers: [
        provideRouter(testRoutes, withComponentInputBinding()),
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });
    this.http = TestBed.inject(HttpTestingController);
    this.router = TestBed.inject(Router);
    this.harness = await RouterTestingHarness.create();
    await this.harness.navigateByUrl(url);
  }

  el = (): HTMLElement => this.harness.routeNativeElement as HTMLElement;
  text = (): string => this.el().textContent ?? '';

  async settle(): Promise<void> {
    await this.harness.fixture.whenStable();
    this.harness.detectChanges();
  }

  /** Requests to `catalog/<path>` (a path with no query string). */
  requests(method: string, path: string): TestRequest[] {
    return this.http.match((r) => r.method === method && r.url === `${API}/${path}`);
  }

  one(method: string, path: string): TestRequest {
    const found = this.requests(method, path);
    if (found.length !== 1) {
      throw new Error(`Expected one ${method} ${path}, found ${found.length}`);
    }
    return found[0];
  }

  /** Answers every open picker request (categories, brands, units, variant parents). */
  async answerPickers(): Promise<void> {
    for (const r of this.requests('GET', 'categories')) r.flush(pageEnvelope([CATEGORY]));
    for (const r of this.requests('GET', 'brands')) r.flush(pageEnvelope([BRAND]));
    for (const r of this.requests('GET', 'units')) r.flush(pageEnvelope(UNITS));
    for (const r of this.requests('GET', 'products')) r.flush(pageEnvelope([TEE]));
    await this.settle();
  }

  field<E extends HTMLElement>(selector: string): E {
    return this.el().querySelector(selector) as E;
  }

  type(selector: string, value: string): void {
    const input = this.field<HTMLInputElement | HTMLTextAreaElement>(selector);
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  /** A plain `<select>`, by its form control name. */
  selectNative(name: string, value: string): void {
    const select = this.field<HTMLSelectElement>(`select[formcontrolname="${name}"]`);
    select.value = value;
    select.dispatchEvent(new Event('change'));
    this.harness.detectChanges();
  }

  /** The `<select>` of an entity picker, found by its visible label. */
  pickerSelect(label: string): HTMLSelectElement | null {
    const l = Array.from(this.el().querySelectorAll('label')).find(
      (x) => x.textContent?.trim() === label,
    );
    return l ? this.el().querySelector<HTMLSelectElement>(`#${l.htmlFor}`) : null;
  }

  pick(label: string, value: string): void {
    const select = this.pickerSelect(label);
    if (!select) {
      throw new Error(`No picker labelled ${label}`);
    }
    select.value = value;
    select.dispatchEvent(new Event('change'));
    this.harness.detectChanges();
  }

  optionTexts(label: string): string[] {
    const select = this.pickerSelect(label);
    return Array.from(select?.options ?? []).map((o) => o.textContent?.trim() ?? '');
  }

  check(name: string, on: boolean): void {
    const box = this.field<HTMLInputElement>(`input[formcontrolname="${name}"]`);
    box.checked = on;
    box.dispatchEvent(new Event('change'));
  }

  submit(): void {
    this.field<HTMLFormElement>('form').dispatchEvent(new Event('submit'));
    this.harness.detectChanges();
  }

  button(label: string): HTMLButtonElement | undefined {
    return Array.from(this.el().querySelectorAll('button')).find(
      (b) => b.textContent?.trim() === label,
    );
  }

  dialogButton(label: string): HTMLButtonElement {
    return Array.from(this.el().querySelectorAll('[role="dialog"] button')).find(
      (b) => b.textContent?.trim() === label,
    ) as HTMLButtonElement;
  }

  /** The message paragraph tied to a control through `aria-describedby`. */
  describedBy(selector: string): string | null {
    const id = this.field(selector)?.getAttribute('aria-describedby');
    return id ? (this.el().querySelector(`#${id}`)?.textContent?.trim() ?? null) : null;
  }
}
