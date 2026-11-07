import { ChangeDetectionStrategy, Component } from '@angular/core';

// Module-scope side effect, deliberately: this only runs once the module is actually
// evaluated. The spec resets the flag to `undefined` before each test and asserts it
// stays `undefined` after registering and building the route table, then becomes `true`
// only once the loader itself is invoked — the proof that `toRoutes()` wires a lazy
// loader rather than an eager import.
(globalThis as Record<string, unknown>)['__fpFixturePluginLoaded'] = true;

/** Fixture-only plugin page, imported solely by `plugin-routes.spec.ts`'s dynamic import. */
@Component({
  selector: 'fp-fixture-plugin-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<p>fixture plugin page</p>`,
})
export class FixturePluginPage {}
