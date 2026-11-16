<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * The side of the ledger an account normally carries (T-032 AC-6).
 */
enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
