<?php

declare(strict_types=1);

namespace Flexipos\Auth\Tenancy;

/**
 * The one authority for "which `tenant_users.status` lets a person use a
 * tenant".
 *
 * **Why a class for one word.** Three slices ask the same question of the
 * same column - the login tenant list (AC-11), the tenant selection refusal
 * (AC-40(a)) and the tenant-scoped refresh re-check (AC-37) - and the answer
 * is a literal `'active'` in a `where` clause. Three literals in three
 * handlers is the shape that drifts: the day an `owner_pending` state is
 * added, two of them are updated. It is the same reasoning
 * {@see TenantSelectionPolicy} exists for, with one difference that is worth
 * stating rather than hiding - **the membership answer is a single value and
 * the commercial answer is a set**, which is why this is a constant and that
 * is an injectable object with a moved-value proof behind it. A set that can
 * be widened is an access decision; one value is a vocabulary.
 *
 * **This is not the tenant's commercial status and not the tenant database's
 * serving status.** Three different columns answer three different
 * questions, and a login applies the first two (AND) while the kernel's gate
 * applies the third on every later request (D-9).
 *
 * The other `tenant_users.status` values T-009 knows of - `invited`,
 * `suspended` - and a soft-deleted membership row are all simply "not this
 * one"; none of them is enumerated anywhere, because a status this class
 * does not name is refused by default, which is the safe direction.
 */
final class MembershipStatus
{
    /**
     * The only membership status that grants anything.
     */
    public const ACTIVE = 'active';
}
