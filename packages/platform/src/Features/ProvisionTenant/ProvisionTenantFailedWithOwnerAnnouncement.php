<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ProvisionTenant;

use Flexipos\Platform\Provisioning\OwnerCredentialAnnouncement;
use Flexipos\Platform\Provisioning\ProvisioningStepFailed;
use RuntimeException;
use Throwable;

/**
 * Review round 1 R-1. `create_owner` ran (and, for a `created`/`rotated`
 * outcome, generated a password this process holds in memory only) before
 * a *later* step (`verify`, `activate`, or `create_owner`'s own
 * `linkUser()` half) failed. Without this, the command's catch block would
 * print only the error, and the password - which exists nowhere else; AC-18
 * forbids journalling it - would be gone the moment this process exits.
 *
 * Carries the original failure's message (never the original throwable
 * itself, matching {@see ProvisioningStepFailed}'s
 * own "do not let a caller recover what the sanitiser redacted" rule) plus
 * a verbatim copy of {@see OwnerCredentialAnnouncement}'s
 * state at the moment of failure, so `ProvisionTenantCommand` can print the
 * owner's password *before* the error - the one chance this process gets.
 */
final class ProvisionTenantFailedWithOwnerAnnouncement extends RuntimeException
{
    public function __construct(
        public readonly ?string $ownerMode,
        public readonly ?string $ownerPassword,
        Throwable $original,
    ) {
        // The original is not kept as $previous: ProvisioningStepFailed's
        // own message is already the sanitised one, and this class must not
        // become a second path by which an un-sanitised cause could reach a
        // caller that walks getPrevious().
        parent::__construct($original->getMessage());
    }
}
