<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api\Features\ListErrorCodes;

use Illuminate\Console\Command;

/**
 * `php artisan api:error-codes` (T-021 AC-14): every registered code, its
 * module, its HTTP status and its default message, sorted by code.
 */
final class ListErrorCodesCommand extends Command
{
    protected $signature = 'api:error-codes';

    protected $description = 'List every registered API error code with its module, status and message';

    public function handle(ListErrorCodesHandler $handler): int
    {
        $rows = $handler->handle(new ListErrorCodes);

        $this->table(['Code', 'Module', 'Status', 'Message'], array_map(
            static fn (array $row): array => [$row['code'], $row['module'], $row['status'], $row['message']],
            $rows
        ));

        return self::SUCCESS;
    }
}
