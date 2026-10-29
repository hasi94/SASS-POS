<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Gateway;

use Closure;
use Flexipos\Contracts\Plugins\HookMode;
use Flexipos\Contracts\Plugins\Veto;
use Flexipos\Contracts\Plugins\VetoSignal;
use Flexipos\PluginSdk\Breaker\CircuitBreaker;
use Flexipos\PluginSdk\Support\Connections;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every synchronous handler call goes through this (T-019 AC-30, AC-36,
 * plan Ruling 5).
 *
 * In order: refuse an open breaker without calling the handler; resolve
 * the handler in-process; run the call inside the tenant connection's own
 * transaction (a savepoint when already nested); measure elapsed time
 * **after** the call and throw {@see HookTimedOut} inside the savepoint
 * when it is over budget; catch every `Throwable` (a {@see VetoSignal} is
 * a veto, not a failure); write exactly one `plugin_execution_logs` row,
 * outside the handler's own savepoint; report the outcome to
 * {@see CircuitBreaker} (T-019 step 11).
 */
final class HookInvoker
{
    public function __construct(
        private readonly Container $container,
        private readonly Connections $connections,
        private readonly HookClock $clock,
        private readonly ExecutionLog $log,
        private readonly CircuitBreaker $breaker,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  Closure(object): mixed  $call  given the resolved handler instance
     */
    public function invoke(Registration $registration, string $point, HookMode $mode, Closure $call): InvocationResult
    {
        if ($registration->refused) {
            $this->log->write($registration->plugin, $point, $mode, 0, 'refused', null);

            $this->logger->warning('Refused dispatching to a plugin whose breaker is open.', [
                'plugin' => $registration->plugin,
                'point' => $point,
                'mode' => $mode->value,
            ]);

            return new InvocationResult('refused');
        }

        $connectionName = $this->connections->tenantConnectionName();
        $connection = DB::connection($connectionName);

        $start = $this->clock->nowMs();
        $status = 'ok';
        $value = null;
        $veto = null;
        $error = null;

        try {
            $connection->transaction(function () use ($call, $registration, $start, &$value, &$veto): void {
                $handler = $this->container->make($registration->handlerClass);

                try {
                    $value = $call($handler);
                } catch (VetoSignal $signal) {
                    $veto = new Veto($signal->reason);

                    return;
                }

                $elapsedMs = (int) round($this->clock->nowMs() - $start);
                $limitMs = (int) config('plugins.timeout_ms', 500);

                if ($elapsedMs > $limitMs) {
                    throw HookTimedOut::after($elapsedMs, $limitMs);
                }
            });

            if ($veto !== null) {
                $status = 'veto';
            }
        } catch (HookTimedOut $timedOut) {
            $status = 'timeout';
            $error = $timedOut->getMessage();
            $value = null;
        } catch (Throwable $exception) {
            $status = 'error';
            $error = $exception->getMessage();
            $value = null;
        }

        $durationMs = (int) round($this->clock->nowMs() - $start);

        $this->log->write($registration->plugin, $point, $mode, $durationMs, $status, $error);

        $trippedNow = false;

        if (in_array($status, ['error', 'timeout'], true)) {
            $trippedNow = $this->breaker->recordFailure($registration->tenantId, $registration->pluginId, (string) $error);
        } else {
            $this->breaker->recordSuccess($registration->tenantId, $registration->pluginId);
        }

        return new InvocationResult($status, $value, $veto, $trippedNow);
    }
}
