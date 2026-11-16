import { ApiErrorItem } from '../../api/envelope';
import {
  Brand,
  CatalogDecimal,
  Category,
  Modifier,
  ModifierGroup,
  Product,
  ProductBarcode,
  Unit,
} from '../../contracts/catalog/catalog.models';

/**
 * State and helpers of the catalog mock (T-036, development only). PROVISIONAL pending B-1.
 *
 * Production-bundle rule (plan, Risks): this file and its siblings hold DECLARATIONS ONLY.
 * No top-level call builds state; the in-memory store is created lazily inside
 * {@link catalogState}, so a stray import cannot leave seed data (or the sentinel) in a
 * production bundle through a side effect. The build check greps for the sentinel.
 */

/** Distinctive string stamped into every `request_id` (D-14); the production grep looks for it. */
export const MOCK_CATALOG_SENTINEL = 'fp-mock-catalog-a7f3';

const DEV_LATENCY_MS = 150;

export interface CategoryRow {
  id: string;
  code: string;
  name: string;
  parent_id: string | null;
  is_active: boolean;
  deleted_at: string | null;
}
export type BrandRow = Brand;
export type UnitRow = Omit<Unit, 'base_unit_code'>;
export interface ConversionRow {
  id: string;
  product_id: string | null;
  from_unit_id: string;
  to_unit_id: string;
  factor: CatalogDecimal;
}
export type ProductRow = Omit<Product, 'category_name' | 'brand_name' | 'stock_unit_code'>;
export type BarcodeRow = Omit<ProductBarcode, 'unit_code'>;
export type GroupRow = ModifierGroup;
export type ModifierRow = Omit<Modifier, 'linked_product_name' | 'linked_unit_code'>;
/** Internal only: which products carry a modifier group (T-027 AC-36a). Never served. */
export interface Attachment {
  group_id: string;
  product_id: string;
}

export interface CatalogState {
  seq: number;
  requestSeq: number;
  latencyMs: number;
  categories: CategoryRow[];
  brands: BrandRow[];
  units: UnitRow[];
  conversions: ConversionRow[];
  products: ProductRow[];
  barcodes: BarcodeRow[];
  groups: GroupRow[];
  modifiers: ModifierRow[];
  attachments: Attachment[];
}

let state: CatalogState | null = null;

/** The store, built on first use. */
export function catalogState(): CatalogState {
  state ??= buildSeed(DEV_LATENCY_MS);
  return state;
}

/** Test hook: a fresh seed, and no artificial latency unless asked for. */
export function resetMockCatalog(options: { latencyMs?: number } = {}): void {
  state = buildSeed(options.latencyMs ?? 0);
}

export function nextId(prefix: string): string {
  const s = catalogState();
  s.seq += 1;
  return `${prefix}${String(s.seq).padStart(4, '0')}`;
}

/** Decimals travel as strings at storage scale. */
export function fixed(value: number): CatalogDecimal {
  return value.toFixed(4);
}

// ---------------------------------------------------------------- the seed

function buildSeed(latencyMs: number): CatalogState {
  const unit = (
    id: string,
    code: string,
    name: string,
    dimension: Unit['dimension'],
    precision: number,
    base: string | null,
    factor: string | null,
  ): UnitRow => ({
    id,
    code,
    name,
    dimension,
    precision,
    base_unit_id: base,
    factor,
    is_active: true,
  });
  const product = (id: string, patch: Partial<ProductRow>): ProductRow => ({
    id,
    type: 'simple',
    parent_id: null,
    sku: id,
    name: id,
    category_id: 'CAT0002',
    brand_id: null,
    stock_unit_id: 'UNI0001',
    purchase_unit_id: null,
    sales_unit_id: null,
    is_weighable: false,
    price_mode: 'per_unit',
    plu_code: null,
    track_stock: true,
    tracking_mode: 'quantity',
    is_active: true,
    variant_options: null,
    variant_values: null,
    version: 1,
    deleted_at: null,
    ...patch,
  });

  return {
    seq: 100,
    requestSeq: 0,
    latencyMs,
    units: [
      unit('UNI0001', 'EA', 'Each', 'count', 0, null, null),
      unit('UNI0002', 'CASE', 'Case', 'count', 0, 'UNI0001', fixed(24)),
      unit('UNI0003', 'KG', 'Kilogram', 'mass', 3, null, null),
      unit('UNI0004', 'G', 'Gram', 'mass', 0, 'UNI0003', fixed(0.001)),
      unit('UNI0005', 'L', 'Litre', 'volume', 3, null, null),
      unit('UNI0006', 'ML', 'Millilitre', 'volume', 0, 'UNI0005', fixed(0.001)),
    ],
    categories: [
      {
        id: 'CAT0001',
        code: 'FOOD',
        name: 'Food',
        parent_id: null,
        is_active: true,
        deleted_at: null,
      },
      {
        id: 'CAT0002',
        code: 'DRINKS',
        name: 'Drinks',
        parent_id: 'CAT0001',
        is_active: true,
        deleted_at: null,
      },
      {
        id: 'CAT0003',
        code: 'RETAIL',
        name: 'Retail',
        parent_id: null,
        is_active: true,
        deleted_at: null,
      },
    ],
    brands: [
      { id: 'BRA0001', code: 'ACME', name: 'Acme', is_active: true, deleted_at: null },
      { id: 'BRA0002', code: 'GLOBEX', name: 'Globex', is_active: true, deleted_at: null },
    ],
    products: [
      product('PRO0001', {
        sku: 'WATER-500',
        name: 'Spring water 500 ml',
        brand_id: 'BRA0001',
        purchase_unit_id: 'UNI0002',
      }),
      product('PRO0002', {
        type: 'variant_parent',
        sku: 'TEE',
        name: 'T-shirt',
        category_id: 'CAT0003',
        variant_options: ['size'],
      }),
      product('PRO0003', {
        type: 'variant',
        parent_id: 'PRO0002',
        sku: 'TEE-S',
        name: 'T-shirt S',
        category_id: 'CAT0003',
        variant_values: { size: 'S' },
      }),
      product('PRO0004', {
        type: 'variant',
        parent_id: 'PRO0002',
        sku: 'TEE-M',
        name: 'T-shirt M',
        category_id: 'CAT0003',
        variant_values: { size: 'M' },
      }),
      product('PRO0005', {
        sku: 'APPLES-KG',
        name: 'Apples',
        category_id: 'CAT0001',
        stock_unit_id: 'UNI0003',
        is_weighable: true,
        price_mode: 'per_weight',
        plu_code: '4011',
      }),
      product('PRO0006', {
        sku: 'OLD-ITEM',
        name: 'Discontinued item',
        is_active: false,
        deleted_at: '2026-01-15T09:00:00Z',
      }),
    ],
    barcodes: [
      {
        id: 'BCD0001',
        product_id: 'PRO0001',
        barcode: '5012345678900',
        unit_id: null,
        quantity: fixed(1),
        is_primary: true,
      },
    ],
    conversions: [
      {
        id: 'CNV0001',
        product_id: null,
        from_unit_id: 'UNI0003',
        to_unit_id: 'UNI0004',
        factor: fixed(1000),
      },
    ],
    groups: [{ id: 'GRP0001', name: 'Extras', min_select: 0, max_select: 3, deleted_at: null }],
    modifiers: [
      {
        id: 'MOD0001',
        group_id: 'GRP0001',
        name: 'Extra shot',
        price_delta: fixed(0.5),
        cost_delta: fixed(0.2),
        linked_product_id: null,
        linked_quantity: null,
        linked_unit_id: null,
      },
      {
        id: 'MOD0002',
        group_id: 'GRP0001',
        name: 'Add apple',
        price_delta: fixed(1),
        cost_delta: fixed(0.4),
        linked_product_id: 'PRO0005',
        linked_quantity: fixed(0.15),
        linked_unit_id: 'UNI0003',
      },
    ],
    attachments: [{ group_id: 'GRP0001', product_id: 'PRO0001' }],
  };
}

// ---------------------------------------------------------------- envelope

export interface MockReply {
  status: number;
  data: unknown;
  pagination?: { page: number; per_page: number; total: number };
}

/** A refusal a handler throws; the interceptor turns it into an enveloped error. */
export class MockRefusal {
  constructor(
    readonly status: number,
    readonly message: string,
    readonly errors: ApiErrorItem[],
  ) {}
}

export function refusal(
  status: number,
  code: string,
  message: string,
  field?: string,
  detail?: string,
): MockRefusal {
  const item: ApiErrorItem = { code };
  if (field !== undefined) {
    item.field = field;
  }
  if (detail !== undefined) {
    item.detail = detail;
  }
  return new MockRefusal(status, message, [item]);
}

/** 422 `kernel.validation_failed`, one error item per failed field. */
export function validationFailed(items: { field: string; detail: string }[]): MockRefusal {
  return new MockRefusal(
    422,
    'The request is invalid.',
    items.map((i) => ({ code: 'kernel.validation_failed', field: i.field, detail: i.detail })),
  );
}

export function notFound(what: string): MockRefusal {
  return refusal(404, 'kernel.not_found', `${what} was not found.`);
}

/** A 409 `catalog.in_use`; the message names the blocker and no field is carried. */
export function inUse(message: string): MockRefusal {
  return refusal(409, 'catalog.in_use', message);
}

export function nextRequestId(): string {
  const s = catalogState();
  s.requestSeq += 1;
  return `${MOCK_CATALOG_SENTINEL}-${s.requestSeq}`;
}

export function okEnvelope(data: unknown, pagination?: MockReply['pagination']): unknown {
  return {
    success: true,
    data,
    message: '',
    errors: [],
    meta: { request_id: nextRequestId(), ...(pagination ? { pagination } : {}) },
  };
}

export function refuseEnvelope(
  message: string,
  errors: ApiErrorItem[],
  requestId = nextRequestId(),
): unknown {
  return { success: false, data: null, message, errors, meta: { request_id: requestId } };
}

// ---------------------------------------------------------------- query helpers

export interface MockRequest {
  method: string;
  /** Path segments after `catalog/`. */
  segments: string[];
  query: { get(name: string): string | null };
  body: Record<string, unknown>;
}

export interface Page<T> {
  rows: T[];
  pagination: { page: number; per_page: number; total: number };
}

/** Applies `search` (case-insensitive substring over `haystack`) then `page`/`per_page`. */
export function pageOf<T>(rows: T[], req: MockRequest, haystack: (row: T) => string[]): Page<T> {
  const term = (req.query.get('search') ?? '').trim().toLowerCase();
  const found = term
    ? rows.filter((r) => haystack(r).some((h) => h.toLowerCase().includes(term)))
    : rows;
  const page = Math.max(1, Math.floor(Number(req.query.get('page')) || 1));
  const perPage = Math.min(100, Math.max(1, Math.floor(Number(req.query.get('per_page')) || 25)));
  return {
    rows: found.slice((page - 1) * perPage, page * perPage),
    pagination: { page, per_page: perPage, total: found.length },
  };
}

export function listReply<T>(
  rows: T[],
  req: MockRequest,
  haystack: (row: T) => string[],
): MockReply {
  const p = pageOf(rows, req, haystack);
  return { status: 200, data: p.rows, pagination: p.pagination };
}

// ---------------------------------------------------------------- field helpers

/** A trimmed string, or '' when absent or not a string. */
export function text(body: Record<string, unknown>, field: string): string {
  const v = body[field];
  return typeof v === 'string' ? v.trim() : '';
}

/** The id in `body[field]`, or null when absent, null or blank. */
export function idOrNull(body: Record<string, unknown>, field: string): string | null {
  const v = body[field];
  return typeof v === 'string' && v.trim() !== '' ? v.trim() : null;
}

/** A finite number from a number or numeric string, or null. */
export function numberOf(value: unknown): number | null {
  if (typeof value === 'number') {
    return Number.isFinite(value) ? value : null;
  }
  if (typeof value === 'string' && value.trim() !== '') {
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
  }
  return null;
}

export function has(body: Record<string, unknown>, field: string): boolean {
  return Object.prototype.hasOwnProperty.call(body, field);
}

export function nowIso(): string {
  return new Date().toISOString();
}

// ---------------------------------------------------------------- lookups

export function unitById(id: string | null): UnitRow | undefined {
  return id === null ? undefined : catalogState().units.find((u) => u.id === id);
}

export function categoryById(id: string | null): CategoryRow | undefined {
  return id === null ? undefined : catalogState().categories.find((c) => c.id === id);
}

export function brandById(id: string | null): BrandRow | undefined {
  return id === null ? undefined : catalogState().brands.find((b) => b.id === id);
}

export function productById(id: string | null): ProductRow | undefined {
  return id === null ? undefined : catalogState().products.find((p) => p.id === id);
}

export function groupById(id: string | null): GroupRow | undefined {
  return id === null ? undefined : catalogState().groups.find((g) => g.id === id);
}
