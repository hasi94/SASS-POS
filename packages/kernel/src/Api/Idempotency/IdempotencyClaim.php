<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api\Idempotency;

/**
 * The outcome of {@see IdempotencyStore::claim()} (T-021 Ruling 6).
 */
enum IdempotencyClaim
{
    /** A new claim was inserted; the caller runs the handler. */
    case Claimed;

    /** The stored response is returned unchanged. */
    case Replay;

    /** Another request holds this key and is still running. */
    case InProgress;

    /** The same key was used with a different method, path, actor or body. */
    case Reused;
}
