<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning;

/**
 * Which of the two grant lists `createUser()` issues (T-026 AC-16,
 * docs/database.md section 2).
 */
enum TenantGrant: string
{
    case App = 'app';
    case Migrate = 'migrate';
}
