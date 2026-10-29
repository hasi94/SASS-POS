<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use Flexipos\Kernel\Http\RequestId;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;
use LogicException;

/**
 * The one writer of `platform_audit_logs` (T-016 plan section 3; T-017
 * closes FP-323 and adds `actor_email` and catalogue enforcement).
 *
 * `actor_type` is `user` when an actor id is given, `system` otherwise (the
 * scheduled sweep). `ip` and `user_agent` are always null: there is no HTTP
 * request behind any write this package makes.
 *
 * **`request_id` is the real identifier of whatever caused the write, lazily
 * resolved exactly as `Flexipos\Access\Support\AuditWriter` resolves
 * it for the tenant side (T-017 AC-12).** It is never minted here: a
 * console-triggered write (T-016's own scheduled deadline-enforcement job,
 * among others) carries null, and two rows written while handling one HTTP
 * request carry the same identifier, because both ask the same resolver at
 * write time.
 *
 * **`actor_email` is a snapshot of the acting user's email, taken once at
 * write time and never joined later** (T-017 AC-13), for
 * `Flexipos\Access\Support\AuditWriter::actorName()`'s reason: a row
 * whose meaning changes when somebody edits their profile is not an audit
 * row. It is read from `users.email` on the given master connection when an
 * actor id is given, and null for a system write or an id naming nobody.
 *
 * **An action outside {@see PlatformAuditActions::ALL} is refused before the
 * insert** (T-017 Ruling 3, AC-19, AC-20): the catalogue here is closed and
 * not shared, because no other file may insert into this table at all.
 *
 * **Deliberately not `final`.** AC-29's fault-injection proof needs a double
 * that throws on one named action and delegates every other call, and the
 * handlers that use this class take the concrete type rather than an
 * interface - there is no second implementation in production, only in a
 * test's `app()->bind()`.
 *
 * **The constructor's second argument is optional, and that is for the four
 * T-016 fault-injection doubles alone** (T-017 Ruling 8): they build
 * `new class(app(RunnerClock::class)) extends PlatformAudit`, with one
 * argument, and must compile unchanged. Null means
 * {@see RequestId::none()}. Production never takes the default -
 * `PlatformServiceProvider` binds this class explicitly with the live
 * resolver.
 */
class PlatformAudit
{
    public function __construct(
        private readonly RunnerClock $clock,
        private readonly ?RequestId $requestId = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     *
     * @throws LogicException when the action is not one of
     *                        {@see PlatformAuditActions::ALL}
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
        if (! in_array($action, PlatformAuditActions::ALL, true)) {
            throw new LogicException(
                "The platform audit action [{$action}] is not one of PlatformAuditActions::ALL."
            );
        }

        $master->table('platform_audit_logs')->insert([
            'actor_type' => $actorUserId !== null ? 'user' : 'system',
            'actor_id' => $actorUserId,
            'actor_email' => $this->actorEmail($master, $actorUserId),
            'tenant_id' => $tenantId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before === null ? null : json_encode($before),
            'after' => $after === null ? null : json_encode($after),
            'ip' => null,
            'user_agent' => null,
            'request_id' => ($this->requestId ?? RequestId::none())->current(),
            'created_at' => $this->clock->timestamp(),
        ]);
    }

    /**
     * The acting user's email at this moment, or null.
     *
     * Null for a system write (no actor id) and - deliberately - also for an
     * id naming nobody: an email invented for an unknown id would be worse
     * than its absence, and the id itself is still on the row.
     */
    private function actorEmail(Connection $master, ?string $actorUserId): ?string
    {
        if ($actorUserId === null) {
            return null;
        }

        $email = $master->table('users')->where('id', $actorUserId)->value('email');

        return $email === null ? null : (string) $email;
    }
}
