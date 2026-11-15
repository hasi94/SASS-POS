<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Fixtures\FixtureEcho;

use Flexipos\Contracts\Plugins\FilterHook;
use Flexipos\Contracts\Plugins\HookCall;

/**
 * The second of the proof-of-pipeline fixture's two chained `filter`
 * handlers (T-019 AC-24, AC-44): appends a lower-case `x` to
 * `payload['text']`, so registration order is observable - `EchoUpperFilter`
 * then this one turns `"ab"` into `"AB"` then `"ABx"`, never `"ABX"`.
 */
final class EchoSuffixFilter implements FilterHook
{
    public function filter(HookCall $call): array
    {
        $payload = $call->payload;

        if (isset($payload['text']) && is_string($payload['text'])) {
            $payload['text'] = $payload['text'].'x';
        }

        return $payload;
    }
}
