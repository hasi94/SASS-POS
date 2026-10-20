<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;

/**
 * The one writer of `audit_logs` in this package (D-20, AC-70).
 *
 * **Minimal on purpose, and replaced later.** D-20 says the audit facility
 * proper is FP-211's; what this task owes is that every administrative
 * operation leaves a row, with the actor, the subject and what changed.
 * There is no retention, no search and no export here, and `qa` must not
 * ask for them.
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
final class AuditWriter
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
        $connection->table('audit_logs')->insert([
            'actor_type' => self::ACTOR_TYPE,
            'actor_id' => $actorId,
            'actor_name' => $this->actorName($connection, $actorId),
            'branch_id' => $branchId,
            'module' => self::MODULE,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before === null ? null : self::encode($before),
            'after' => $after === null ? null : self::encode($after),
            'changed_fields' => self::encode(self::changedFields($before, $after)),
            // AC-70 names `ip` among the fields the row carries. It comes
            // from the request the container holds, packed by the one
            // helper inside this package, and is null when there is no
            // request - see ClientIp. `user_agent` and `request_id` are
            // columns T-008 created and no criterion of this task names;
            // they stay null until a task owns them, which is a different
            // fact from this one having been forgotten.
            'ip' => $this->client->packed(),
            'user_agent' => null,
            'request_id' => null,
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
