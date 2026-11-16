import { Brand, Category } from '../../contracts/catalog/catalog.models';
import {
  CategoryRow,
  MockReply,
  MockRequest,
  brandById,
  categoryById,
  catalogState,
  has,
  idOrNull,
  inUse,
  listReply,
  nextId,
  notFound,
  nowIso,
  refusal,
  text,
  validationFailed,
} from './mock-catalog.store';

/** Deepest legal `depth` (T-027 AC-3a: 18 ids of 26 characters fit a 500 character path). */
const MAX_DEPTH = 17;

/** Ancestor ids from the root down to the row itself. */
function chain(row: CategoryRow): string[] {
  const ids: string[] = [];
  const seen = new Set<string>();
  let cursor: CategoryRow | undefined = row;
  while (cursor && !seen.has(cursor.id)) {
    seen.add(cursor.id);
    ids.unshift(cursor.id);
    cursor = categoryById(cursor.parent_id);
  }
  return ids;
}

function project(row: CategoryRow): Category {
  const ids = chain(row);
  return {
    id: row.id,
    code: row.code,
    name: row.name,
    parent_id: row.parent_id,
    parent_name: categoryById(row.parent_id)?.name ?? null,
    path: ids.join('/'),
    depth: ids.length - 1,
    is_active: row.is_active,
    deleted_at: row.deleted_at,
  };
}

function descendantsOf(id: string): CategoryRow[] {
  const all = catalogState().categories;
  const found: CategoryRow[] = [];
  const queue = [id];
  while (queue.length > 0) {
    const parent = queue.shift() as string;
    for (const c of all.filter((x) => x.parent_id === parent && x.deleted_at === null)) {
      found.push(c);
      queue.push(c.id);
    }
  }
  return found;
}

/** Height of the subtree under `row`: 0 for a leaf. */
function height(row: CategoryRow): number {
  const base = chain(row).length;
  return Math.max(0, ...descendantsOf(row.id).map((d) => chain(d).length - base + 1));
}

function saveCategory(req: MockRequest, existing: CategoryRow | null): CategoryRow {
  const s = catalogState();
  const body = req.body;
  const code = has(body, 'code') || !existing ? text(body, 'code') : existing.code;
  const name = has(body, 'name') || !existing ? text(body, 'name') : existing.name;
  const parentId =
    has(body, 'parent_id') || !existing ? idOrNull(body, 'parent_id') : existing.parent_id;

  const missing: { field: string; detail: string }[] = [];
  if (code === '') missing.push({ field: 'code', detail: 'Code is required.' });
  if (name === '') missing.push({ field: 'name', detail: 'Name is required.' });
  if (missing.length > 0) throw validationFailed(missing);

  const clash = s.categories.find(
    (c) =>
      c.deleted_at === null && c.id !== existing?.id && c.code.toLowerCase() === code.toLowerCase(),
  );
  if (clash) {
    throw refusal(
      422,
      'catalog.duplicate',
      'That category code is already used.',
      'code',
      `Code ${code} is already used by category ${clash.name}.`,
    );
  }

  let parentDepth = -1;
  if (parentId !== null) {
    const parent = categoryById(parentId);
    if (!parent || parent.deleted_at !== null) {
      throw validationFailed([
        { field: 'parent_id', detail: 'The parent category does not exist.' },
      ]);
    }
    if (
      existing &&
      (parent.id === existing.id || descendantsOf(existing.id).some((d) => d.id === parent.id))
    ) {
      throw refusal(
        422,
        'catalog.cycle',
        'A category cannot be moved under itself.',
        'parent_id',
        `Category ${parent.name} is inside ${existing.name}, so it cannot become its parent.`,
      );
    }
    parentDepth = chain(parent).length - 1;
  }
  const below = existing ? height(existing) : 0;
  if (parentDepth + 1 + below > MAX_DEPTH) {
    throw refusal(
      422,
      'catalog.too_deep',
      'The category tree is too deep.',
      'parent_id',
      `Categories can be at most ${MAX_DEPTH + 1} levels deep.`,
    );
  }

  if (existing) {
    existing.code = code;
    existing.name = name;
    existing.parent_id = parentId;
    return existing;
  }
  const created: CategoryRow = {
    id: nextId('CAT'),
    code,
    name,
    parent_id: parentId,
    is_active: true,
    deleted_at: null,
  };
  s.categories.push(created);
  return created;
}

function handleCategories(req: MockRequest): MockReply | null {
  const s = catalogState();
  const id = req.segments[1];
  if (req.segments.length === 1) {
    if (req.method === 'GET') {
      const live = s.categories
        .filter((c) => c.deleted_at === null)
        .map(project)
        .sort((a, b) => treeKey(a).localeCompare(treeKey(b)));
      return listReply(live, req, (c) => [c.code, c.name]);
    }
    if (req.method === 'POST') {
      return { status: 201, data: project(saveCategory(req, null)) };
    }
    return null;
  }
  if (req.segments.length !== 2) return null;
  const row = categoryById(id);
  if (!row) throw notFound('The category');
  if (req.method === 'GET') {
    return { status: 200, data: project(row) };
  }
  if (row.deleted_at !== null) throw notFound('The category');
  if (req.method === 'PUT') {
    return { status: 200, data: project(saveCategory(req, row)) };
  }
  if (req.method === 'DELETE') {
    const child = s.categories.find((c) => c.parent_id === row.id && c.deleted_at === null);
    if (child) {
      throw inUse(
        `Category ${row.name} cannot be deleted: it has the active child category ${child.name}.`,
      );
    }
    const product = s.products.find((p) => p.category_id === row.id && p.deleted_at === null);
    if (product) {
      throw inUse(
        `Category ${row.name} cannot be deleted: it holds the active product ${product.sku}.`,
      );
    }
    row.deleted_at = nowIso();
    return { status: 200, data: null };
  }
  return null;
}

/** Sort key that puts every child directly after its parent. */
function treeKey(c: Category): string {
  return chain(categoryById(c.id) as CategoryRow)
    .map((id) => (categoryById(id)?.name ?? '').toLowerCase())
    .join('\u0000');
}

function saveBrand(req: MockRequest, existing: Brand | null): Brand {
  const s = catalogState();
  const code = has(req.body, 'code') || !existing ? text(req.body, 'code') : existing.code;
  const name = has(req.body, 'name') || !existing ? text(req.body, 'name') : existing.name;
  const missing: { field: string; detail: string }[] = [];
  if (code === '') missing.push({ field: 'code', detail: 'Code is required.' });
  if (name === '') missing.push({ field: 'name', detail: 'Name is required.' });
  if (missing.length > 0) throw validationFailed(missing);

  const live = s.brands.filter((b) => b.deleted_at === null && b.id !== existing?.id);
  const sameCode = live.find((b) => b.code.toLowerCase() === code.toLowerCase());
  if (sameCode) {
    throw refusal(
      422,
      'catalog.duplicate',
      'That brand code is already used.',
      'code',
      `Code ${code} is already used by brand ${sameCode.name}.`,
    );
  }
  const sameName = live.find((b) => b.name.toLowerCase() === name.toLowerCase());
  if (sameName) {
    throw refusal(
      422,
      'catalog.duplicate',
      'That brand name is already used.',
      'name',
      `Name ${name} is already used by brand code ${sameName.code}.`,
    );
  }
  if (existing) {
    existing.code = code;
    existing.name = name;
    return existing;
  }
  const created: Brand = { id: nextId('BRA'), code, name, is_active: true, deleted_at: null };
  s.brands.push(created);
  return created;
}

function handleBrands(req: MockRequest): MockReply | null {
  const s = catalogState();
  if (req.segments.length === 1) {
    if (req.method === 'GET') {
      return listReply(
        s.brands.filter((b) => b.deleted_at === null),
        req,
        (b) => [b.code, b.name],
      );
    }
    if (req.method === 'POST') {
      return { status: 201, data: saveBrand(req, null) };
    }
    return null;
  }
  if (req.segments.length !== 2) return null;
  const row = brandById(req.segments[1]);
  if (!row) throw notFound('The brand');
  if (req.method === 'GET') {
    return { status: 200, data: row };
  }
  if (row.deleted_at !== null) throw notFound('The brand');
  if (req.method === 'PUT') {
    return { status: 200, data: saveBrand(req, row) };
  }
  if (req.method === 'DELETE') {
    const product = s.products.find((p) => p.brand_id === row.id && p.deleted_at === null);
    if (product) {
      throw inUse(
        `Brand ${row.name} cannot be deleted: it is used by the active product ${product.sku}.`,
      );
    }
    row.deleted_at = nowIso();
    return { status: 200, data: null };
  }
  return null;
}

/** Category and brand routes; null when the path is not one of them. */
export function handleCategoriesAndBrands(req: MockRequest): MockReply | null {
  switch (req.segments[0]) {
    case 'categories':
      return handleCategories(req);
    case 'brands':
      return handleBrands(req);
    default:
      return null;
  }
}
