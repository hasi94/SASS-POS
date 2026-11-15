<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Support;

/**
 * The expand / backfill / contract phase of one migration file.
 *
 * `docs/database.md` section 9.4 makes the phase the unit of discipline: an
 * expand step may only add, a contract step is the only place a drop, a rename
 * or a new foreign key onto an existing table is allowed, and a backfill step
 * moves data without changing shape. The catalogue (`schema_versions.phase`)
 * records it per migration, and `SourceRules` enforces what each phase may do.
 *
 * **How a file declares its phase, and why the default is the strictest one.**
 * The phase is a `@phase <name>` tag in a comment at the top of the migration
 * file. A file that declares nothing is `expand`, which is the phase that may do
 * the least: forgetting the tag therefore produces a *refusal* of the risky
 * statement rather than a silent licence for it. A contract step has to say so
 * in writing, which is exactly the pressure D-5 asks for - the four foreign keys
 * to `branches` and `employees` cannot be slipped into an expand migration
 * without someone typing the word.
 *
 * The tag is read out of a comment while every rule in `SourceRules` reads code
 * with the comments stripped. That asymmetry is deliberate and it is the one
 * place in the task where a comment is load bearing: the phase is metadata
 * about the file, not a statement the file executes.
 */
enum MigrationPhase: string
{
    case Expand = 'expand';
    case Backfill = 'backfill';
    case Contract = 'contract';

    /**
     * The phase a migration file declares, or `expand` when it declares none.
     *
     * Only a comment is consulted, and only the first tag in the file, so a
     * second `@phase` line further down cannot quietly widen the first. A tag
     * naming something that is not a phase - a typo - is `expand` too, for the
     * same fail-closed reason: a misspelled `contract` must not be a licence.
     */
    public static function fromSource(string $source): self
    {
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (preg_match('/@phase\s+([a-z]+)/', (string) $token[1], $matches) !== 1) {
                continue;
            }

            return self::tryFrom($matches[1]) ?? self::Expand;
        }

        return self::Expand;
    }
}
