/*
 * Test-only: the static scan of the engine's own source files (AC-2, AC-12, AC-25, AC-42). It is
 * named for what it scans: `pricing-engine/*.ts` without the specs and without `testing/`.
 *
 * `scanSource` is the one function both the real scan and its controls run through, so a control
 * proves the real scan can fail. Comments and the contents of string literals are removed first, so
 * a banned word in prose or in a message is not a violation, and the MAX_AMOUNT string does not
 * trip the numeric-literal ban. Template literals are banned outright, which closes the `${...}`
 * loophole: messages use `+`.
 *
 * Limitation: the stripper does not understand regular-expression literals. Engine source may hold
 * a regex, but not one that contains a quote character, `//` or `/*`.
 */

export interface Violation {
  readonly file: string;
  readonly rule: string;
  readonly line: number;
}

interface Banned {
  readonly rule: string;
  readonly pattern: RegExp;
}

/** One named regex per banned token, applied to the stripped text. */
export const BANNED: readonly Banned[] = [
  // Floats and number formatting (AC-12).
  { rule: 'parseFloat', pattern: /parseFloat/ },
  { rule: 'Number(', pattern: /Number\s*\(/ },
  { rule: 'Math.', pattern: /Math\.(?!random\b)/ },
  { rule: 'toFixed', pattern: /\.toFixed\b/ },
  { rule: 'toPrecision', pattern: /\.toPrecision\b/ },
  { rule: 'Intl', pattern: /\bIntl\b/ },
  { rule: 'toLocaleString', pattern: /toLocaleString/ },
  { rule: 'unary-plus', pattern: /(?:^|[=(,:;?!&|{[]|\breturn)\s*\+(?![+=])/m },
  {
    rule: 'decimal-literal',
    pattern: /(?<![\w$.])\d*\.\d+(?:[eE][+-]?\d+)?\b|\b\d+[eE][+-]?\d+\b/,
  },
  // Network, clock, randomness and ambient state (AC-25, AC-42).
  { rule: 'fetch', pattern: /\bfetch\b/ },
  { rule: 'XMLHttpRequest', pattern: /XMLHttpRequest/ },
  { rule: 'WebSocket', pattern: /WebSocket/ },
  { rule: 'navigator', pattern: /\bnavigator\b/ },
  { rule: 'localStorage', pattern: /localStorage/ },
  { rule: 'sessionStorage', pattern: /sessionStorage/ },
  { rule: 'indexedDB', pattern: /indexedDB/ },
  { rule: 'Date', pattern: /\bDate\b/ },
  { rule: 'Math.random', pattern: /Math\.random/ },
  { rule: 'crypto.getRandomValues', pattern: /crypto\.getRandomValues/ },
  { rule: 'process.env', pattern: /process\.env/ },
  { rule: 'setTimeout', pattern: /setTimeout/ },
  { rule: 'setInterval', pattern: /setInterval/ },
  { rule: 'async', pattern: /\basync\b/ },
  { rule: 'await', pattern: /\bawait\b/ },
  { rule: 'Promise', pattern: /\bPromise\b/ },
  { rule: 'performance.now', pattern: /performance\.now/ },
  // Template literals (the `${...}` loophole) and module state (AC-44).
  { rule: 'template-literal', pattern: /`/ },
  { rule: 'module-state', pattern: /^(?:export\s+)?(?:let|var)\b/m },
];

/** A sibling in this directory: `./name`, no `../`, no subdirectory, no package. */
const SIBLING = /^\.\/[A-Za-z0-9_-]+$/;

/**
 * One state-machine pass. Removes `//` and block comments always; with `keepStrings` false it also
 * empties the contents of `'...'` and `"..."` strings. Quotes and line breaks are kept, so line
 * numbers stay correct.
 */
export function stripCommentsAndStrings(src: string, keepStrings = false): string {
  let out = '';
  let i = 0;

  while (i < src.length) {
    const char = src.charAt(i);
    const next = src.charAt(i + 1);

    if (char === '/' && next === '/') {
      while (i < src.length && src.charAt(i) !== '\n') {
        i++;
      }

      continue;
    }

    if (char === '/' && next === '*') {
      i += 2;

      while (i < src.length && !(src.charAt(i) === '*' && src.charAt(i + 1) === '/')) {
        out += src.charAt(i) === '\n' ? '\n' : '';
        i++;
      }

      i += 2;

      continue;
    }

    if (char === "'" || char === '"') {
      out += char;
      i++;

      while (i < src.length && src.charAt(i) !== char && src.charAt(i) !== '\n') {
        if (src.charAt(i) === '\\') {
          out += keepStrings ? src.charAt(i) + src.charAt(i + 1) : '';
          i += 2;

          continue;
        }

        out += keepStrings ? src.charAt(i) : '';
        i++;
      }

      out += src.charAt(i) === char ? char : '';
      i++;

      continue;
    }

    out += char;
    i++;
  }

  return out;
}

function lineOf(text: string, index: number): number {
  let line = 1;

  for (let i = 0; i < index; i++) {
    if (text.charAt(i) === '\n') {
      line++;
    }
  }

  return line;
}

/** Every violation in one source file. Empty means clean. */
export function scanSource(name: string, src: string): Violation[] {
  const violations: Violation[] = [];

  // Imports are read from the text with comments removed and strings kept (the specifier is one).
  const withStrings = stripCommentsAndStrings(src, true);

  for (const match of withStrings.matchAll(/\b(?:from|import)\s*(['"])([^'"\n]*)\1/g)) {
    if (!SIBLING.test(match[2] ?? '')) {
      violations.push({
        file: name,
        rule: 'import-outside-engine',
        line: lineOf(withStrings, match.index),
      });
    }
  }

  for (const match of withStrings.matchAll(/\bimport\s*\(/g)) {
    violations.push({ file: name, rule: 'dynamic-import', line: lineOf(withStrings, match.index) });
  }

  for (const match of withStrings.matchAll(/\brequire\s*\(/g)) {
    violations.push({ file: name, rule: 'require', line: lineOf(withStrings, match.index) });
  }

  const code = stripCommentsAndStrings(src, false);

  for (const { rule, pattern } of BANNED) {
    const flags = pattern.flags.includes('g') ? pattern.flags : pattern.flags + 'g';

    for (const match of code.matchAll(new RegExp(pattern.source, flags))) {
      violations.push({ file: name, rule, line: lineOf(code, match.index) });
    }
  }

  return violations;
}
