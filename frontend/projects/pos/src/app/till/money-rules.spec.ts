import { readFileSync, readdirSync } from 'node:fs';

/**
 * AC-77 (no floating-point money anywhere in the till) and the AC-7 seam rules (no component
 * names an adapter or the mock), as a source scan with a canary that proves the scan can fail.
 * The only permitted money handling is `decimal-string.ts`, which this scan excludes by path;
 * `sales-mock/**` is out of scope by path (it does its own exact BigInt arithmetic and is never
 * imported by application code, which the seam rules below enforce).
 */

const TILL_DIR = 'projects/pos/src/app/till';
const SALES_DIR = 'projects/shared/src/lib/sales';
const PROJECTS_DIR = 'projects';
const CANARY = `${TILL_DIR}/testing/money-rules.canary.txt`;

/** Fewer files than this means the scan is looking in the wrong place. */
const FILE_COUNT_FLOOR = 40;

const MONEY_FIELDS = [
  'total',
  'subtotal',
  'amount',
  'tendered',
  'balance_due',
  'paid_total',
  'change_due',
  'change_given',
  'unit_price',
  'line_total',
  'list_price',
  'tax_amount',
  'discount_amount',
  'quantity',
  'base_quantity',
  'refunded_quantity',
  'refundable_quantity',
  'opening_cash',
  'counted_cash',
  'expected_cash',
  'variance',
].join('|');

interface Rule {
  id: string;
  pattern: RegExp;
}

const MONEY_RULES: Rule[] = [
  { id: 'parseFloat', pattern: /\bparseFloat\b/ },
  { id: 'parseInt(', pattern: /\bparseInt\s*\(/ },
  { id: 'Number(', pattern: /\bNumber\s*\(/ },
  { id: '.toFixed(', pattern: /\.toFixed\s*\(/ },
  { id: 'Math.round', pattern: /\bMath\.round\b/ },
  { id: 'Math.floor', pattern: /\bMath\.floor\b/ },
  { id: 'Math.ceil', pattern: /\bMath\.ceil\b/ },
  { id: 'toLocaleString', pattern: /toLocaleString/ },
  { id: 'Intl.NumberFormat', pattern: /\bIntl\.NumberFormat\b/ },
  { id: 'unary plus', pattern: /[=(,:?]\s*\+\s*[\w$.]/ },
  {
    id: 'plus beside a money or quantity field',
    pattern: new RegExp(`(\\b(?:${MONEY_FIELDS})\\b\\s*\\+)|(\\+\\s*\\b(?:${MONEY_FIELDS})\\b)`),
  },
];

const SEAM_RULES: Rule[] = [
  { id: 'ServerLinePricer', pattern: /\bServerLinePricer\b/ },
  { id: 'ServerBarcodeResolver', pattern: /\bServerBarcodeResolver\b/ },
  { id: 'sales-mock path', pattern: /sales-mock/ },
  { id: 'mock-decimal', pattern: /mock-decimal/ },
  { id: 'mock-pricing', pattern: /mock-pricing/ },
  { id: 'salesMock', pattern: /\bsalesMock/ },
];

const INTERCEPTOR_IMPORT: Rule = {
  id: 'import of salesMockInterceptor',
  pattern: /import[^;]*\bsalesMockInterceptor\b/,
};

interface Violation {
  file: string;
  line: number;
  rule: string;
}

function findViolations(file: string, text: string, rules: readonly Rule[]): Violation[] {
  const found: Violation[] = [];
  const lines = text.split('\n');
  for (const rule of rules) {
    for (let i = 0; i < lines.length; i++) {
      if (rule.pattern.test(lines[i] ?? '')) {
        found.push({ file, line: i + 1, rule: rule.id });
      }
    }
  }
  return found;
}

/** Files under `dir`. A name that cannot be listed as a directory is a file. */
function walk(dir: string): string[] {
  const out: string[] = [];
  for (const name of readdirSync(dir)) {
    const path = `${dir}/${name}`;
    let children: string[] | null;
    try {
      children = readdirSync(path);
    } catch {
      children = null;
    }
    if (children === null) {
      out.push(path);
    } else {
      out.push(...walk(path));
    }
  }
  return out;
}

function isSource(path: string): boolean {
  return /\.(ts|html)$/.test(path) && !path.endsWith('.spec.ts');
}

function inScope(path: string): boolean {
  return (
    isSource(path) &&
    !path.includes('/testing/') &&
    !path.includes('/fixtures/') &&
    !path.endsWith('/decimal-string.ts')
  );
}

const scanned = [...walk(TILL_DIR), ...walk(SALES_DIR)].filter(inScope).sort();
const tillFiles = scanned.filter((f) => f.startsWith(TILL_DIR));

function report(violations: Violation[]): string[] {
  return violations.map((v) => `${v.file}:${v.line} ${v.rule}`);
}

describe('money rules (AC-77)', () => {
  it('scans a non-vacuous set of files', () => {
    expect(scanned.length).toBeGreaterThan(FILE_COUNT_FLOOR);
    expect(tillFiles.length).toBeGreaterThan(0);
    expect(scanned.some((f) => f.startsWith(SALES_DIR))).toBe(true);
    // Excluded by path, and really excluded.
    expect(scanned.some((f) => f.endsWith('/decimal-string.ts'))).toBe(false);
    expect(scanned.some((f) => f.endsWith('.spec.ts'))).toBe(false);
    expect(scanned.some((f) => f.includes('/testing/'))).toBe(false);
  });

  it('finds no floating-point money handling in the till or the sales contract', () => {
    const violations = scanned.flatMap((f) =>
      findViolations(f, readFileSync(f, 'utf8'), MONEY_RULES),
    );
    expect(report(violations)).toEqual([]);
  });

  it('proves the scan can fail: the canary trips every money rule', () => {
    const fired = new Set(
      findViolations(CANARY, readFileSync(CANARY, 'utf8'), MONEY_RULES).map((v) => v.rule),
    );
    expect([...fired].sort()).toEqual(MONEY_RULES.map((r) => r.id).sort());
  });

  it('trips on a violation planted in a real file, and not on the file as it is', () => {
    const real = scanned[0] ?? '';
    const text = readFileSync(real, 'utf8');
    expect(findViolations(real, text, MONEY_RULES)).toEqual([]);
    const planted = `${text}\nconst planted = parseFloat(sale.total);\n`;
    expect(findViolations(real, planted, MONEY_RULES).map((v) => v.rule)).toContain('parseFloat');
  });

  it('does not trip on ordinary code', () => {
    const clean = [
      'const next = lines.length + 1;',
      'const text = formatMoney(sale.total);',
      'const same = compareDecimal(a, b) === 0;',
      'const label = `Balance remaining: ${formatMoney(due)}`;',
      'items.push(item);',
      'const totals = lines.map((l) => l.id);',
    ].join('\n');
    expect(findViolations('clean.ts', clean, MONEY_RULES)).toEqual([]);
  });
});

describe('seam rules (AC-7)', () => {
  it('no till file names an adapter or the mock', () => {
    const violations = tillFiles.flatMap((f) =>
      findViolations(f, readFileSync(f, 'utf8'), SEAM_RULES),
    );
    expect(report(violations)).toEqual([]);
  });

  it('no file outside the environment files imports salesMockInterceptor', () => {
    const files = walk(PROJECTS_DIR).filter(
      (f) =>
        f.endsWith('.ts') &&
        !f.endsWith('.spec.ts') &&
        !f.includes('/testing/') &&
        !f.includes('/sales-mock/') &&
        !f.includes('/environments/'),
    );
    expect(files.length).toBeGreaterThan(FILE_COUNT_FLOOR);
    const violations = files.flatMap((f) =>
      findViolations(f, readFileSync(f, 'utf8'), [INTERCEPTOR_IMPORT]),
    );
    expect(report(violations)).toEqual([]);
  });

  it('only the pos development environment reaches for the sales mock', () => {
    const named = (path: string): boolean => /salesMock/.test(readFileSync(path, 'utf8'));
    expect(named('projects/pos/src/environments/environment.development.ts')).toBe(true);
    expect(named('projects/pos/src/environments/environment.ts')).toBe(false);
    expect(named('projects/admin/src/environments/environment.development.ts')).toBe(false);
    expect(named('projects/admin/src/environments/environment.ts')).toBe(false);
  });

  it('proves the scan can fail: the canary trips every seam rule', () => {
    const text = readFileSync(CANARY, 'utf8');
    const fired = new Set(
      [
        ...findViolations(CANARY, text, SEAM_RULES),
        ...findViolations(CANARY, text, [INTERCEPTOR_IMPORT]),
      ].map((v) => v.rule),
    );
    expect([...fired].sort()).toEqual([...SEAM_RULES, INTERCEPTOR_IMPORT].map((r) => r.id).sort());
  });
});
