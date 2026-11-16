/**
 * Spec-only ambient declaration (T-037 P-12). `@types/node` is not installed, so the specs that
 * read files declare exactly the two functions they use. Never imported by application code.
 */
declare module 'node:fs' {
  export function readFileSync(path: string, encoding: 'utf8'): string;
  export function readdirSync(path: string): string[];
}
