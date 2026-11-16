<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * The closed refusal vocabulary of {@see ChartOfAccounts} (T-032 Ruling 2,
 * AC-55, AC-63).
 */
enum AccountRefusal: string
{
    /** No account has this id or code. */
    case AccountNotFound = 'account_not_found';

    /** The account is soft-deleted. */
    case AccountNotLive = 'account_not_live';

    /** The account is deactivated. */
    case AccountInactive = 'account_inactive';

    /** The account is a header and takes no postings. */
    case AccountNotPostable = 'account_not_postable';

    /** The account's type or subtype does not fit the {@see AccountKind} asked for. */
    case AccountKindMismatch = 'account_kind_mismatch';

    /** The code is not a well-formed account code (AC-2). */
    case InvalidCode = 'invalid_code';
}
