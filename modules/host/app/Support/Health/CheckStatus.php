<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * The only words a readiness check may answer with (T-021 AC-45): the body
 * holds no hostname, credential, version or tenant identifier, only one of
 * these and a module name.
 */
enum CheckStatus: string
{
    case Ok = 'ok';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
