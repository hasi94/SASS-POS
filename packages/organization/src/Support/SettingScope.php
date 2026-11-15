<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Organization\Exceptions\OrganizationRefused;

/**
 * Where a setting applies: the one parse of (scope type, scope id) every
 * settings operation shares (T-014 AC-45, AC-46, AC-51).
 *
 * - `company` takes **no** id: the NULL id is the meaning, and an id sent with
 *   it is `validation`, not ignored.
 * - `branch` and `terminal` need a 26-character ULID, folded to upper case
 *   (AC-61) like every other identifier here.
 * - `employee` is the fourth layer of the stored hierarchy and is **not
 *   writable and not read** (D-23): `scope_not_supported`, whatever the id.
 * - any other type is `validation`.
 *
 * Existence is not checked here: a write needs the scope to be usable, a clear
 * does not (D-6), and a read answers `not_found`, so each caller asks the
 * question it needs.
 */
final class SettingScope
{
    public const COMPANY = 'company';

    public const BRANCH = 'branch';

    public const TERMINAL = 'terminal';

    public const EMPLOYEE = 'employee';

    private function __construct(
        public readonly string $type,
        public readonly ?string $id,
    ) {}

    /**
     * @throws OrganizationRefused validation, scope_not_supported
     */
    public static function parse(mixed $type, mixed $id): self
    {
        if (! is_string($type)) {
            throw OrganizationRefused::validation('The scope_type is required and must be a string.');
        }

        if ($type === self::EMPLOYEE) {
            throw OrganizationRefused::scopeNotSupported('The employee layer of settings is not supported.');
        }

        if ($type === self::COMPANY) {
            if ($id !== null) {
                throw OrganizationRefused::validation('A company scope takes no scope_id.');
            }

            return new self(self::COMPANY, null);
        }

        if ($type !== self::BRANCH && $type !== self::TERMINAL) {
            throw OrganizationRefused::validation('The scope_type must be company, branch or terminal.');
        }

        return new self($type, Identifier::fold($id, 'scope_id'));
    }
}
