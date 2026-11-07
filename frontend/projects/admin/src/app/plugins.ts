import { createPluginRouteRegistry } from '@flexipos/shared';

/**
 * The admin app's plugin route registry (T-025 D-10). Empty in this task — no real
 * plugin registers a route yet (T-019 D-10: no plugin HTTP surface exists). A later
 * task's plugin bundle calls `adminPlugins.register(...)` before the routes below are
 * built.
 */
export const adminPlugins = createPluginRouteRegistry();
