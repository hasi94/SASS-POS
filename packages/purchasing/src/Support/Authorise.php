<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Support;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;

/**
 * The one permission assertion every purchasing slice makes (T-034 Ruling 8,
 * AC-18, AC-85, AC-86).
 *
 * **Which branch is asked is the caller's decision and the rule is not
 * negotiable.** A document slice passes the document's **stored** branch (AC-85
 * (d)), never one a caller supplied that differs from it; a supplier slice passes
 * `null`, because the supplier keys are tenant-wide and a null-branch question on
 * a branch-scoped key would be `branch_required`. The resolver answers every
 * other case, including a suspended, inactive or deleted employee (AC-86), with
 * a reason of its own, and that reason is carried as the refusal's cause.
 */
final class Authorise
{
    /**
     * Fold the actor, ask the resolver, refuse with `not_authorised` when it says
     * no. Returns the folded actor id, so a slice uses the value it checked.
     *
     * @throws PurchasingRefused validation (no actor, malformed ids), not_authorised
     */
    public static function require(PermissionResolver $resolver, ?string $actorId, string $key, ?string $branchId): string
    {
        if ($actorId === null) {
            throw PurchasingRefused::validation('A purchasing action is taken by an employee.');
        }

        $actor = Identifier::fold($actorId, 'actor');
        $branch = Identifier::foldNullable($branchId, 'branch_id');

        $decision = $resolver->decide($actor, $key, $branch);

        if (! $decision->allowed) {
            throw PurchasingRefused::fromAccess($decision, "The employee may not do this [{$key}].");
        }

        return $actor;
    }

    /**
     * Whether the actor holds any one of these keys: `GetSupplier` accepts `view`
     * or `manage`. Only the allowing answer is returned; the refusal to throw is
     * the last key's, so the cause is a real resolver reason.
     *
     * @param  list<string>  $keys  at least one
     * @return array{actor: string, key: string} the folded actor and the first key that allowed
     *
     * @throws PurchasingRefused validation, not_authorised
     */
    public static function requireAny(PermissionResolver $resolver, ?string $actorId, array $keys, ?string $branchId): array
    {
        $last = null;

        foreach ($keys as $key) {
            try {
                return ['actor' => self::require($resolver, $actorId, $key, $branchId), 'key' => $key];
            } catch (PurchasingRefused $refused) {
                if ($refused->code() !== 'not_authorised') {
                    throw $refused;
                }

                $last = $refused;
            }
        }

        throw $last ?? PurchasingRefused::validation('No permission key was named.');
    }
}
