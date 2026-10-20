<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SyncPermissionRegistry;

/**
 * What one registry sync did (AC-15, AC-16).
 *
 * **Keys, not only counts.** AC-15 asks for the four counts, and
 * {@see self::counts()} renders them; the lists are kept because a count
 * cannot tell an operator *which* key was orphaned or *which* flip was
 * refused, and because a test asserting "one key was updated" is satisfied by
 * the wrong key being updated.
 *
 * **`refused` is a fifth outcome and not a failure.** A key whose
 * `is_branch_scoped` would flip is left exactly as it is and reported, while
 * the rest of the sync proceeds (AC-15): the sync is the mechanism that keeps
 * a deployment's table in step with its code, and one disagreeing key must
 * not stop the other hundred from arriving. The whole-sync failure is a
 * different case - a declaration set that cannot be reconciled with itself -
 * and that one throws before anything is written.
 */
final class SyncPermissionRegistryResult
{
    /**
     * @param  list<string>  $inserted  declared keys the table did not hold
     * @param  list<string>  $updated  declared keys whose attributes moved
     * @param  list<string>  $unchanged  declared keys already stored exactly
     * @param  list<string>  $orphaned  stored keys nothing declares - never deleted
     * @param  list<string>  $refused  stored keys whose is_branch_scoped would flip
     */
    public function __construct(
        public readonly array $inserted,
        public readonly array $updated,
        public readonly array $unchanged,
        public readonly array $orphaned,
        public readonly array $refused,
    ) {}

    /**
     * The report AC-15 asks for.
     *
     * @return array{inserted: int, updated: int, unchanged: int, orphaned: int, refused: int}
     */
    public function counts(): array
    {
        return [
            'inserted' => count($this->inserted),
            'updated' => count($this->updated),
            'unchanged' => count($this->unchanged),
            'orphaned' => count($this->orphaned),
            'refused' => count($this->refused),
        ];
    }

    /**
     * Did this sync change any row at all?
     *
     * The question step 7's bump asks: a sync that inserted and updated
     * nothing has changed nobody's rights, so nothing may be bumped by it.
     */
    public function changedNothing(): bool
    {
        return $this->inserted === [] && $this->updated === [];
    }
}
