<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\RelayOutbox;

use Flexipos\Eventbus\Contracts\EventBus;
use Flexipos\Eventbus\Support\ConsumerRegistry;
use Flexipos\Kernel\Tenancy\Contracts\TenantFleet;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `php artisan eventbus:relay` (T-018 Ruling 6).
 *
 * **A long-running worker, never a scheduled job** (D-5): a once-a-minute
 * scheduled pass cannot give the relay's own increasing back-off between
 * attempts, and `docs/deployment-vps.md` already names `pos-worker` -
 * "queues + stream consumers + outbox relay" - at the infrastructure level.
 * No schedule entry is registered. Nothing runs this in production yet
 * (FP-319's family); wiring a supervisor is a follow-up with the Redis
 * infrastructure that also lifts D-1.
 *
 * No option visits every serving tenant `TenantFleet::servingTenantIds()`
 * names; `--tenant` (repeatable) visits exactly the named tenants. One
 * tenant's failure is logged and the loop continues; `--once` exits 1 if any
 * tenant or any row failed to publish this run.
 */
final class RelayOutboxCommand extends Command
{
    protected $signature = 'eventbus:relay
        {--tenant=* : Relay only the named tenants, instead of the whole serving fleet}
        {--once : Run exactly one pass over each tenant, then exit}
        {--max-passes=0 : Stop after this many passes (0 means unlimited)}
        {--sleep=1 : Seconds to sleep between passes}';

    protected $description = 'Publish pending outbox_events rows onto the event bus, with retry and back-off';

    public function handle(
        RelayOutboxHandler $handler,
        Tenancy $tenancy,
        TenantFleet $fleet,
        ConsumerRegistry $registry,
        EventBus $bus,
        ConfigRepository $config,
        LoggerInterface $logger,
    ): int {
        /** @var list<string> $named */
        $named = $this->option('tenant');
        $tenantIds = $named === [] ? $fleet->servingTenantIds() : $named;

        $once = (bool) $this->option('once');
        $maxPasses = (int) $this->option('max-passes');
        $sleepSeconds = (int) $this->option('sleep');

        $stream = (string) $config->get('eventbus.stream', 'flexipos:events');

        foreach ($registry->groups() as $group) {
            $bus->ensureGroup($stream, $group);
        }

        $pass = 0;
        $anyFailure = false;

        while (true) {
            $pass++;
            $passFailed = false;

            foreach ($tenantIds as $tenantId) {
                try {
                    $result = $tenancy->run(
                        $tenantId,
                        static fn (): RelayOutboxResult => $handler->handle(new RelayOutbox)
                    );

                    $this->components->twoColumnDetail(
                        $tenantId,
                        "{$result->published} published, {$result->retried} retried, {$result->exhausted} failed, {$result->skipped} skipped"
                    );

                    if ($result->exhausted > 0) {
                        $passFailed = true;
                    }
                } catch (Throwable $exception) {
                    $passFailed = true;
                    $exceptionClass = $exception::class;

                    $logger->error('Relaying the outbox failed for one tenant.', [
                        'tenant_id' => $tenantId,
                        'exception' => $exceptionClass,
                    ]);

                    $this->components->error("{$tenantId}: {$exceptionClass}");
                }
            }

            if ($tenantIds === []) {
                $this->components->info('No tenant was named and the fleet is empty; nothing was relayed.');
            }

            $anyFailure = $anyFailure || $passFailed;

            if ($once || ($maxPasses > 0 && $pass >= $maxPasses)) {
                break;
            }

            Sleep::for($sleepSeconds)->seconds();
        }

        return $anyFailure ? self::FAILURE : self::SUCCESS;
    }
}
