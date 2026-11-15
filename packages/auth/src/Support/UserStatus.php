<?php

declare(strict_types=1);

namespace Flexipos\Auth\Support;

/**
 * The one authority for "which `users.status` may authenticate".
 *
 * `users.status` had no reader before T-009, the same way `tenants.status`
 * did not (plan section 1), and authentication is the first code with an
 * opinion about it. Two slices form that opinion - the login status refusal
 * (AC-7) and the refresh-time re-check that cuts a live session off when the
 * account stops being `active` (D-13, AC-36) - so the value is named once
 * here rather than written as a literal in each.
 *
 * **Deny by default, and no list of the refused states.** `invited`,
 * `disabled` and `locked` are refused because they are not {@see self::ACTIVE},
 * not because they are enumerated; a status added by a later migration is
 * refused on the day it is added rather than on the day somebody remembers to
 * add it to a list. A soft-deleted row never reaches this question at all -
 * `deleted_at` is filtered in the lookup, so an absent account and a deleted
 * one are the same account (AC-7, AC-23's `unknown_user`).
 *
 * **Not `locked_until`.** The lockout is a timer on an otherwise `active`
 * account - AC-17 requires `users.status` to still read `active` after a
 * lockout - and it is a different refusal with a different reason in
 * `login_history`.
 */
final class UserStatus
{
    /**
     * The only status that may sign in.
     */
    public const ACTIVE = 'active';
}
