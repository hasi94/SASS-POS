<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ProvisionTenant;

use Flexipos\Platform\Provisioning\OwnerCredentialAnnouncement;
use Flexipos\Platform\Provisioning\OwnerPasswordReissue;

/**
 * What `tenant:provision` reports back (T-026 AC-2 to AC-4).
 *
 * `$ownerMode`/`$ownerPassword` are `create_owner`'s own announcement
 * (D-26, {@see OwnerCredentialAnnouncement}),
 * carried here only because this result is itself an in-memory value the
 * command reads once and discards - never persisted, logged or journalled.
 * Both are null when `create_owner` did not run this attempt (every step
 * already `done` on a resume that only needed to finish `verify`/`activate`).
 *
 * **`$ownerMode` grows two more values with review round 2's R-3 fix**,
 * neither ever set by `create_owner` itself - both only ever come from
 * {@see OwnerPasswordReissue}, reached from
 * `ProvisionTenantHandler::resume()` or `--reissue-owner-password`:
 * `rotated` here means the same thing D-31 always has (a fresh password,
 * carried in `$ownerPassword`) but reached after `create_owner` was already
 * `done` and skipped this attempt; `already_signed_in` means D-31's
 * precondition failed - the login has been signed into, or its password
 * already changed - printed as its own explicit sentence rather than
 * silently reporting nothing.
 *
 * `$abandonMessage` is step 16's own `Features\AbandonProvisioning`
 * result, carried the same way: null for every `create`/`--resume` call,
 * and the spec's "was not dropped" sentence (or its "never reached
 * create_database" variant) for an `--abandon` call.
 */
final class ProvisionTenantResult
{
    public function __construct(
        public readonly string $runId,
        public readonly string $status,
        public readonly ?string $ownerMode = null,
        public readonly ?string $ownerPassword = null,
        public readonly ?string $abandonMessage = null,
    ) {}
}
