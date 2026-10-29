<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Http\RequestId;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;
use LogicException;

/**
 * The one writer of `audit_logs` in this package (D-20, AC-70).
 *
 * **Extended by T-017, never replaced.** D-20 said the audit facility proper
 * is FP-211's; that task closed two gaps on this class rather than
 * duplicating it (its own D-1): `request_id` and `user_agent` are now filled
 * (AC-1, AC-2), and an action outside {@see AuditCatalogue}'s declared list is
 * refused before the insert (AC-4's prerequisite, AC-19).
 *
 * **One writer, so three things cannot drift.** `actor_name` is a snapshot
 * and has to be taken the same way every time; `changed_fields` has to be
 * derived from `before` and `after` rather than restated by each caller,
 * because a caller that lists the fields it *meant* to change writes an
 * audit line that disagrees with its own payload; and `created_at` has to
 * come from the one clock T-009 bound rather than from `now()` in fourteen
 * places.
 *
 * **`actor_name` is a snapshot and that is the point.** The employee row it
 * is read from may be renamed or soft-deleted afterwards. An audit line
 * whose meaning changes when somebody edits a name is not an audit line, so
 * the name is copied at write time and never joined to.
 *
 * **It takes the connection rather than resolving one.** Every caller is
 * inside the transaction that carries its change, and an audit row written
 * on another connection would survive a rollback that took the change away
 * (AC-71) - which is the worst possible audit defect: a record of something
 * that did not happen.
 */
final class AuditWriter implements AuditTrail
{
    /**
     * The module every row this writer produces belongs to.
     *
     * One literal, here, because `audit_logs.module` is what an operator
     * filters by and fourteen call sites spelling it themselves is how one
     * of them comes to spell it differently.
     */
    public const MODULE = 'access';

    /**
     * What `actor_type` holds for every row this package writes.
     *
     * The access tables know only employees. When a master-side user or a
     * background job needs an audit row, that is a different value and a
     * decision somebody makes, not a default that happens.
     */
    public const ACTOR_TYPE = 'employee';

    public function __construct(
        private readonly RunnerClock $clock,
        private readonly ClientIp $client,
        private readonly ClientUserAgent $userAgent,
        private readonly RequestId $requestId,
        private readonly TenantConnection $tenant,
        private readonly AuditCatalogue $catalogue,
    ) {}

    /**
     * Record one successful administrative operation.
     *
     * @param  array<string, mixed>|null  $before  the subject's relevant state before, null for a creation
     * @param  array<string, mixed>|null  $after  the state after, null for a removal
     */
    public function write(
        Connection $connection,
        ?string $actorId,
        string $action,
        string $subjectType,
        string $subjectId,
        ?array $before,
        ?array $after,
        ?string $branchId = null,
    ): void {
        $this->insert($connection, self::MODULE, $actorId, $action, $subjectType, $subjectId, $before, $after, $branchId);
    }

    /**
     * {@see AuditTrail::record()}: the same writer for another module's rows
     * (T-014 AC-20), on the tenant connection the caller has entered.
     *
     * **Organization asks for its audit rows through the contract and never
     * inserts one itself**, so the actor-name snapshot, the derived
     * `changed_fields`, the clock and the caller's address stay decided in
     * this one place. The caller is inside its own transaction.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $module,
        ?string $actorId,
        string $action,
        string $subjectType,
        string $subjectId,
        ?array $before,
        ?array $after,
        ?string $branchId = null,
    ): void {
        $this->insert($this->tenant->get(), $module, $actorId, $action, $subjectType, $subjectId, $before, $after, $branchId);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     *
     * @throws LogicException when the catalogue has never declared this
     *                        (module, action) pair (T-017 Ruling 3, AC-4
     *                        prerequisite, AC-19)
     */
    private function insert(
        Connection $connection,
        string $module,
        ?string $actorId,
        string $action,
        string $subjectType,
        string $subjectId,
        ?array $before,
        ?array $after,
        ?string $branchId,
    ): void {
        if (! $this->catalogue->knows($module, $action)) {
            throw new LogicException(
                "The audit action [{$action}] is not declared for module [{$module}]."
                .' A module declares its audit actions by implementing Flexipos\Contracts\Audit\DeclaresAuditActions.'
            );
        }

        $connection->table('audit_logs')->insert([
            'actor_type' => self::ACTOR_TYPE,
            'actor_id' => $actorId,
            'actor_name' => $this->actorName($connection, $actorId),
            'branch_id' => $branchId,
            'module' => $module,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before === null ? null : self::encode($before),
            'after' => $after === null ? null : self::encode($after),
            'changed_fields' => self::encode(self::changedFields($before, $after)),
            // AC-70 names `ip` among the fields the row carries. It comes
            // from the request the container holds, packed by the one
            // helper inside this package, and is null when there is no
            // request - see ClientIp. `user_agent` and `request_id` follow
            // the identical lazy, per-current-request treatment, through
            // ClientUserAgent and the kernel's RequestId respectively
            // (T-017 AC-1, AC-2): both are null outside a request and both
            // are read fresh on every call, never captured at construction.
            'ip' => $this->client->packed(),
            'user_agent' => $this->userAgent->header(),
            'request_id' => $this->requestId->current(),
            'created_at' => $this->clock->timestamp(),
        ]);
    }

    /**
     * The fields whose value differs between the two states.
     *
     * A creation reports every field of `after`, a removal every field of
     * `before`, and an update only what moved. Derived rather than
     * declared: a caller stating "I changed the name" while writing the
     * description too would produce a line that reads correctly and is
     * false, and nothing downstream could tell.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return list<string>
     */
    public static function changedFields(?array $before, ?array $after): array
    {
        $keys = array_unique(array_merge(
            array_keys($before ?? []),
            array_keys($after ?? []),
        ));

        sort($keys);

        $changed = [];

        foreach ($keys as $key) {
            $was = ($before ?? [])[$key] ?? null;
            $now = ($after ?? [])[$key] ?? null;

            if ($was !== $now) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * The acting employee's display name at this moment, or null.
     *
     * Null when there is no actor, and - deliberately - also when the actor
     * id names nobody: a name invented for an unknown id would be worse
     * than its absence, and the id itself is still on the row.
     */
    private function actorName(Connection $connection, ?string $actorId): ?string
    {
        if ($actorId === null) {
            return null;
        }

        $name = $connection->table('employees')->where('id', $actorId)->value('display_name');

        return $name === null ? null : (string) $name;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
