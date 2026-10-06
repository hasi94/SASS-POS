<?php

namespace App\Providers;

use App\Support\ModuleHost\ModuleRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Env;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class ModuleHostServiceProvider extends ServiceProvider
{
    /**
     * Boot the modules named in MODULES.
     *
     * This happens during the registration phase on purpose: a misconfigured
     * MODULES value must break the process at boot, not lazily on the first
     * request that happens to hit a missing route.
     */
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, fn (Application $app): ModuleRegistry => new ModuleRegistry($app));

        $modules = config('modulehost.modules', '');
        $modules = is_string($modules) ? $modules : '';

        $this->guardAgainstStaleCachedModules($modules);

        $this->app->make(ModuleRegistry::class)->bootAll($modules);
    }

    /**
     * Refuse to boot a cached MODULES value the environment disagrees with.
     *
     * `config:cache` bakes MODULES into bootstrap/cache/config.php and the
     * running process never re-reads the variable again. That silently defeats
     * the one guarantee this host exists for - the same image running a
     * different subset of modules per deployment stage - so it has to be loud.
     *
     * Deliberately narrow, because a guard people disable is worse than none:
     * silent when the configuration is not cached, silent when it is cached and
     * the process environment sets no MODULES at all (a container legitimately
     * built and started with one baked-in value), and silent when the ambient
     * value parses to the same list. It throws only when a cached value and an
     * ambient value genuinely disagree.
     *
     * "Ambient" means the real process environment and nothing else. While the
     * configuration is cached the framework skips loading .env altogether, so a
     * MODULES edited there after caching is invisible to the application and
     * therefore also to this guard: the process boots the frozen list in
     * silence. That is the most likely non-container route into the very
     * failure this guard exists to catch, so it is called out in
     * config/modulehost.php and the root README rather than left implicit.
     */
    private function guardAgainstStaleCachedModules(string $cachedRaw): void
    {
        if (! ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            return;
        }

        // Env::getRepository()->get(), not env() or Env::get(): those coerce
        // "true"/"false"/"null"/"empty" string literals, which would corrupt a
        // raw comparison. This returns the raw string, or null if unset.
        //
        // Under a cached configuration this repository holds only what the
        // process was started with - .env was never loaded - so a MODULES set
        // only in .env reads as null here and the guard stays silent. See the
        // docblock above.
        $ambient = Env::getRepository()->get('MODULES');

        if ($ambient === null) {
            return;
        }

        $registry = $this->app->make(ModuleRegistry::class);

        if ($registry->parse($ambient) === $registry->parse($cachedRaw)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Cached configuration disagrees with the process environment: config:cache froze '.
            'MODULES="%s", but the environment now sets MODULES="%s". A cached configuration '.
            'never re-reads MODULES, so this process would boot the frozen list. Run '.
            '"php artisan config:clear && php artisan config:cache" after setting the MODULES '.
            'this image should boot with, or do not cache configuration where MODULES must '.
            'vary per container from one image.',
            $cachedRaw,
            $ambient
        ));
    }
}
