<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api;

use Flexipos\Kernel\Api\Features\ListErrorCodes\ListErrorCodesCommand;
use Flexipos\Kernel\Api\Features\PruneIdempotencyKeys\PruneIdempotencyKeysCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * The kernel's own Api entry point (T-021 Ruling 2). It declares the kernel's
 * error codes through the same single mechanism every module uses, and binds
 * the catalogue as a singleton so every collaborator reads the same table.
 */
final class ApiServiceProvider extends ServiceProvider implements DeclaresErrorCodes
{
    public function register(): void
    {
        $this->app->singleton(ErrorCatalogue::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ListErrorCodesCommand::class,
                PruneIdempotencyKeysCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        // T-021 Ruling 6, AC-23. Inherits FP-319 (no scheduler runs in
        // production yet), exactly as AccessServiceProvider's archival job.
        $this->app->booted(function (): void {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);

            $schedule->command(PruneIdempotencyKeysCommand::class)
                ->hourly()
                ->withoutOverlapping();
        });
    }

    /**
     * @return array<string, list<ErrorCode>>
     */
    public function errorCodes(): array
    {
        return [KernelErrorCodes::MODULE => KernelErrorCodes::declarations()];
    }
}
