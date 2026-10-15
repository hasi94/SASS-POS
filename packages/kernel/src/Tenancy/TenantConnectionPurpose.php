<?php

namespace Flexipos\Kernel\Tenancy;

/**
 * Why a tenant scope is being entered: to serve a request, or to migrate the
 * tenant's schema.
 *
 * The purpose selects the mapping source and nothing else. Both purposes write
 * their stanza through TenantConnectionAuthority, by the same single
 * connection-construction path (AC-60), so every T-007 isolation proof holds
 * for a migration scope exactly as it does for a request.
 *
 * It is an argument rather than a container binding that something swaps while
 * the runner runs. A mutated binding is the kind of invisible state T-007's
 * review removed, and a swap that leaked would let a request resolve the
 * migrate credential.
 *
 * **One rule is waived by `Migrating`, and it is named here so nobody reads the
 * enum as a general bypass:** the serving schema-version gate. A tenant below
 * the required version is the only kind of tenant the runner ever migrates, so
 * a gate that refused it would refuse the runner its only job (AC-19's
 * exemption; plan section 9, finding 2). Every other refusal - the identifier
 * check, re-entrancy, "no mapping", the status gate, the driver whitelist and
 * the credential dereference - applies to both purposes identically.
 */
enum TenantConnectionPurpose: string
{
    case Serving = 'serving';
    case Migrating = 'migrating';
}
