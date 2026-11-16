<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

use InvalidArgumentException;

/**
 * An immutable view of one account, as another module may see it (T-032
 * AC-63).
 *
 * **Shape only**: the id must be a ULID (folded to upper case). Whether the
 * type, subtype, normal balance and control type agree with each other is
 * `AccountRules`' business on the way in, not this constructor's.
 */
final readonly class AccountSnapshot
{
    public string $id;

    public function __construct(
        string $id,
        public string $code,
        public string $name,
        public AccountType $type,
        public ?AccountSubtype $subtype,
        public NormalBalance $normalBalance,
        public bool $isPostable,
        public bool $isActive,
        public ControlType $controlType,
    ) {
        if (preg_match('/\A[0-9A-Za-z]{26}\z/', $id) !== 1) {
            throw new InvalidArgumentException('[id] is not a 26-character ULID.');
        }

        $this->id = strtoupper($id);
    }
}
