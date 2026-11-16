import { Modifier } from '../../contracts/catalog/catalog.models';
import {
  GroupRow,
  MockReply,
  MockRequest,
  ModifierRow,
  catalogState,
  fixed,
  groupById,
  has,
  idOrNull,
  inUse,
  listReply,
  nextId,
  notFound,
  nowIso,
  numberOf,
  productById,
  refusal,
  text,
  unitById,
  validationFailed,
} from './mock-catalog.store';

function projectModifier(row: ModifierRow): Modifier {
  return {
    ...row,
    linked_product_name: productById(row.linked_product_id)?.name ?? null,
    linked_unit_code: unitById(row.linked_unit_id)?.code ?? null,
  };
}

/** A whole number from a number or numeric string, else null. */
function wholeNumber(value: unknown): number | null {
  const n = numberOf(value);
  return n !== null && Number.isInteger(n) ? n : null;
}

function saveGroup(req: MockRequest, existing: GroupRow | null): GroupRow {
  const s = catalogState();
  const body = req.body;
  const name = has(body, 'name') || !existing ? text(body, 'name') : existing.name;
  const rawMin =
    has(body, 'min_select') || !existing ? (body['min_select'] ?? 0) : existing.min_select;
  const rawMax =
    has(body, 'max_select') || !existing ? (body['max_select'] ?? null) : existing.max_select;
  const min = wholeNumber(rawMin);
  const max = rawMax === null || rawMax === '' ? null : wholeNumber(rawMax);

  const problems: { field: string; detail: string }[] = [];
  if (name === '') problems.push({ field: 'name', detail: 'Name is required.' });
  if (min === null || min < 0)
    problems.push({ field: 'min_select', detail: 'Minimum must be a whole number, 0 or more.' });
  if (rawMax !== null && rawMax !== '' && (max === null || max < 0)) {
    problems.push({
      field: 'max_select',
      detail: 'Maximum must be a whole number, 0 or more, or empty for no limit.',
    });
  } else if (min !== null && max !== null && min > max) {
    problems.push({ field: 'max_select', detail: 'Maximum cannot be smaller than the minimum.' });
  }
  if (problems.length > 0) throw validationFailed(problems);

  if (existing) {
    Object.assign(existing, { name, min_select: min, max_select: max });
    return existing;
  }
  const created: GroupRow = {
    id: nextId('GRP'),
    name,
    min_select: min as number,
    max_select: max,
    deleted_at: null,
  };
  s.groups.push(created);
  return created;
}

function saveModifier(
  req: MockRequest,
  group: GroupRow,
  existing: ModifierRow | null,
): ModifierRow {
  const s = catalogState();
  const body = req.body;
  const name = has(body, 'name') || !existing ? text(body, 'name') : existing.name;
  // An update replaces the linked trio: absent keys mean "no link" (T-036 AC-54).
  const productId = idOrNull(body, 'linked_product_id');
  const unitId = idOrNull(body, 'linked_unit_id');
  const rawQuantity = body['linked_quantity'];
  const hasQuantity = rawQuantity !== undefined && rawQuantity !== null && rawQuantity !== '';
  const price = numberOf(
    has(body, 'price_delta') || !existing ? (body['price_delta'] ?? 0) : existing.price_delta,
  );
  const cost = numberOf(
    has(body, 'cost_delta') || !existing ? (body['cost_delta'] ?? 0) : existing.cost_delta,
  );

  const problems: { field: string; detail: string }[] = [];
  if (name === '') problems.push({ field: 'name', detail: 'Name is required.' });
  if (price === null)
    problems.push({ field: 'price_delta', detail: 'Price change must be a number.' });
  if (cost === null)
    problems.push({ field: 'cost_delta', detail: 'Cost change must be a number.' });
  const linked = [productId !== null, hasQuantity, unitId !== null];
  if (linked.some(Boolean) && !linked.every(Boolean)) {
    problems.push({
      field: 'linked_product_id',
      detail: 'Linked product, quantity and unit go together.',
    });
  }
  const quantity = hasQuantity ? numberOf(rawQuantity) : null;
  if (hasQuantity && (quantity === null || quantity <= 0)) {
    problems.push({ field: 'linked_quantity', detail: 'Linked quantity must be greater than 0.' });
  }
  if (problems.length > 0) throw validationFailed(problems);

  if (productId !== null) {
    const product = productById(productId);
    if (!product || product.deleted_at !== null) {
      throw validationFailed([
        { field: 'linked_product_id', detail: 'The linked product does not exist.' },
      ]);
    }
    const unit = unitById(unitId);
    if (!unit)
      throw validationFailed([
        { field: 'linked_unit_id', detail: 'The linked unit does not exist.' },
      ]);
    const stock = unitById(product.stock_unit_id);
    if (stock && unit.dimension !== stock.dimension) {
      throw refusal(
        422,
        'catalog.dimension_mismatch',
        'That unit has another dimension than the linked product.',
        'linked_unit_id',
        `${unit.code} is ${unit.dimension} but ${product.sku} is counted in ${stock.code} (${stock.dimension}).`,
      );
    }
  }

  const values = {
    name,
    price_delta: fixed(price as number),
    cost_delta: fixed(cost as number),
    linked_product_id: productId,
    linked_quantity: productId !== null ? fixed(quantity as number) : null,
    linked_unit_id: productId !== null ? unitId : null,
  };
  if (existing) {
    Object.assign(existing, values);
    return existing;
  }
  const created: ModifierRow = { id: nextId('MOD'), group_id: group.id, ...values };
  s.modifiers.push(created);
  return created;
}

function handleGroups(req: MockRequest): MockReply | null {
  const s = catalogState();
  if (req.segments.length === 1) {
    if (req.method === 'GET') {
      const live = s.groups.filter((g) => g.deleted_at === null);
      return listReply(live, req, (g) => [g.name]);
    }
    if (req.method === 'POST') return { status: 201, data: saveGroup(req, null) };
    return null;
  }
  const group = groupById(req.segments[1]);
  if (!group) throw notFound('The modifier group');
  if (req.segments.length === 2) {
    if (req.method === 'GET') return { status: 200, data: group };
    if (group.deleted_at !== null) throw notFound('The modifier group');
    if (req.method === 'PUT') return { status: 200, data: saveGroup(req, group) };
    if (req.method === 'DELETE') {
      const attached = s.attachments.find((a) => {
        const product = productById(a.product_id);
        return a.group_id === group.id && product !== undefined && product.deleted_at === null;
      });
      if (attached) {
        const product = productById(attached.product_id);
        throw inUse(
          `Modifier group ${group.name} cannot be deleted: it is attached to the product ${product?.sku ?? ''}.`,
        );
      }
      group.deleted_at = nowIso();
      return { status: 200, data: null };
    }
    return null;
  }
  if (req.segments[2] !== 'modifiers') return null;
  if (req.segments.length === 3) {
    if (req.method === 'GET') {
      const rows = s.modifiers.filter((m) => m.group_id === group.id).map(projectModifier);
      return listReply(rows, req, (m) => [m.name]);
    }
    if (req.method === 'POST') {
      if (group.deleted_at !== null) throw notFound('The modifier group');
      return { status: 201, data: projectModifier(saveModifier(req, group, null)) };
    }
    return null;
  }
  if (req.segments.length !== 4) return null;
  const row = s.modifiers.find((m) => m.id === req.segments[3] && m.group_id === group.id);
  if (!row) throw notFound('The modifier');
  if (req.method === 'GET') return { status: 200, data: projectModifier(row) };
  if (req.method === 'PUT')
    return { status: 200, data: projectModifier(saveModifier(req, group, row)) };
  if (req.method === 'DELETE') {
    s.modifiers.splice(s.modifiers.indexOf(row), 1);
    return { status: 200, data: null };
  }
  return null;
}

/** Modifier group and modifier routes; null when the path is not one of them. */
export function handleModifierGroups(req: MockRequest): MockReply | null {
  return req.segments[0] === 'modifier-groups' ? handleGroups(req) : null;
}
