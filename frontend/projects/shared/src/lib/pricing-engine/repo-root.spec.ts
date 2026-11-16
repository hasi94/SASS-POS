import { locateRepoRoot } from './testing/fixture-files';

// A temp directory layout, built with Node's real fs (reached at run time, never imported).
interface TempFs {
  mkdtempSync(prefix: string): string;
  mkdirSync(path: string, options: { recursive: boolean }): void;
  writeFileSync(path: string, data: string): void;
  rmSync(path: string, options: { recursive: boolean; force: boolean }): void;
}

const getBuiltin = (
  globalThis as unknown as {
    process: { getBuiltinModule: (id: string) => unknown };
  }
).process.getBuiltinModule;
const fs = getBuiltin('node:fs') as TempFs;
const os = getBuiltin('node:os') as { tmpdir(): string };

const made: string[] = [];

const BACKSLASH = String.fromCharCode(92);

function slashes(path: string): string {
  return path.split(BACKSLASH).join('/');
}

function sandbox(): string {
  const root = slashes(fs.mkdtempSync(slashes(os.tmpdir()) + '/pricing-root-'));
  made.push(root);

  return root;
}

function put(root: string, file: string): void {
  const cut = file.lastIndexOf('/');
  fs.mkdirSync(root + '/' + file.slice(0, cut), { recursive: true });
  fs.writeFileSync(root + '/' + file, '{}');
}

afterAll(() => {
  for (const root of made) {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

describe('locateRepoRoot (anchored on frontend/angular.json)', () => {
  it('finds the anchor from a nested directory when the fixtures are there', () => {
    const root = sandbox();
    put(root, 'frontend/angular.json');
    put(root, 'packages/pricing/fixtures/tax/a.json');
    put(root, 'frontend/projects/shared');

    expect(locateRepoRoot(root + '/frontend/projects/shared')).toBe(root);
  });

  it('throws, naming the missing path, and never climbs past the anchor into another checkout', () => {
    const outer = sandbox();
    // An outer checkout that has everything...
    put(outer, 'frontend/angular.json');
    put(outer, 'packages/pricing/fixtures/tax/a.json');
    // ...and an inner one that has the anchor but no fixtures.
    put(outer, 'inner/frontend/angular.json');

    expect(() => locateRepoRoot(outer + '/inner/frontend')).toThrow(
      outer + '/inner/packages/pricing/fixtures',
    );
  });

  it('throws, listing what it tried, when no ancestor holds the anchor', () => {
    const root = sandbox();
    put(root, 'a/b/c');

    expect(() => locateRepoRoot(root + '/a/b')).toThrow(/no repository root found.*a\/b/);
  });
});
