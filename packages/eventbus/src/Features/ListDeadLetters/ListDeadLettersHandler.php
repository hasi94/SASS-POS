<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ListDeadLetters;

use Flexipos\Eventbus\Bus\DeadLetter;
use Flexipos\Eventbus\Contracts\EventBus;
use Flexipos\Kernel\Audit\PageSize;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * AC-30: event id, original stream, failure reason and delivery count for
 * operator inspection. The real-Redis read path is [ENGINE - Redis], NOT
 * VERIFIED in this environment (D-1); this handler's listing/formatting is
 * proved against the stand-in bus.
 */
final class ListDeadLettersHandler
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 500;

    public function __construct(
        private readonly EventBus $bus,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return list<DeadLetter>
     */
    public function handle(ListDeadLetters $command): array
    {
        $deadStream = (string) $this->config->get('eventbus.dead_letter_stream', 'flexipos:events:dead');
        $limit = PageSize::clamp($command->limit, self::DEFAULT_LIMIT, self::MAX_LIMIT);

        return $this->bus->deadLetters($deadStream, $limit, $command->newestFirst);
    }
}
