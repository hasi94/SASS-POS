<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

/**
 * Implemented by a module's service provider to declare the permission keys
 * that module owns (AC-14).
 *
 * **Declaration is how a key becomes known, and the registry's domain is the
 * set of booted providers implementing this.** That is a narrower set than
 * "every key the product has": a module not named in `MODULES` declares
 * nothing in this process. The registry sweep (AC-18) must publish that
 * difference rather than imply it does not exist - shape 7, an instrument
 * discriminating perfectly over the wrong domain.
 *
 * It lives in `packages/contracts` because a module implementing it must not
 * have to depend on `packages/access` to do so.
 */
interface DeclaresPermissions
{
    /**
     * Every permission key this provider owns.
     *
     * @return list<PermissionDeclaration>
     */
    public function permissionDeclarations(): array;
}
