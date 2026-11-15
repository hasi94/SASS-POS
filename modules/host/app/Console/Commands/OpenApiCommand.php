<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\OpenApi\OpenApiDocument;
use Flexipos\Kernel\Api\ErrorCatalogue;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;

/**
 * `php artisan api:openapi {--output=}` (T-021 Ruling 8, AC-34, AC-38):
 * always available, independent of `API_DOCS_ENABLED`. Writes to the given
 * path, or prints to standard output when none is given.
 */
final class OpenApiCommand extends Command
{
    protected $signature = 'api:openapi {--output= : Write the document to this path instead of standard output}';

    protected $description = 'Generate the OpenAPI 3.1 document for every booted api/v1 route';

    public function handle(Router $router, ErrorCatalogue $catalogue): int
    {
        $document = OpenApiDocument::build($router, $catalogue);
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        $output = $this->option('output');

        if ($output === null) {
            $this->output->write($json);

            return self::SUCCESS;
        }

        file_put_contents($output, $json);
        $this->components->info("Wrote the OpenAPI document to {$output}.");

        return self::SUCCESS;
    }
}
