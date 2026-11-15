<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Runtime;

use Flexipos\Contracts\Events\EventConsumer;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Contracts\Plugins\AfterHookCall;
use Flexipos\Contracts\Plugins\HookMode;
use Flexipos\PluginSdk\Exceptions\PluginDispatchRefused;
use Flexipos\PluginSdk\Gateway\HookInvoker;
use Flexipos\PluginSdk\Gateway\HookPointCatalogue;
use Flexipos\PluginSdk\Support\Connections;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Drains a plugin's `after` hooks from T-018's outbox (T-019 AC-27,
 * AC-29, AC-34, plan Ruling 11).
 *
 * `eventTypes()` is static, built fresh from the catalogue each time it
 * is asked - `'plugin.'.$name` for every point that accepts `after`.
 * Runs inside the tenant scope T-018's runner already opens
 * (`ConsumeEventsHandler::handle()`), so it never enters one itself.
 */
final class PluginAfterHookConsumer implements EventConsumer
{
    public function __construct(
        private readonly HookPointCatalogue $catalogue,
        private readonly PluginRuntime $runtime,
        private readonly HookInvoker $invoker,
        private readonly Connections $connections,
        private readonly LoggerInterface $logger,
    ) {}

    public function group(): string
    {
        return 'plugins.after';
    }

    /**
     * @return list<string>
     */
    public function eventTypes(): array
    {
        $types = [];

        foreach ($this->catalogue->all() as $point) {
            if ($point->accepts(HookMode::After)) {
                $types[] = 'plugin.'.$point->name;
            }
        }

        return $types;
    }

    public function handle(EventEnvelope $event): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $event->payload ?? [];
        $pluginName = (string) ($payload['plugin'] ?? '');
        $point = (string) ($payload['point'] ?? '');
        /** @var array<string, mixed> $hookPayload */
        $hookPayload = (array) ($payload['payload'] ?? []);
        $result = $payload['result'] ?? null;

        $row = $this->connections->master()->table('tenant_plugins')
            ->where('tenant_id', $event->tenantId)
            ->where('plugin_id', $event->aggregateId)
            ->first();

        if ($row === null || in_array((string) $row->status, ['disabled', 'installing'], true)) {
            $this->logger->info('Acknowledging an after hook for a plugin that is no longer enabled.', [
                'plugin' => $pluginName,
                'tenant_id' => $event->tenantId,
            ]);

            return;
        }

        if ((string) $row->status === 'error') {
            throw PluginDispatchRefused::tripped($pluginName, $event->tenantId);
        }

        foreach ($this->runtime->tableFor($event->tenantId)->registrationsFor($point) as $registration) {
            if ($registration->mode !== HookMode::After || $registration->plugin !== $pluginName) {
                continue;
            }

            $afterCall = new AfterHookCall($event->id, $event->tenantId, $registration->plugin, $point, $hookPayload, $result);
            $invocation = $this->invoker->invoke($registration, $point, HookMode::After, fn (object $handler): mixed => $handler->after($afterCall));

            if (in_array($invocation->status, ['error', 'timeout'], true)) {
                throw new RuntimeException("The after handler for plugin [{$pluginName}] failed; redelivery applies.");
            }
        }
    }
}
