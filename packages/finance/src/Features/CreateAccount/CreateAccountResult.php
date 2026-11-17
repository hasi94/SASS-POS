<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\CreateAccount;

/**
 * The id of the account a {@see CreateAccountHandler} created.
 */
final readonly class CreateAccountResult
{
    public function __construct(public string $accountId) {}
}
