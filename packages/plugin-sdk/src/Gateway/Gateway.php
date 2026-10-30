<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Gateway;

use Closure;
use Flexipos\Contracts\Events\EventActor;
use Flexipos\Contracts\Events\NewEvent;
use Flexipos\Contracts\Events\Outbox;
use Flexipos\Contracts\Plugins\HookCall;
use Flexipos\Contracts\Plugins\HookContribution;
use Flexipos\Contracts\Plugins\HookGateway;
use Flexipos\Contracts\Plugins\HookMode;
use Flexipos\Contracts\Plugins\HookOutcome;
use Flexipos\Contracts\Plugins\HookVeto;
use Flexipos\Contracts\Plugins\Veto;
use Flexipos\Kernel\Tenancy\TenantContext;
use Flexipos\PluginSdk\Runtime\PluginRuntime;
use Flexipos\PluginSdk\Support\Connections;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * `HookGateway`'s one implementation (T-019 AC-21 to AC-26, plan
 * Ruling 4).
 *
 * `before`, then `filter`, then (`override` or `$action`) plus `add` plus
 * `after` inside one tenant transaction. With zero registrations this is
 * exactly `$action($payload)` (AC-26). An exception thrown by the
 * caller's own action is never a plugin failure - it is never caught
 * here, so it propagates unchanged.
 */
final class Gateway implements HookGateway
{
    public function __construct(
        private readonly HookPointCatalogue $catalogue,
        private readonly PluginRuntime $runtime,
        private readonly HookInvoker $invoker,
        private readonly TenantContext $tenantContext,
        private readonly Connections $connections,
        private readonly Outbox $outbox,
    ) {}

    public function dispatch(string $point, array $payload = [], ?Closure $action = null): HookOutcome
    {
        $hookPoint = $this->catalogue->find($point);

        if ($hookPoint === null) {
            throw new LogicException("Hook point [{$point}] is not registered in the catalogue.");
        }

        // Throws the kernel's own NoTenantResolved when nothing has
        // entered a tenant yet (Ruling 4, step 1).
        $tenantId = $this->tenantContext->tenantId();

        $registrations = $this->runtime->tableFor($tenantId)->registrationsFor($point);

        $skipped = [];
        $failed = [];

        // A plugin this very dispatch trips: Ruling 12, "the trip is
        // noticed at once by the process that tripped it." Keyed by
        // plugin name, checked ahead of `$registration->refused`.
        $trippedNow = [];

        $veto = $this->runBefore($registrations, $point, $payload, $skipped, $failed, $trippedNow);

        if ($veto !== null) {
            return new HookOutcome($point, $veto, $payload, null, null, [], $skipped, $failed);
        }

        $payload = $this->runFilters($registrations, $point, $payload, $skipped, $failed, $trippedNow);

        $contributions = [];
        $overriddenBy = null;

        $connection = DB::connection($this->connections->tenantConnectionName());

        $result = $connection->transaction(function () use ($hookPoint, $registrations, $point, $payload, $action, &$overriddenBy, &$contributions, &$skipped, &$failed, &$trippedNow): mixed {
            $value = $this->runActionOrOverride($registrations, $hookPoint->isOverridable(), $point, $payload, $action, $overriddenBy, $skipped, $failed, $trippedNow);
            $contributions = $this->runAdds($registrations, $point, $payload, $value, $skipped, $failed, $trippedNow);

            $this->runAfters($registrations, $point, $payload, $value, $skipped, $trippedNow);

            return $value;
        });

        return new HookOutcome($point, null, $payload, $result, $overriddenBy, $contributions, $skipped, $failed);
    }

    /**
     * @param  list<Registration>  $registrations
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $skipped
     * @param  list<string>  $failed
     * @param  array<string, bool>  $trippedNow
     */
    private function runBefore(array $registrations, string $point, array $payload, array &$skipped, array &$failed, array &$trippedNow): ?HookVeto
    {
        foreach ($registrations as $registration) {
            if ($registration->mode !== HookMode::Before) {
                continue;
            }

            if ($registration->refused || isset($trippedNow[$registration->plugin])) {
                $skipped[] = $registration->plugin;
                $this->invoker->refuse($registration, $point, HookMode::Before);

                continue;
            }

            $call = new HookCall($point, $registration->plugin, $payload);
            $invocation = $this->invoker->invoke($registration, $point, HookMode::Before, fn (object $handler): mixed => $handler->before($call));

            if ($invocation->trippedNow) {
                $trippedNow[$registration->plugin] = true;
            }

            if ($invocation->status === 'veto') {
                return new HookVeto($registration->plugin, (string) $invocation->veto?->reason);
            }

            // The other way to veto: returning a Veto rather than throwing
            // VetoSignal (AC-23, "returning/throwing").
            if ($invocation->status === 'ok' && $invocation->value instanceof Veto) {
                return new HookVeto($registration->plugin, $invocation->value->reason);
            }

            if (in_array($invocation->status, ['error', 'timeout'], true)) {
                $failed[] = $registration->plugin;
            }
        }

        return null;
    }

    /**
     * @param  list<Registration>  $registrations
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $skipped
     * @param  list<string>  $failed
     * @param  array<string, bool>  $trippedNow
     * @return array<string, mixed>
     */
    private function runFilters(array $registrations, string $point, array $payload, array &$skipped, array &$failed, array &$trippedNow): array
    {
        foreach ($registrations as $registration) {
            if ($registration->mode !== HookMode::Filter) {
                continue;
            }

            if ($registration->refused || isset($trippedNow[$registration->plugin])) {
                $skipped[] = $registration->plugin;
                $this->invoker->refuse($registration, $point, HookMode::Filter);

                continue;
            }

            $call = new HookCall($point, $registration->plugin, $payload);
            $invocation = $this->invoker->invoke($registration, $point, HookMode::Filter, fn (object $handler): mixed => $handler->filter($call));

            if ($invocation->trippedNow) {
                $trippedNow[$registration->plugin] = true;
            }

            if ($invocation->status === 'ok' && is_array($invocation->value)) {
                $payload = $invocation->value;
            } elseif (in_array($invocation->status, ['error', 'timeout'], true)) {
                $failed[] = $registration->plugin;
            }
        }

        return $payload;
    }

    /**
     * @param  list<Registration>  $registrations
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $skipped
     * @param  list<string>  $failed
     * @param  array<string, bool>  $trippedNow
     */
    private function runActionOrOverride(array $registrations, bool $isOverridable, string $point, array $payload, ?Closure $action, ?string &$overriddenBy, array &$skipped, array &$failed, array &$trippedNow): mixed
    {
        $overrideRegistration = null;

        if ($isOverridable) {
            foreach ($registrations as $registration) {
                if ($registration->mode !== HookMode::Override) {
                    continue;
                }

                if ($registration->refused || isset($trippedNow[$registration->plugin])) {
                    $skipped[] = $registration->plugin;
                    $this->invoker->refuse($registration, $point, HookMode::Override);

                    continue;
                }

                $overrideRegistration = $registration;

                break;
            }
        }

        if ($overrideRegistration !== null) {
            $call = new HookCall($point, $overrideRegistration->plugin, $payload);
            $invocation = $this->invoker->invoke($overrideRegistration, $point, HookMode::Override, fn (object $handler): mixed => $handler->override($call));

            if ($invocation->trippedNow) {
                $trippedNow[$overrideRegistration->plugin] = true;
            }

            if ($invocation->status === 'ok') {
                $overriddenBy = $overrideRegistration->plugin;

                return $invocation->value;
            }

            if (in_array($invocation->status, ['error', 'timeout'], true)) {
                $failed[] = $overrideRegistration->plugin;
            }
        }

        return $action !== null ? $action($payload) : null;
    }

    /**
     * @param  list<Registration>  $registrations
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $skipped
     * @param  list<string>  $failed
     * @param  array<string, bool>  $trippedNow
     * @return list<HookContribution>
     */
    private function runAdds(array $registrations, string $point, array $payload, mixed $result, array &$skipped, array &$failed, array &$trippedNow): array
    {
        $contributions = [];

        foreach ($registrations as $registration) {
            if ($registration->mode !== HookMode::Add) {
                continue;
            }

            if ($registration->refused || isset($trippedNow[$registration->plugin])) {
                $skipped[] = $registration->plugin;
                $this->invoker->refuse($registration, $point, HookMode::Add);

                continue;
            }

            $call = new HookCall($point, $registration->plugin, $payload);
            $invocation = $this->invoker->invoke($registration, $point, HookMode::Add, fn (object $handler): mixed => $handler->add($call, $result));

            if ($invocation->trippedNow) {
                $trippedNow[$registration->plugin] = true;
            }

            if ($invocation->status === 'ok') {
                $contributions[] = new HookContribution($registration->plugin, $invocation->value);
            } elseif (in_array($invocation->status, ['error', 'timeout'], true)) {
                $failed[] = $registration->plugin;
            }
        }

        return $contributions;
    }

    /**
     * One outbox row per enabled `after` registration (AC-27), inside the
     * same transaction the action/override/add just ran in - a rolled-back
     * action leaves no `after` row. **Never calls the handler in-process**
     * (AC-27): that happens only once `PluginAfterHookConsumer` drains the
     * row.
     *
     * @param  list<Registration>  $registrations
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $skipped
     * @param  array<string, bool>  $trippedNow
     */
    private function runAfters(array $registrations, string $point, array $payload, mixed $result, array &$skipped, array &$trippedNow): void
    {
        foreach ($registrations as $registration) {
            if ($registration->mode !== HookMode::After) {
                continue;
            }

            if ($registration->refused || isset($trippedNow[$registration->plugin])) {
                $skipped[] = $registration->plugin;
                $this->invoker->refuse($registration, $point, HookMode::After);

                continue;
            }

            $this->outbox->record(new NewEvent(
                type: 'plugin.'.$point,
                aggregateType: 'plugin',
                aggregateId: $registration->pluginId,
                payload: [
                    'plugin' => $registration->plugin,
                    'plugin_version' => $registration->pluginVersion,
                    'point' => $point,
                    'payload' => $payload,
                    'result' => self::serializableResult($result),
                ],
                actor: EventActor::plugin($registration->pluginId, $registration->plugin),
            ));
        }
    }

    /**
     * The outbox payload's `result` key: null, a scalar or an array -
     * never an object a plugin handler returned, which the payload's JSON
     * column cannot carry back out unchanged.
     */
    private static function serializableResult(mixed $result): mixed
    {
        return $result === null || is_scalar($result) || is_array($result) ? $result : null;
    }
}
