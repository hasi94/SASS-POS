import {
  PRICE_MODES,
  PRODUCT_TYPES,
  Product,
  ProductBarcode,
  TRACKING_MODES,
} from '../../contracts/catalog/catalog.models';
import {
  BarcodeRow,
  MockReply,
  MockRequest,
  ProductRow,
  brandById,
  catalogState,
  categoryById,
  fixed,
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

function projectProduct(row: ProductRow): Product {
  return {
    ...row,
    category_name: categoryById(row.category_id)?.name ?? '',
    brand_name: brandById(row.brand_id)?.name ?? null,
    stock_unit_code: unitById(row.stock_unit_id)?.code ?? '',
  };
}

function projectBarcode(row: BarcodeRow): ProductBarcode {
  return { ...row, unit_code: unitById(row.unit_id)?.code ?? null };
}

type Problems = { field: string; detail: string }[];

/** A boolean field: the body value when it is a boolean, else the fallback. */
function flag(body: Record<string, unknown>, field: string, fallback: boolean): boolean {
  const v = body[field];
  return typeof v === 'boolean' ? v : fallback;
}

function saveProduct(req: MockRequest, existing: ProductRow | null): ProductRow {
  const s = catalogState();
  const body = req.body;
  const keep = <T>(field: string, current: T | undefined, read: () => T): T =>
    has(body, field) || !existing ? read() : (current as T);

  if (existing) {
    const sent = numberOf(body['version']);
    if (sent !== existing.version) {
      throw refusal(409, 'catalog.stale_version', 'This product was changed by someone else.');
    }
    const stock = idOrNull(body, 'stock_unit_id');
    if (stock !== null && stock !== existing.stock_unit_id) {
      throw refusal(
        422,
        'catalog.immutable',
        'The stock unit of a product cannot change.',
        'stock_unit_id',
        'Create a new product instead of changing the stock unit.',
      );
    }
  }

  const type = keep('type', existing?.type, () => text(body, 'type')) as ProductRow['type'];
  const sku = keep('sku', existing?.sku, () => text(body, 'sku'));
  const name = keep('name', existing?.name, () => text(body, 'name'));
  const categoryId = keep('category_id', existing?.category_id, () =>
    idOrNull(body, 'category_id'),
  );
  const brandId = keep('brand_id', existing?.brand_id, () => idOrNull(body, 'brand_id'));
  const stockId = existing ? existing.stock_unit_id : idOrNull(body, 'stock_unit_id');
  const purchaseId = keep('purchase_unit_id', existing?.purchase_unit_id, () =>
    idOrNull(body, 'purchase_unit_id'),
  );
  const salesId = keep('sales_unit_id', existing?.sales_unit_id, () =>
    idOrNull(body, 'sales_unit_id'),
  );
  const priceMode = keep(
    'price_mode',
    existing?.price_mode,
    () => text(body, 'price_mode') || 'per_unit',
  ) as ProductRow['price_mode'];
  const tracking = keep(
    'tracking_mode',
    existing?.tracking_mode,
    () => text(body, 'tracking_mode') || 'quantity',
  ) as ProductRow['tracking_mode'];
  const plu: string | null = has(body, 'plu_code')
    ? body['plu_code'] === null
      ? null
      : text(body, 'plu_code')
    : (existing?.plu_code ?? null);
  const parentId =
    type === 'variant'
      ? keep('parent_id', existing?.parent_id, () => idOrNull(body, 'parent_id'))
      : null;

  const problems: Problems = [];
  if (!(PRODUCT_TYPES as readonly string[]).includes(type))
    problems.push({ field: 'type', detail: 'Choose a product type.' });
  if (sku === '') problems.push({ field: 'sku', detail: 'SKU is required.' });
  else if (sku.length > 50)
    problems.push({ field: 'sku', detail: 'SKU can be at most 50 characters.' });
  if (name === '') problems.push({ field: 'name', detail: 'Name is required.' });
  if (categoryId === null) problems.push({ field: 'category_id', detail: 'Category is required.' });
  if (stockId === null)
    problems.push({ field: 'stock_unit_id', detail: 'Stock unit is required.' });
  if (!(PRICE_MODES as readonly string[]).includes(priceMode))
    problems.push({ field: 'price_mode', detail: 'Choose a price mode.' });
  if (!(TRACKING_MODES as readonly string[]).includes(tracking))
    problems.push({ field: 'tracking_mode', detail: 'Choose a tracking mode.' });
  if (plu !== null && plu.length > 10)
    problems.push({ field: 'plu_code', detail: 'PLU can be at most 10 characters.' });
  if (type === 'variant' && parentId === null)
    problems.push({ field: 'parent_id', detail: 'A variant needs a parent product.' });
  if (problems.length > 0) throw validationFailed(problems);

  const category = categoryById(categoryId);
  if (!category || category.deleted_at !== null) {
    throw validationFailed([{ field: 'category_id', detail: 'The category does not exist.' }]);
  }
  if (brandId !== null && (!brandById(brandId) || brandById(brandId)?.deleted_at !== null)) {
    throw validationFailed([{ field: 'brand_id', detail: 'The brand does not exist.' }]);
  }
  const stock = unitById(stockId);
  if (!stock)
    throw validationFailed([{ field: 'stock_unit_id', detail: 'The stock unit does not exist.' }]);
  for (const [field, id] of [
    ['purchase_unit_id', purchaseId],
    ['sales_unit_id', salesId],
  ] as const) {
    if (id === null) continue;
    const unit = unitById(id);
    if (!unit) throw validationFailed([{ field, detail: 'The unit does not exist.' }]);
    if (unit.dimension !== stock.dimension) {
      throw refusal(
        422,
        'catalog.dimension_mismatch',
        'That unit has another dimension than the stock unit.',
        field,
        `${unit.code} is ${unit.dimension} but the stock unit ${stock.code} is ${stock.dimension}.`,
      );
    }
  }
  if (parentId !== null) {
    const parent = productById(parentId);
    if (!parent || parent.deleted_at !== null || parent.type !== 'variant_parent') {
      throw validationFailed([
        { field: 'parent_id', detail: 'The parent must be a variant parent product.' },
      ]);
    }
  }
  const sameSku = s.products.find(
    (p) =>
      p.deleted_at === null && p.id !== existing?.id && p.sku.toLowerCase() === sku.toLowerCase(),
  );
  if (sameSku) {
    throw refusal(
      422,
      'catalog.duplicate',
      'That SKU is already used.',
      'sku',
      `SKU ${sku} is already used by product ${sameSku.name}.`,
    );
  }
  if (plu) {
    const samePlu = s.products.find(
      (p) => p.deleted_at === null && p.id !== existing?.id && p.plu_code === plu,
    );
    if (samePlu) {
      throw refusal(
        422,
        'catalog.duplicate',
        'That PLU is already used.',
        'plu_code',
        `PLU ${plu} is already used by product ${samePlu.name} (${samePlu.sku}).`,
      );
    }
  }

  const values = {
    type,
    parent_id: parentId,
    sku,
    name,
    category_id: categoryId as string,
    brand_id: brandId,
    purchase_unit_id: purchaseId,
    sales_unit_id: salesId,
    is_weighable: flag(body, 'is_weighable', existing?.is_weighable ?? false),
    price_mode: priceMode,
    plu_code: plu === '' ? null : plu,
    track_stock: flag(body, 'track_stock', existing?.track_stock ?? true),
    tracking_mode: tracking,
    variant_options: has(body, 'variant_options')
      ? body['variant_options']
      : (existing?.variant_options ?? null),
    variant_values: has(body, 'variant_values')
      ? body['variant_values']
      : (existing?.variant_values ?? null),
  };
  if (existing) {
    Object.assign(existing, values);
    existing.version += 1;
    return existing;
  }
  const created: ProductRow = {
    id: nextId('PRO'),
    stock_unit_id: stockId as string,
    is_active: true,
    version: 1,
    deleted_at: null,
    ...values,
  };
  s.products.push(created);
  return created;
}

function handleProducts(req: MockRequest): MockReply | null {
  const s = catalogState();
  if (req.segments.length === 1) {
    if (req.method === 'GET') {
      const type = req.query.get('type');
      const parent = req.query.get('parent_id');
      const rows = s.products
        .filter((p) => p.deleted_at === null)
        .filter((p) => !type || p.type === type)
        .filter((p) => !parent || p.parent_id === parent)
        .map(projectProduct);
      return listReply(rows, req, (p) => [p.sku, p.name, p.plu_code ?? '']);
    }
    if (req.method === 'POST') {
      return { status: 201, data: projectProduct(saveProduct(req, null)) };
    }
    return null;
  }
  const row = productById(req.segments[1]);
  if (!row) throw notFound('The product');
  if (req.segments.length === 2) {
    if (req.method === 'GET') return { status: 200, data: projectProduct(row) };
    if (row.deleted_at !== null) throw notFound('The product');
    if (req.method === 'PUT') return { status: 200, data: projectProduct(saveProduct(req, row)) };
    if (req.method === 'DELETE') {
      const variant = s.products.find((p) => p.parent_id === row.id && p.deleted_at === null);
      if (variant) {
        throw inUse(
          `Product ${row.sku} cannot be deleted: it has the active variant ${variant.sku}.`,
        );
      }
      const modifier = s.modifiers.find((m) => m.linked_product_id === row.id);
      if (modifier) {
        throw inUse(
          `Product ${row.sku} cannot be deleted: the modifier ${modifier.name} is linked to it.`,
        );
      }
      row.deleted_at = nowIso();
      return { status: 200, data: null };
    }
    return null;
  }
  if (req.segments[2] === 'barcodes') {
    return handleBarcodes(req, row);
  }
  return null;
}

function saveBarcode(
  req: MockRequest,
  product: ProductRow,
  existing: BarcodeRow | null,
): BarcodeRow {
  const s = catalogState();
  const body = req.body;
  const value = existing ? existing.barcode : text(body, 'barcode');
  if (existing && has(body, 'barcode') && text(body, 'barcode') !== existing.barcode) {
    throw refusal(
      422,
      'catalog.immutable',
      'A barcode value cannot change.',
      'barcode',
      'Delete the barcode and add a new one.',
    );
  }
  const unitId = has(body, 'unit_id') || !existing ? idOrNull(body, 'unit_id') : existing.unit_id;
  const rawQuantity =
    has(body, 'quantity') || !existing ? (body['quantity'] ?? 1) : existing.quantity;
  const quantity = numberOf(rawQuantity);

  const problems: Problems = [];
  if (value === '') problems.push({ field: 'barcode', detail: 'Barcode is required.' });
  else if (value.length > 64)
    problems.push({ field: 'barcode', detail: 'Barcode can be at most 64 characters.' });
  if (quantity === null || quantity <= 0)
    problems.push({ field: 'quantity', detail: 'Quantity must be greater than 0.' });
  if (problems.length > 0) throw validationFailed(problems);

  if (!existing) {
    const owner = s.barcodes.find((b) => b.barcode === value);
    if (owner) {
      const ownerProduct = productById(owner.product_id);
      throw refusal(
        422,
        'catalog.duplicate',
        'That barcode is already used.',
        'barcode',
        `Barcode ${value} already belongs to product ${ownerProduct?.name ?? owner.product_id} (${ownerProduct?.sku ?? ''}).`,
      );
    }
  }
  if (unitId !== null) {
    const unit = unitById(unitId);
    const stock = unitById(product.stock_unit_id);
    if (!unit) throw validationFailed([{ field: 'unit_id', detail: 'The unit does not exist.' }]);
    if (stock && unit.dimension !== stock.dimension) {
      throw refusal(
        422,
        'catalog.dimension_mismatch',
        'That unit has another dimension than the stock unit.',
        'unit_id',
        `${unit.code} is ${unit.dimension} but the stock unit ${stock.code} is ${stock.dimension}.`,
      );
    }
  }

  const makePrimary = flag(body, 'is_primary', existing?.is_primary ?? false);
  if (makePrimary) {
    for (const other of s.barcodes.filter(
      (b) => b.product_id === product.id && b.id !== existing?.id,
    )) {
      other.is_primary = false;
    }
  }
  const values = { unit_id: unitId, quantity: fixed(quantity as number), is_primary: makePrimary };
  if (existing) {
    Object.assign(existing, values);
    return existing;
  }
  const created: BarcodeRow = {
    id: nextId('BCD'),
    product_id: product.id,
    barcode: value,
    ...values,
  };
  s.barcodes.push(created);
  return created;
}

function handleBarcodes(req: MockRequest, product: ProductRow): MockReply | null {
  const s = catalogState();
  if (req.segments.length === 3) {
    if (req.method === 'GET') {
      const rows = s.barcodes.filter((b) => b.product_id === product.id).map(projectBarcode);
      return listReply(rows, req, (b) => [b.barcode]);
    }
    if (req.method === 'POST') {
      if (product.deleted_at !== null) throw notFound('The product');
      return { status: 201, data: projectBarcode(saveBarcode(req, product, null)) };
    }
    return null;
  }
  if (req.segments.length !== 4) return null;
  const row = s.barcodes.find((b) => b.id === req.segments[3] && b.product_id === product.id);
  if (!row) throw notFound('The barcode');
  if (req.method === 'GET') return { status: 200, data: projectBarcode(row) };
  if (req.method === 'PUT')
    return { status: 200, data: projectBarcode(saveBarcode(req, product, row)) };
  if (req.method === 'DELETE') {
    s.barcodes.splice(s.barcodes.indexOf(row), 1);
    return { status: 200, data: null };
  }
  return null;
}

/** Product, variant and barcode routes; null when the path is not one of them. */
export function handleProductsAndBarcodes(req: MockRequest): MockReply | null {
  return req.segments[0] === 'products' ? handleProducts(req) : null;
}
