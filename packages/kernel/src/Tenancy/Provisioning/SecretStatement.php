<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning;

use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use Flexipos\Kernel\Tenancy\Secrets\Secret;
use JsonSerializable;
use Stringable;

/**
 * A SQL statement carrying a password, built so that every way PHP can be
 * made to show its inside shows `[redacted]` (T-026 AC-18, Ruling 4).
 *
 * `sql()` is the one audited call site, and only
 * {@see MySqlTenantDatabaseProvisioner} ever calls it, immediately before
 * handing the result to the raw PDO - so no `QueryExecuted` listener (and
 * therefore no log line this project does not control) ever sees the
 * rendered statement. The password itself lives inside a {@see Secret},
 * which carries no state of its own (`(array) $secret` is empty), so this
 * object's own properties hold nothing a dumper or `var_export()` could
 * show but the template string and that empty object.
 */
final readonly class SecretStatement implements JsonSerializable, Stringable
{
    /**
     * @param  string  $template  contains exactly one occurrence of
     *                            {@see self::PLACEHOLDER}, where the quoted
     *                            password literal is substituted. A plain
     *                            `sprintf('%s', ...)` is not used here: the
     *                            MySQL/MariaDB host pattern `%` is a
     *                            legitimate part of these statements
     *                            (`@'%'`), and sprintf would read it as its
     *                            own directive.
     */
    public function __construct(
        private string $template,
        private Secret $password,
    ) {}

    public const PLACEHOLDER = '%%PASSWORD%%';

    /**
     * The only call site allowed to read this. Quotes and escapes the
     * password itself, so no caller ever needs the raw value to build the
     * statement.
     */
    public function sql(): string
    {
        $escaped = str_replace("'", "\\'", $this->password->reveal());

        return str_replace(self::PLACEHOLDER, "'".$escaped."'", $this->template);
    }

    public function __toString(): string
    {
        return TenancyException::REDACTED;
    }

    public function jsonSerialize(): string
    {
        return TenancyException::REDACTED;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['sql' => TenancyException::REDACTED];
    }
}
