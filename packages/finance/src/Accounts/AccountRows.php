<?php

declare(strict_types=1);

namespace Flexipos\Finance\Accounts;

use Flexipos\Contracts\Finance\AccountSubtype;
use Flexipos\Contracts\Finance\AccountType;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Finance\Support\TenantConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Every read and write of the `accounts` table (T-032 plan Ruling 6, section
 * 0.3 W-4 and W-1).
 *
 * **The construction that makes the locked-read rule checkable.** A write
 * handler never builds a query on `accounts` itself and never calls a
 * terminal read (`first`, `get`, `exists`, `count`, `value`, `pluck`) on one:
 * it calls a method here, and **the method's name states its lock mode**.
 * `liveForUpdate`, `anySharedLock` and `liveChildCountSharedLock` are locking
 * reads for the write path; the `*Unlocked` methods are the only plain reads
 * and belong to the read slices. A source scan (plan step 19) enforces both
 * halves, so a decision cannot quietly rest on a stale snapshot under
 * REPEATABLE READ.
 *
 * Methods return the row as the query builder hands it back (a `stdClass`),
 * and {@see self::state()} turns one into the {@see AccountState} the pure
 * rules decide on.
 *
 * **Not final, on purpose**: a test replaces a read with a blind one to prove
 * that the unique-index backstops (code, control marker) catch what a racing
 * pre-check would miss, because the stand-in has no second connection to race
 * with.
 */
class AccountRows
{
    public function __construct(private readonly TenantConnection $tenant) {}

    // -- Locking reads: the write path ----------------------------------

    /**
     * The target of a write, **live only**, `FOR UPDATE` (AC-20, AC-21). A
     * soft-deleted or absent row is `null`, which every handler refuses
     * `account_not_live`.
     */
    public function liveForUpdate(string $id): ?object
    {
        return $this->query()->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
    }

    /**
     * A row of any liveness, `FOR UPDATE` (re-reading the account a write has
     * just changed, so the audit `after` is what the database holds).
     */
    public function anyForUpdate(string $id): ?object
    {
        return $this->query()->where('id', $id)->lockForUpdate()->first();
    }

    /**
     * A row of any liveness under a shared lock: the parent a child is being
     * hung under, so a concurrent delete, deactivation or turn into a postable
     * account cannot happen between the check and the insert (AC-12).
     */
    public function anySharedLock(string $id): ?object
    {
        return $this->query()->where('id', $id)->sharedLock()->first();
    }

    /**
     * The row holding a code, live or soft-deleted: a soft-deleted account
     * keeps its code reserved (AC-3). Also the re-read after a unique
     * violation, which must be a locking read (W-1).
     */
    public function byCodeSharedLock(string $code): ?object
    {
        return $this->query()->where('code', $code)->sharedLock()->first();
    }

    /**
     * The live account holding a single-holder control type, other than
     * `$exceptId` (AC-17).
     */
    public function liveHolderOfControlSharedLock(ControlType $type, ?string $exceptId = null): ?object
    {
        $query = $this->query()->where('control_type', $type->value)->whereNull('deleted_at');

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->sharedLock()->first();
    }

    /**
     * Live children of an account: what makes a header unable to turn
     * postable, a type unable to change and a delete refused (AC-14, AC-15,
     * AC-23). A locking read, so a concurrent child create is seen or waited
     * for.
     */
    public function liveChildCountSharedLock(string $id): int
    {
        return (int) $this->query()->where('parent_id', $id)->whereNull('deleted_at')->sharedLock()->count();
    }

    /**
     * Live **and active** children (AC-24: an active child refuses
     * deactivation).
     */
    public function activeChildCountSharedLock(string $id): int
    {
        return (int) $this->query()->where('parent_id', $id)->whereNull('deleted_at')
            ->where('is_active', true)->sharedLock()->count();
    }

    /**
     * Every account row, live and soft-deleted, `FOR UPDATE`, in id order: the
     * whole-chart lock a re-parent takes (plan Ruling 6, the T-027
     * `product_components` precedent). Re-parent is rare and the chart is at
     * most a few thousand rows.
     *
     * @return list<object>
     */
    public function allForUpdate(): array
    {
        return array_values($this->query()->orderBy('id')->lockForUpdate()->get()->all());
    }

    // -- Writes ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws UniqueConstraintViolationException
     */
    public function insert(array $row): void
    {
        $this->query()->insert($row);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(string $id, array $changes): void
    {
        $this->query()->where('id', $id)->update($changes);
    }

    // -- Plain reads: the read slices only --------------------------------

    public function byIdUnlocked(string $id): ?object
    {
        return $this->query()->where('id', $id)->first();
    }

    /**
     * The `parent_id` of a row, live or not, without a lock: **only** to learn
     * which parent to lock before the child (parent first, child second, the
     * order every account write shares). The caller re-reads the child under
     * its lock and compares, so a stale answer costs a late lock, never a
     * wrong decision.
     */
    public function parentIdUnlocked(string $id): ?string
    {
        $value = $this->query()->where('id', $id)->value('parent_id');

        return $value === null ? null : (string) $value;
    }

    public function liveByIdUnlocked(string $id): ?object
    {
        return $this->query()->where('id', $id)->whereNull('deleted_at')->first();
    }

    /**
     * The row holding a code, live or soft-deleted (a deleted account keeps
     * its code reserved, so a reader must be able to say "deleted").
     */
    public function byCodeUnlocked(string $code): ?object
    {
        return $this->query()->where('code', $code)->first();
    }

    /**
     * @return list<object>
     */
    public function liveByControlTypeUnlocked(ControlType $type): array
    {
        return array_values($this->query()->where('control_type', $type->value)->whereNull('deleted_at')
            ->orderBy('code')->orderBy('id')->get()->all());
    }

    /**
     * Every live account, parents before children, one query (AC-61, NFR).
     *
     * @return list<object>
     */
    public function liveTreeUnlocked(): array
    {
        return array_values($this->query()->whereNull('deleted_at')
            ->orderBy('depth')->orderBy('code')->orderBy('id')->get()->all());
    }

    /**
     * One keyset page of live accounts, `ORDER BY code, id`, after the cursor
     * (AC-61). `$filters` are already validated, in final form; `q` is matched
     * with `LIKE` over code and name, the wildcards escaped with `!` (an
     * `ESCAPE` character that needs no extra literal escaping on either
     * engine).
     *
     * @param  array<string, mixed>  $filters
     * @return list<object>
     */
    public function pageUnlocked(array $filters, int $limit, ?string $afterCode, ?string $afterId): array
    {
        $query = $this->query()->whereNull('deleted_at');

        foreach (['type', 'subtype', 'control_type', 'is_active', 'is_postable'] as $column) {
            if (array_key_exists($column, $filters)) {
                $query->where($column, $filters[$column]);
            }
        }

        if (array_key_exists('parent_id', $filters)) {
            $filters['parent_id'] === null ? $query->whereNull('parent_id') : $query->where('parent_id', $filters['parent_id']);
        }

        if (isset($filters['q'])) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['q']).'%';
            $query->whereRaw("(code like ? escape '!' or name like ? escape '!')", [$like, $like]);
        }

        if ($afterCode !== null && $afterId !== null) {
            $query->where(static function (Builder $keyset) use ($afterCode, $afterId): void {
                $keyset->where('code', '>', $afterCode)
                    ->orWhere(static function (Builder $tie) use ($afterCode, $afterId): void {
                        $tie->where('code', $afterCode)->where('id', '>', $afterId);
                    });
            });
        }

        return array_values($query->orderBy('code')->orderBy('id')->limit($limit)->get()->all());
    }

    // -- Row to value ------------------------------------------------------

    /**
     * What the pure rules decide on, from a stored row.
     */
    public function state(object $row): AccountState
    {
        /** @var object{id: string, type: string, is_postable: int|bool, is_active: int|bool, deleted_at: ?string, is_system: int|bool, path: string, depth: int|string} $row */
        return new AccountState(
            (string) $row->id,
            AccountType::from((string) $row->type),
            (bool) $row->is_postable,
            (bool) $row->is_active,
            $row->deleted_at !== null,
            (bool) $row->is_system,
            (string) $row->path,
            (int) $row->depth,
        );
    }

    /**
     * The stored subtype, or null.
     */
    public function subtypeOf(object $row): ?AccountSubtype
    {
        /** @var object{subtype: ?string} $row */
        return $row->subtype === null ? null : AccountSubtype::from((string) $row->subtype);
    }

    private function query(): Builder
    {
        return $this->tenant->get()->table('accounts');
    }
}
