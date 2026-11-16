import {
  UNIT_DIMENSIONS,
  Unit,
  UnitConversion,
  UnitDimension,
} from '../../contracts/catalog/catalog.models';
import {
  ConversionRow,
  MockReply,
  MockRequest,
  UnitRow,
  catalogState,
  fixed,
  has,
  idOrNull,
  inUse,
  listReply,
  nextId,
  notFound,
  numberOf,
  productById,
  refusal,
  text,
  unitById,
  validationFailed,
} from './mock-catalog.store';

function projectUnit(row: UnitRow): Unit {
  return { ...row, base_unit_code: unitById(row.base_unit_id)?.code ?? null };
}

function projectConversion(row: ConversionRow): UnitConversion {
  return {
    id: row.id,
    product_id: row.product_id,
    product_name: productById(row.product_id)?.name ?? null,
    from_unit_id: row.from_unit_id,
    from_unit_code: unitById(row.from_unit_id)?.code ?? '',
    to_unit_id: row.to_unit_id,
    to_unit_code: unitById(row.to_unit_id)?.code ?? '',
    factor: row.factor,
  };
}

function saveUnit(req: MockRequest, existing: UnitRow | null): UnitRow {
  const s = catalogState();
  const body = req.body;
  const pick = (field: string, current: string | undefined): string =>
    has(body, field) || !existing ? text(body, field) : (current ?? '');
  const code = pick('code', existing?.code);
  const name = pick('name', existing?.name);
  const dimensionText = text(body, 'dimension');

  if (existing && dimensionText !== '' && dimensionText !== existing.dimension) {
    throw refusal(
      422,
      'catalog.immutable',
      'A unit dimension cannot change.',
      'dimension',
      `Unit ${existing.code} is ${existing.dimension} and stays that way.`,
    );
  }
  const dimension = (existing ? existing.dimension : dimensionText) as UnitDimension;

  const problems: { field: string; detail: string }[] = [];
  if (code === '') problems.push({ field: 'code', detail: 'Code is required.' });
  if (name === '') problems.push({ field: 'name', detail: 'Name is required.' });
  if (!existing && !UNIT_DIMENSIONS.includes(dimension)) {
    problems.push({ field: 'dimension', detail: 'Choose one of the listed dimensions.' });
  }
  const rawPrecision = has(body, 'precision') || !existing ? body['precision'] : existing.precision;
  const precision = rawPrecision === undefined ? 4 : numberOf(rawPrecision);
  if (precision === null || !Number.isInteger(precision) || precision < 0 || precision > 4) {
    problems.push({ field: 'precision', detail: 'Precision must be a whole number from 0 to 4.' });
  }
  const baseId =
    has(body, 'base_unit_id') || !existing ? idOrNull(body, 'base_unit_id') : existing.base_unit_id;
  const rawFactor = has(body, 'factor') || !existing ? body['factor'] : existing.factor;
  const factorBlank = rawFactor === undefined || rawFactor === null || rawFactor === '';
  const factor = factorBlank ? null : numberOf(rawFactor);
  if ((baseId === null) !== factorBlank) {
    problems.push({ field: 'factor', detail: 'Base unit and factor go together.' });
  } else if (!factorBlank && (factor === null || factor <= 0)) {
    problems.push({ field: 'factor', detail: 'Factor must be greater than 0.' });
  }
  if (problems.length > 0) throw validationFailed(problems);

  const clash = s.units.find(
    (u) => u.id !== existing?.id && u.code.toLowerCase() === code.toLowerCase(),
  );
  if (clash) {
    throw refusal(
      422,
      'catalog.duplicate',
      'That unit code is already used.',
      'code',
      `Code ${code} is already used by unit ${clash.name}.`,
    );
  }
  if (baseId !== null) {
    const base = unitById(baseId);
    if (!base) {
      throw validationFailed([{ field: 'base_unit_id', detail: 'The base unit does not exist.' }]);
    }
    if (base.dimension !== dimension) {
      throw refusal(
        422,
        'catalog.dimension_mismatch',
        'The base unit has another dimension.',
        'base_unit_id',
        `${base.code} is ${base.dimension}, not ${dimension}.`,
      );
    }
  }

  const values = {
    code,
    name,
    precision: precision as number,
    base_unit_id: baseId,
    factor: factor === null ? null : fixed(factor),
  };
  if (existing) {
    Object.assign(existing, values);
    return existing;
  }
  const created: UnitRow = { id: nextId('UNI'), dimension, is_active: true, ...values };
  s.units.push(created);
  return created;
}

function unitBlocker(row: UnitRow): string | null {
  const s = catalogState();
  const product = s.products.find(
    (p) =>
      p.deleted_at === null &&
      [p.stock_unit_id, p.purchase_unit_id, p.sales_unit_id].includes(row.id),
  );
  if (product) return `the product ${product.sku} uses it`;
  const barcode = s.barcodes.find((b) => b.unit_id === row.id);
  if (barcode) return `the barcode ${barcode.barcode} uses it`;
  const conversion = s.conversions.find(
    (c) => c.from_unit_id === row.id || c.to_unit_id === row.id,
  );
  if (conversion) return `a unit conversion (${conversion.id}) uses it`;
  const derived = s.units.find((u) => u.base_unit_id === row.id);
  if (derived) return `the unit ${derived.code} is based on it`;
  const modifier = s.modifiers.find((m) => m.linked_unit_id === row.id);
  if (modifier) return `the modifier ${modifier.name} links to it`;
  return null;
}

function handleUnits(req: MockRequest): MockReply | null {
  const s = catalogState();
  if (req.segments.length === 1) {
    if (req.method === 'GET') {
      return listReply(s.units.map(projectUnit), req, (u) => [u.code, u.name]);
    }
    if (req.method === 'POST') {
      return { status: 201, data: projectUnit(saveUnit(req, null)) };
    }
    return null;
  }
  if (req.segments.length !== 2) return null;
  const row = unitById(req.segments[1]);
  if (!row) throw notFound('The unit');
  if (req.method === 'GET') return { status: 200, data: projectUnit(row) };
  if (req.method === 'PUT') return { status: 200, data: projectUnit(saveUnit(req, row)) };
  if (req.method === 'DELETE') {
    const blocker = unitBlocker(row);
    if (blocker) {
      throw inUse(`Unit ${row.code} cannot be deleted: ${blocker}.`);
    }
    s.units.splice(s.units.indexOf(row), 1);
    return { status: 200, data: null };
  }
  return null;
}

function createConversion(req: MockRequest): ConversionRow {
  const s = catalogState();
  const body = req.body;
  const fromId = idOrNull(body, 'from_unit_id');
  const toId = idOrNull(body, 'to_unit_id');
  const productId = idOrNull(body, 'product_id');
  const factor = numberOf(body['factor']);

  const problems: { field: string; detail: string }[] = [];
  if (fromId === null) problems.push({ field: 'from_unit_id', detail: 'From unit is required.' });
  if (toId === null) problems.push({ field: 'to_unit_id', detail: 'To unit is required.' });
  if (fromId !== null && fromId === toId) {
    problems.push({ field: 'to_unit_id', detail: 'From and to units must be different.' });
  }
  if (factor === null || factor <= 0) {
    problems.push({ field: 'factor', detail: 'Factor must be greater than 0.' });
  }
  if (problems.length > 0) throw validationFailed(problems);

  const from = unitById(fromId);
  const to = unitById(toId);
  if (!from)
    throw validationFailed([{ field: 'from_unit_id', detail: 'The from unit does not exist.' }]);
  if (!to) throw validationFailed([{ field: 'to_unit_id', detail: 'The to unit does not exist.' }]);
  if (from.dimension !== to.dimension) {
    throw refusal(
      422,
      'catalog.dimension_mismatch',
      'Units of different dimensions cannot be converted.',
      'to_unit_id',
      `${from.code} is ${from.dimension} but ${to.code} is ${to.dimension}.`,
    );
  }
  if (productId !== null) {
    const product = productById(productId);
    if (!product || product.deleted_at !== null) {
      throw validationFailed([{ field: 'product_id', detail: 'The product does not exist.' }]);
    }
    if (product.stock_unit_id !== to.id) {
      throw validationFailed([
        {
          field: 'to_unit_id',
          detail: `For ${product.sku} the to unit must be its stock unit, ${unitById(product.stock_unit_id)?.code ?? ''}.`,
        },
      ]);
    }
  }
  const twin = s.conversions.find(
    (c) => c.from_unit_id === from.id && c.to_unit_id === to.id && c.product_id === productId,
  );
  if (twin) {
    throw refusal(
      422,
      'catalog.duplicate',
      'That conversion already exists.',
      'to_unit_id',
      `Conversion ${twin.id} already converts ${from.code} to ${to.code}${productId ? ' for this product' : ' for all products'}.`,
    );
  }
  const created: ConversionRow = {
    id: nextId('CNV'),
    product_id: productId,
    from_unit_id: from.id,
    to_unit_id: to.id,
    factor: fixed(factor as number),
  };
  s.conversions.push(created);
  return created;
}

function handleConversions(req: MockRequest): MockReply | null {
  const s = catalogState();
  if (req.segments.length === 1) {
    if (req.method === 'GET') {
      const wanted = req.query.get('product_id');
      const rows = s.conversions
        .filter((c) => wanted === null || wanted === '' || c.product_id === wanted)
        .map(projectConversion);
      return listReply(rows, req, (c) => [c.from_unit_code, c.to_unit_code, c.product_name ?? '']);
    }
    if (req.method === 'POST') {
      return { status: 201, data: projectConversion(createConversion(req)) };
    }
    return null;
  }
  if (req.segments.length !== 2) return null;
  const row = s.conversions.find((c) => c.id === req.segments[1]);
  if (!row) throw notFound('The unit conversion');
  if (req.method === 'GET') return { status: 200, data: projectConversion(row) };
  if (req.method === 'DELETE') {
    s.conversions.splice(s.conversions.indexOf(row), 1);
    return { status: 200, data: null };
  }
  return null;
}

/** Unit and unit conversion routes; null when the path is not one of them. */
export function handleUnitsAndConversions(req: MockRequest): MockReply | null {
  switch (req.segments[0]) {
    case 'units':
      return handleUnits(req);
    case 'unit-conversions':
      return handleConversions(req);
    default:
      return null;
  }
}
