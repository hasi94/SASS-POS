<?php

namespace App\Console\Commands;

use App\Support\ServiceHost\ServiceRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SvcMakeCommand extends Command
{
    /**
     * Names a service package may not use.
     */
    private const RESERVED_NAMES = ['host'];

    /**
     * Lowercase kebab-case, so the name works as a directory, a Composer
     * package name and a namespace segment at the same time (decision D-12).
     *
     * Deliberately the same constant the host validates SERVICES entries with,
     * not a copy of the expression: a name that generates must also boot.
     */
    private const NAME_PATTERN = ServiceRegistry::NAME_PATTERN;

    /**
     * Stub file => path inside the generated package.
     */
    private const FILES = [
        'composer.json.stub' => 'composer.json',
        'ServiceProvider.php.stub' => 'src/{{studly}}ServiceProvider.php',
        'PingController.php.stub' => 'src/Http/Controllers/PingController.php',
        'connection.config.php.stub' => 'config/connection.php',
        'routes.api.php.stub' => 'routes/api.php',
        'PingRouteTest.php.stub' => 'tests/Feature/PingRouteTest.php',
    ];

    /**
     * Structural directories every service package has, empty to begin with.
     */
    private const EMPTY_DIRECTORIES = [
        'src/Models',
        'src/Services',
        'src/Consumers',
        'src/Hooks',
        'database/migrations',
    ];

    protected $signature = 'svc:make {name : Lowercase kebab-case service name, for example billing}';

    protected $description = 'Scaffold a new service package under services/.';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            $this->error(sprintf(
                'Invalid service name [%s]. A service name must be lowercase kebab-case matching %s.',
                $name,
                self::NAME_PATTERN
            ));

            return self::FAILURE;
        }

        if (in_array($name, self::RESERVED_NAMES, true)) {
            $this->error(sprintf(
                '[%s] is a reserved name: services/host is the service host application itself, not a service package.',
                $name
            ));

            return self::FAILURE;
        }

        $target = $this->servicesPath().DIRECTORY_SEPARATOR.$name;

        if (File::exists($target)) {
            $this->error(sprintf('services/%s already exists. Nothing was created or modified.', $name));

            return self::FAILURE;
        }

        $replacements = $this->replacements($name);

        foreach (self::FILES as $stub => $relativePath) {
            $path = $target.DIRECTORY_SEPARATOR.strtr($relativePath, $replacements);

            File::ensureDirectoryExists(dirname($path));
            File::put($path, strtr($this->stub($stub), $replacements));
        }

        foreach (self::EMPTY_DIRECTORIES as $directory) {
            File::ensureDirectoryExists($target.DIRECTORY_SEPARATOR.$directory);
            File::put($target.DIRECTORY_SEPARATOR.$directory.DIRECTORY_SEPARATOR.'.gitkeep', '');
        }

        $this->info(sprintf("Created services/%s. Add '%s' to SERVICES to boot it.", $name, $name));

        // PHPStan and Deptrac cannot glob their path lists, so a new package is
        // invisible to both until someone registers it by hand. Say so here;
        // QaToolchainRegistryCoverageTest fails the gate if this is forgotten.
        $this->warn(sprintf(
            'Also register services/%s/src in phpstan.neon.dist (paths) and in deptrac.yaml '.
            '(paths, plus a Service_%s layer and its ruleset entry), or it is neither statically '.
            'analysed nor boundary-checked.',
            $name,
            Str::studly($name)
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function replacements(string $name): array
    {
        return [
            '{{name}}' => $name,
            // Str::studly('http-client') is 'HttpClient' and Str::studly('plugin-sdk')
            // is 'PluginSdk': the namespace segment is not a literal transliteration
            // of the hyphenated directory name.
            '{{studly}}' => Str::studly($name),
            '{{screaming}}' => Str::upper(str_replace('-', '_', $name)),
            '{{connection}}' => 'svc_'.str_replace('-', '_', $name),
            '{{schema}}' => 'pos_'.str_replace('-', '_', $name),
            '{{prefix}}' => 'api/v1/'.$name,
        ];
    }

    private function stub(string $stub): string
    {
        $contents = File::get($this->laravel->basePath('stubs'.DIRECTORY_SEPARATOR.'service'.DIRECTORY_SEPARATOR.$stub));

        return str_replace("\r\n", "\n", $contents);
    }

    private function servicesPath(): string
    {
        return dirname($this->laravel->basePath());
    }
}
