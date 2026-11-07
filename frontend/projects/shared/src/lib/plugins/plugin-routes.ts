import { Type } from '@angular/core';
import { Data, Routes } from '@angular/router';

/**
 * A documented, minimal extension point for a plugin's frontend routes (T-025 D-10).
 *
 * T-019 (DONE) ships a backend hook gateway (five modes: add/before/after/filter/override)
 * but explicitly builds **no HTTP surface and no plugin registry** of any kind
 * (`docs/decisions/T-019-plugin-framework.md` D-10). This is a plain TypeScript convention,
 * not module federation: there is nothing real on the other end to call yet. A registered
 * entry contributes one lazy Angular route, mounted at `plugins/<pluginId>/<path>` so a
 * plugin can never shadow a host route such as `/catalog`.
 */
export interface PluginRouteEntry {
  /** The backend plugin's identifier (T-019). */
  pluginId: string;
  /** Relative; mounted at `plugins/<pluginId>/<path>`. */
  path: string;
  loadComponent?: () => Promise<Type<unknown>>;
  loadChildren?: () => Promise<Routes>;
  data?: Data;
}

export interface PluginRouteRegistry {
  /** Throws on: an empty `pluginId`, not exactly one loader, or a duplicate mounted path. */
  register(entry: PluginRouteEntry): void;
  entries(): readonly PluginRouteEntry[];
  /** Builds the Angular route table from every registered entry. */
  toRoutes(): Routes;
}

function mountedPath(entry: PluginRouteEntry): string {
  return `plugins/${entry.pluginId}/${entry.path}`;
}

class InMemoryPluginRouteRegistry implements PluginRouteRegistry {
  private readonly items: PluginRouteEntry[] = [];

  register(entry: PluginRouteEntry): void {
    if (!entry.pluginId) {
      throw new Error('PluginRouteEntry.pluginId must not be empty.');
    }
    const loaderCount = (entry.loadComponent ? 1 : 0) + (entry.loadChildren ? 1 : 0);
    if (loaderCount !== 1) {
      throw new Error(
        `PluginRouteEntry for '${entry.pluginId}' must declare exactly one of loadComponent/loadChildren.`,
      );
    }
    const path = mountedPath(entry);
    if (this.items.some((e) => mountedPath(e) === path)) {
      throw new Error(`A plugin route is already registered at '${path}'.`);
    }
    this.items.push(entry);
  }

  entries(): readonly PluginRouteEntry[] {
    return this.items;
  }

  toRoutes(): Routes {
    return this.items.map((entry) => ({
      path: mountedPath(entry),
      ...(entry.loadComponent ? { loadComponent: entry.loadComponent } : {}),
      ...(entry.loadChildren ? { loadChildren: entry.loadChildren } : {}),
      data: { ...entry.data, pluginId: entry.pluginId },
    }));
  }
}

/** A plain object, not DI: Angular route tables are built before the injector exists. */
export function createPluginRouteRegistry(): PluginRouteRegistry {
  return new InMemoryPluginRouteRegistry();
}
