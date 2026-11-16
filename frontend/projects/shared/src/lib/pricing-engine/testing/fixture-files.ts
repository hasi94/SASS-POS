/*
 * Test-only: reads the shared pricing fixtures (packages/pricing/fixtures) and the engine's own
 * source files in place, with Node's fs obtained at run time. Nothing here is part of the engine,
 * and nothing here is exported from the public API.
 *
 * fs is reached through `process.getBuiltinModule`, never `import`, so the bundler that builds the
 * specs never sees a Node import. When fs, the fixture directory or a file is missing, this
 * throws: a missing fixture set must fail the gate, never skip.
 */

interface MinimalFs {
  existsSync(path: string): boolean;
  statSync(path: string): { isDirectory(): boolean };
  readdirSync(path: string): string[];
  readFileSync(path: string, encoding: 'utf8'): string;
}

interface MinimalProcess {
  cwd?: () => string;
  getBuiltinModule?: (id: string) => unknown;
}

function nodeProcess(): MinimalProcess | undefined {
  return (globalThis as unknown as { process?: MinimalProcess }).process;
}

/** Node's fs, or a loud failure. */
export function nodeFs(): MinimalFs {
  const fs = nodeProcess()?.getBuiltinModule?.('node:fs') as MinimalFs | undefined;

  if (!fs || typeof fs.readFileSync !== 'function') {
    throw new Error(
      'pricing fixtures: node:fs is unavailable (needs Node >= 22.3 under Vitest); the replay cannot run',
    );
  }

  return fs;
}

const FIXTURES_AT = 'packages/pricing/fixtures';
const ANGULAR_JSON_AT = 'frontend/angular.json';
const ENGINE_AT = 'frontend/projects/shared/src/lib/pricing-engine';

function slashes(path: string): string {
  return path.replace(/\\/g, '/').replace(/\/+$/, '');
}

function parentOf(path: string): string {
  const cut = path.lastIndexOf('/');

  return cut <= 0 ? path : path.slice(0, cut);
}

/**
 * The repository root: the first ancestor of `start` (default the working directory) that holds
 * `frontend/angular.json`. The fixture tree must then be there; the walk never continues past the
 * anchor, so a worktree can never replay another checkout's fixtures. A missing tree throws.
 */
export function locateRepoRoot(start?: string): string {
  const fs = nodeFs();
  const from = start ?? nodeProcess()?.cwd?.();

  if (typeof from !== 'string' || from === '') {
    throw new Error(
      'pricing fixtures: process.cwd() is unavailable; cannot locate the repository root',
    );
  }

  const tried: string[] = [];
  let candidate = slashes(from);

  for (;;) {
    tried.push(candidate);

    if (fs.existsSync(candidate + '/' + ANGULAR_JSON_AT)) {
      if (!fs.existsSync(candidate + '/' + FIXTURES_AT)) {
        throw new Error(
          'pricing fixtures: the repository root ' +
            candidate +
            ' (anchored on ' +
            ANGULAR_JSON_AT +
            ') has no ' +
            FIXTURES_AT +
            ': ' +
            candidate +
            '/' +
            FIXTURES_AT,
        );
      }

      return candidate;
    }

    const parent = parentOf(candidate);

    if (parent === candidate) {
      break;
    }

    candidate = parent;
  }

  throw new Error(
    'pricing fixtures: no repository root found (needs ' +
      ANGULAR_JSON_AT +
      ' in an ancestor). Tried: ' +
      tried.join(', '),
  );
}

export interface FixtureFile {
  /** Directory name, which is the kind the file must declare. */
  readonly kind: string;
  /** File name without `.json`. */
  readonly name: string;
  readonly path: string;
  /** The parsed document, or undefined when it did not parse. */
  readonly decoded: unknown;
  readonly parseError: string | null;
}

/** Every `packages/pricing/fixtures/*\/*.json`, sorted by path, found by glob. */
export function loadFixtureSet(root?: string): FixtureFile[] {
  const fs = nodeFs();
  const base = slashes(root ?? locateRepoRoot()) + '/' + FIXTURES_AT;

  if (!fs.existsSync(base) || !fs.statSync(base).isDirectory()) {
    throw new Error('pricing fixtures: directory not found: ' + base);
  }

  const paths: string[] = [];

  for (const dir of fs.readdirSync(base)) {
    const dirPath = base + '/' + dir;

    if (!fs.statSync(dirPath).isDirectory()) {
      continue;
    }

    for (const file of fs.readdirSync(dirPath)) {
      if (file.endsWith('.json')) {
        paths.push(dirPath + '/' + file);
      }
    }
  }

  if (paths.length === 0) {
    throw new Error('pricing fixtures: no fixture files under ' + base);
  }

  paths.sort();

  return paths.map((path): FixtureFile => {
    const parts = path.split('/');
    const file = parts[parts.length - 1] ?? '';
    const kind = parts[parts.length - 2] ?? '';
    const name = file.slice(0, file.length - '.json'.length);

    try {
      return {
        kind,
        name,
        path,
        decoded: JSON.parse(fs.readFileSync(path, 'utf8')),
        parseError: null,
      };
    } catch (error) {
      return { kind, name, path, decoded: undefined, parseError: String(error) };
    }
  });
}

export interface EngineSource {
  readonly name: string;
  readonly text: string;
}

/** The engine's own source files: `pricing-engine/*.ts` without the specs. Never `testing/`. */
export function readEngineSources(root?: string): EngineSource[] {
  const fs = nodeFs();
  const base = slashes(root ?? locateRepoRoot()) + '/' + ENGINE_AT;

  if (!fs.existsSync(base)) {
    throw new Error('pricing engine: directory not found: ' + base);
  }

  const names = fs
    .readdirSync(base)
    .filter((file) => file.endsWith('.ts') && !file.endsWith('.spec.ts'))
    .sort();

  if (names.length === 0) {
    throw new Error('pricing engine: no source files under ' + base);
  }

  return names.map((name) => ({ name, text: fs.readFileSync(base + '/' + name, 'utf8') }));
}
