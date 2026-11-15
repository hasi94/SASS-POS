<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\MigrateTenant;

use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use SensitiveParameter;

/**
 * The text the runner is allowed to store about a failure (AC-45).
 *
 * A migration failure is the one place in this system where a driver's own
 * message - which on a rejected login can contain the credential, and on a DSN
 * error can contain the whole stanza - is written into a column an operator
 * later reads. So nothing reaches `tenant_migration_jobs.error_message` or
 * `tenant_databases.last_migration_error` without passing through here.
 *
 * **Two rules, and the second one is the one that is not merely tidy.**
 *
 * 1. Every needle the caller names is replaced: the resolved credential value
 *    and the credential *reference* for the tenant being migrated. That is the
 *    criterion's literal content.
 * 2. Every remaining reference-shaped token - `scheme://rest` - is replaced
 *    too, whether or not the caller knew about it. Rule 1 can only redact the
 *    references the handler had in its hand, and a cause thrown from deeper
 *    (another tenant's mapping in a batched message, a provisioner reference in
 *    a server-level error) carries ones it never saw. A rule that redacts only
 *    what it was told about is a rule whose coverage is an accident of the call
 *    site. The cost is that a harmless URL in an error message is redacted
 *    too, which is the right direction to be wrong in.
 *
 * The result is truncated, because `last_migration_error` is a `TEXT` column
 * and a multi-megabyte stack trace in a master table is its own incident.
 * Truncation happens **after** redaction, never before: cutting first could
 * leave a half-redacted secret.
 */
final class MigrationErrorText
{
    /**
     * How much of a failure is worth keeping in a master column.
     */
    public const MAX_LENGTH = 2000;

    /**
     * The sanitised form of one message.
     *
     * @param  list<string>  $needles  values that must not survive - the
     *                                 resolved credential and the credential
     *                                 reference
     */
    public static function sanitise(string $message, #[SensitiveParameter] array $needles = []): string
    {
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }

            $message = str_replace($needle, TenancyException::REDACTED, $message);
        }

        $message = (string) preg_replace(
            '~\b[a-z][a-z0-9+.\-]*://\S+~i',
            TenancyException::REDACTED,
            $message
        );

        if (strlen($message) <= self::MAX_LENGTH) {
            return $message;
        }

        return substr($message, 0, self::MAX_LENGTH - 3).'...';
    }
}
