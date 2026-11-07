import { Component, Type } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, RouterOutlet, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { createPluginRouteRegistry } from './plugin-routes';

// No static import of './testing/fixture-plugin-page' anywhere in this file or in
// production code: that is what makes (b) below a real proof of laziness rather than
// an assumption. `grep -r "fixture-plugin-page" projects/shared/src` should show only
// this file's one dynamic `import()` call and the fixture module itself.

@Component({ selector: 'fp-host-root', imports: [RouterOutlet], template: `<router-outlet />` })
class HostRoot {}

describe('PluginRouteRegistry (AC-13, AC-14)', () => {
  beforeEach(() => {
    (globalThis as Record<string, unknown>)['__fpFixturePluginLoaded'] = undefined;
  });

  it('contributes exactly one route, mounted at plugins/<pluginId>/<path>, carrying data.pluginId', () => {
    const registry = createPluginRouteRegistry();
    registry.register({
      pluginId: 'fixture',
      path: 'hello',
      loadComponent: () => import('./testing/fixture-plugin-page').then((m) => m.FixturePluginPage),
    });

    const routes = registry.toRoutes();
    expect(routes).toHaveLength(1);
    expect(routes[0].path).toBe('plugins/fixture/hello');
    expect(routes[0].data?.['pluginId']).toBe('fixture');
  });

  it('stays lazy: the fixture module is not loaded merely by registering and building routes', () => {
    const registry = createPluginRouteRegistry();
    registry.register({
      pluginId: 'fixture',
      path: 'hello',
      loadComponent: () => import('./testing/fixture-plugin-page').then((m) => m.FixturePluginPage),
    });
    registry.toRoutes();

    expect((globalThis as Record<string, unknown>)['__fpFixturePluginLoaded']).toBeUndefined();
  });

  it('awaiting the registered loadComponent resolves to a component class and sets the flag', async () => {
    const registry = createPluginRouteRegistry();
    registry.register({
      pluginId: 'fixture',
      path: 'hello',
      loadComponent: () => import('./testing/fixture-plugin-page').then((m) => m.FixturePluginPage),
    });

    const [route] = registry.toRoutes();
    const Loaded = (await route.loadComponent!()) as Type<unknown>;
    expect(typeof Loaded).toBe('function');
    expect((globalThis as Record<string, unknown>)['__fpFixturePluginLoaded']).toBe(true);
  });

  it('router navigation to /plugins/fixture/hello renders the fixture page', async () => {
    const registry = createPluginRouteRegistry();
    registry.register({
      pluginId: 'fixture',
      path: 'hello',
      loadComponent: () => import('./testing/fixture-plugin-page').then((m) => m.FixturePluginPage),
    });

    TestBed.configureTestingModule({
      providers: [provideRouter([...registry.toRoutes(), { path: '**', component: HostRoot }])],
    });

    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/plugins/fixture/hello');
    const router = TestBed.inject(Router);
    expect(router.url).toBe('/plugins/fixture/hello');
    expect(harness.routeNativeElement?.textContent).toContain('fixture plugin page');
  });

  it('register() throws on a duplicate mounted path', () => {
    const registry = createPluginRouteRegistry();
    const entry = {
      pluginId: 'fixture',
      path: 'hello',
      loadComponent: () => import('./testing/fixture-plugin-page').then((m) => m.FixturePluginPage),
    };
    registry.register(entry);
    expect(() => registry.register({ ...entry })).toThrow();
  });

  it('register() throws when both loaders are present', () => {
    const registry = createPluginRouteRegistry();
    expect(() =>
      registry.register({
        pluginId: 'fixture',
        path: 'both',
        loadComponent: () => import('./testing/fixture-plugin-page').then((m) => m.FixturePluginPage),
        loadChildren: () => Promise.resolve([]),
      }),
    ).toThrow();
  });

  it('register() throws when no loader is present', () => {
    const registry = createPluginRouteRegistry();
    expect(() => registry.register({ pluginId: 'fixture', path: 'none' })).toThrow();
  });

  it('register() throws on an empty pluginId', () => {
    const registry = createPluginRouteRegistry();
    expect(() =>
      registry.register({
        pluginId: '',
        path: 'hello',
        loadComponent: () => import('./testing/fixture-plugin-page').then((m) => m.FixturePluginPage),
      }),
    ).toThrow();
  });
});
