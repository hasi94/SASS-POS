import { readEngineSources } from './testing/fixture-files';
import { BANNED, scanSource, stripCommentsAndStrings } from './testing/source-scan';

// The engine's own files, read in place at collection time. An empty or missing directory throws
// here, which fails the spec file: an empty glob is a failure, never a pass.
const sources = readEngineSources();

function rulesIn(src: string): string[] {
  return scanSource('scratch.ts', src).map((violation) => violation.rule);
}

describe('engine source scan: the engine files', () => {
  it('lists the files it scanned, and the list is not empty', () => {
    console.info(
      'engine source scan: ' +
        String(sources.length) +
        ' files: ' +
        sources.map((source) => source.name).join(', '),
    );

    expect(sources.length).toBeGreaterThan(0);
    expect(sources.reduce((total, source) => total + source.text.length, 0)).toBeGreaterThan(0);
  });

  it('covers every engine module and never a spec or the testing directory', () => {
    const names = sources.map((source) => source.name);

    for (const expected of [
      'refusal.ts',
      'decimal.ts',
      'shape.ts',
      'byte-order.ts',
      'rules.ts',
      'types.ts',
      'tax.ts',
      'price-ladder.ts',
      'discount.ts',
      'line.ts',
      'index.ts',
    ]) {
      expect(names).toContain(expected);
    }

    expect(names.some((name) => name.endsWith('.spec.ts'))).toBe(false);
    expect(names.some((name) => name.includes('/'))).toBe(false);
  });

  it.each(sources.map((source) => [source.name, source] as const))(
    '%s is clean',
    (_name, source) => {
      expect(scanSource(source.name, source.text)).toEqual([]);
    },
  );
});

describe('engine source scan: controls (the scan can fail)', () => {
  // One scratch source per banned rule, the token in code position. Each must produce that rule.
  const positive: [string, string][] = [
    ['parseFloat', 'const x = parseFloat(input);'],
    ['Number(', 'const x = Number(input);'],
    ['Math.', 'const x = Math.floor(input);'],
    ['toFixed', 'const x = input.toFixed(2);'],
    ['toPrecision', 'const x = input.toPrecision(4);'],
    ['Intl', 'const x = new Intl.NumberFormat();'],
    ['toLocaleString', 'const x = input.toLocaleString();'],
    ['unary-plus', 'const x = +input;'],
    ['decimal-literal', 'const x = 1.5;'],
    ['fetch', 'const x = fetch(url);'],
    ['XMLHttpRequest', 'const x = new XMLHttpRequest();'],
    ['WebSocket', 'const x = new WebSocket(url);'],
    ['navigator', 'const x = navigator.onLine;'],
    ['localStorage', 'const x = localStorage.getItem(key);'],
    ['sessionStorage', 'const x = sessionStorage.getItem(key);'],
    ['indexedDB', 'const x = indexedDB.open(name);'],
    ['Date', 'const x = Date.now();'],
    ['Math.random', 'const x = Math.random();'],
    ['crypto.getRandomValues', 'const x = crypto.getRandomValues(bytes);'],
    ['process.env', 'const x = process.env;'],
    ['setTimeout', 'setTimeout(callback, 0);'],
    ['setInterval', 'setInterval(callback, 0);'],
    ['async', 'export async function run() {}'],
    ['await', 'const x = await run();'],
    ['Promise', 'const x = Promise.resolve(1);'],
    ['performance.now', 'const x = performance.now();'],
    ['template-literal', 'const x = `a${b}`;'],
    ['module-state', 'let counter = 0n;'],
    ['import-outside-engine', "import { x } from '@angular/core';"],
    ['dynamic-import', "const x = import('./other');"],
    ['require', "const x = require('./other');"],
  ];

  it('has a control for every banned rule and every import rule', () => {
    const covered = new Set(positive.map(([rule]) => rule));

    for (const { rule } of BANNED) {
      expect(covered.has(rule), 'no control for rule ' + rule).toBe(true);
    }
  });

  it.each(positive)('flags %s in code position', (rule, src) => {
    expect(rulesIn(src)).toContain(rule);
  });

  it.each(positive.filter(([rule]) => rule !== 'Math.random'))(
    'flags %s as exactly that rule',
    (rule, src) => {
      expect([...new Set(rulesIn(src))]).toEqual([rule]);
    },
  );

  it('flags an exponent literal and a leading-dot literal', () => {
    expect(rulesIn('const x = 1e3;')).toContain('decimal-literal');
    expect(rulesIn('const x = .5;')).toContain('decimal-literal');
  });

  it('flags var state and exported let at module level', () => {
    expect(rulesIn('var counter = 0n;')).toContain('module-state');
    expect(rulesIn('export let counter = 0n;')).toContain('module-state');
  });

  it('does not flag a let inside a function', () => {
    expect(rulesIn('function f() {\n  let total = 0n;\n  return total;\n}')).toEqual([]);
  });

  it('flags an import from a relative parent and from a subdirectory', () => {
    expect(rulesIn("import { x } from '../api/envelope';")).toEqual(['import-outside-engine']);
    expect(rulesIn("import { x } from './testing/fixture-files';")).toEqual([
      'import-outside-engine',
    ]);
    expect(rulesIn("export * from 'rxjs';")).toEqual(['import-outside-engine']);
    expect(rulesIn("import fs from 'node:fs';")).toEqual(['import-outside-engine']);
    expect(rulesIn("import './side-effect-only-package/x';")).toEqual(['import-outside-engine']);
  });

  it('accepts a flat sibling import, a type import and a re-export', () => {
    expect(rulesIn("import { x } from './decimal';")).toEqual([]);
    expect(rulesIn("import type { T } from './types';")).toEqual([]);
    expect(rulesIn("export { y } from './tax';")).toEqual([]);
  });

  it('reports the file and the line of a violation', () => {
    const found = scanSource('scratch.ts', 'const a = 1n;\nconst b = parseFloat(a);\n');

    expect(found).toEqual([{ file: 'scratch.ts', rule: 'parseFloat', line: 2 }]);
  });

  it('negative control: the same tokens inside comments and strings are not flagged', () => {
    const src = [
      '// parseFloat Number(x) Math.floor fetch Date.now 1.5 `tick`',
      '/* XMLHttpRequest',
      '   setTimeout async await Promise */',
      "const message = 'parseFloat Number(x) Math.floor fetch Date.now 1.5 async await';",
      'const other = "navigator localStorage process.env";',
      "const max = '99999999999999.9999';",
      "const escaped = 'it\\'s fetch';",
    ].join('\n');

    expect(rulesIn(src)).toEqual([]);
  });

  it('the stripper keeps line breaks, so a line number stays correct after a block comment', () => {
    const src = '/* one\n two\n three */\nconst x = parseFloat(y);';

    expect(scanSource('scratch.ts', src)[0]?.line).toBe(4);
    expect(stripCommentsAndStrings(src).split('\n').length).toBe(src.split('\n').length);
  });

  it('word boundaries: documentDate is not Date, and a longer name is not a banned word', () => {
    expect(rulesIn('const documentDate = 1n;\nconst fetched = 2n;\nconst asyncs = 3n;')).toEqual(
      [],
    );
  });
});
