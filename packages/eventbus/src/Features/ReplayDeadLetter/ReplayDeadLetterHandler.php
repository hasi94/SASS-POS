<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ReplayDeadLetter;

use Flexipos\Eventbus\Contracts\EventBus;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/**
 * AC-31: an unknown dead-letter id throws, letting the command decide the
 * exit code.
 */
final class ReplayDeadLetterHandler
{
    public function __construct(
        private readonly EventBus $bus,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @throws RuntimeException the id names no dead-letter entry
     */
    public function handle(ReplayDeadLetter $command): string
    {
        $deadStream = (string) $this->config->get('eventbus.dead_letter_stream', 'flexipos:events:dead');

        return $this->bus->replay($deadStream, $command->deadId);
    }
}
