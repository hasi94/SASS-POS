<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * The one writer of `platform_audit_logs` (T-016 plan section 3).
 *
 * `actor_type` is `user` when an actor id is given, `system` otherwise (the
 * scheduled sweep). `ip` and `user_agent` are always null: there is no HTTP
 * request behind any write this package makes.
 *
 * **Deliberately not `final`.** AC-29's fault-injection proof needs a double
 * that throws on one named action and delegates every other call, and the
 * handlers that use this class take the concrete type rather than an
 * interface - there is no second implementation in production, only in a
 * test's `app()->bind()`.
 */
class PlatformAudit
{
    public function __construct(private readonly RunnerClock $clock) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function write(
        Connection $master,
        string $action,
        string $subjectType,
        ?string $subjectId,
        ?string $tenantId,
        ?string $actorUserId,
        ?array $before,
        ?array $after,
    ): void {
        $master->table('platform_audit_logs')->insert([
            'actor_type' => $actorUserId !== null ? 'user' : 'system',
            'actor_id' => $actorUserId,
            'actor_email' => null,
            'tenant_id' => $tenantId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before === null ? null : json_encode($before),
            'after' => $after === null ? null : json_encode($after),
            'ip' => null,
            'user_agent' => null,
            'request_id' => Str::ulid()->toBase32(),
            'created_at' => $this->clock->timestamp(),
        ]);
    }
}
