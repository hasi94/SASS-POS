<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ConsumeEvents;

use Flexipos\Eventbus\Contracts\EventBus;
use Flexipos\Eventbus\Support\ConsumerRegistry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `php artisan eventbus:consume` (T-018 Ruling 7).
 *
 * One consumer group per declared consumer, on one shared stream: no
 * `--group` means every declared group, round robin. `--consumer` defaults
 * to `<hostname>-<pid>`, the value the stream records against every message
 * this process claims or reads.
 *
 * A long-running worker, the same shape as `eventbus:relay` and for the
 * same reason (D-5): no schedule entry is registered.
 */
final class ConsumeEventsCommand extends Command
{
    protected $signature = 'eventbus:consume
        {--group=* : Consume only the named groups, instead of every declared one}
        {--consumer= : Consumer name, defaulting to <hostname>-<pid>}
        {--once : Run exactly one pass over each group, then exit}
        {--max-passes=0 : Stop after this many passes (0 means unlimited)}
        {--sleep=1 : Seconds to sleep between passes}';

    protected $description = 'Consume declared event consumer groups from the shared event stream, with native redelivery and dead-lettering';

    public function handle(
        ConsumeEventsHandler $handler,
        ConsumerRegistry $registry,
        EventBus $bus,
        ConfigRepository $config,
        LoggerInterface $logger,
    ): int {
        /** @var list<string> $named */
        $named = $this->option('group');
        $groups = $named === [] ? $registry->groups() : $named;

        $consumerOption = $this->option('consumer');
        $consumerName = is_string($consumerOption) && $consumerOption !== ''
            ? $consumerOption
            : (gethostname() ?: 'consumer').'-'.getmypid();

        $once = (bool) $this->option('once');
        $maxPasses = (int) $this->option('max-passes');
        $sleepSeconds = (int) $this->option('sleep');

        $stream = (string) $config->get('eventbus.stream', 'flexipos:events');

        foreach ($groups as $group) {
            $bus->ensureGroup($stream, $group);
        }

        $pass = 0;
        $anyFailure = false;

        while (true) {
            $pass++;

            foreach ($groups as $group) {
                try {
                    $result = $handler->handle(new ConsumeEvents($group, $consumerName));

                    $this->components->twoColumnDetail(
                        $group,
                        "{$result->handled} handled, {$result->acknowledgedNoop} no-op, {$result->deadLettered} dead-lettered, {$result->reclaimed} reclaimed"
                    );
                } catch (Throwable $exception) {
                    $anyFailure = true;
                    $exceptionClass = $exception::class;

                    $logger->error('Consuming events failed for one group.', [
                        'group' => $group,
                        'exception' => $exceptionClass,
                    ]);

                    $this->components->error("{$group}: {$exceptionClass}");
                }
            }

            if ($groups === []) {
                $this->components->info('No group is declared; nothing was consumed.');
            }

            if ($once || ($maxPasses > 0 && $pass >= $maxPasses)) {
                break;
            }

            Sleep::for($sleepSeconds)->seconds();
        }

        return $anyFailure ? self::FAILURE : self::SUCCESS;
    }
}
