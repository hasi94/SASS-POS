<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\EnforceCommercialDeadlines;

use Illuminate\Console\Command;

/**
 * `php artisan platform:enforce-deadlines` (T-016 Ruling 2, D-14).
 *
 * Scheduled `everyMinute()->withoutOverlapping()` by `PlatformServiceProvider`.
 */
final class EnforceCommercialDeadlinesCommand extends Command
{
    protected $signature = 'platform:enforce-deadlines';

    protected $description = 'Suspend tenants whose grace period or platform licence has expired, and lift suspensions this task itself caused once the cause is gone';

    public function handle(EnforceCommercialDeadlinesHandler $handler): int
    {
        $result = $handler->handle(new EnforceCommercialDeadlines);

        $this->components->info("Examined {$result->examined}, changed {$result->changed}, failed {$result->failed}.");

        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
