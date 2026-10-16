<?php

declare(strict_types=1);

namespace Flexipos\Auth\Support;

use Flexipos\Auth\Features\Login\LoginCredentials;

/**
 * The one place a submitted address becomes a lookup key.
 *
 * **Two forms, and they are not interchangeable.** {@see self::submitted()}
 * is what the person typed, trimmed and otherwise untouched - the form
 * `login_history.email` records as evidence (AC-23) and the form
 * `users.email` stores, because the display form is the user's own (AC-2
 * keeps `Owner@Example.com` as `Owner@Example.com`).
 * {@see self::normalised()} is the lookup key, and it exists because
 * `users.email_lower` is a **stored generated column** holding `LOWER(email)`
 * with its own unique index: the lookup is an indexed equality on that
 * column, so the value compared against it has to be produced somewhere, once.
 *
 * **Trimming is part of the criterion, not politeness.** AC-2 requires
 * `  owner@example.COM  ` to sign in as `Owner@Example.com`. Both halves -
 * the case and the surrounding whitespace - are handled here rather than in a
 * handler, because a second handler would do it slightly differently.
 *
 * **One known divergence between this class and the generated column, and it
 * is the stand-in's.** The column is computed by SQL `LOWER()`. On MySQL with
 * a utf8mb4 collation that lowercases non-ASCII letters, which is what
 * `mb_strtolower` does here; on SQLite, `LOWER()` is ASCII-only, so an address
 * whose local part carries a non-ASCII capital would normalise here and not
 * there, and the lookup would miss **on the stand-in only**. No criterion
 * exercises such an address and no fallback is invented for one: this is
 * recorded so that a future non-ASCII report is read as the stand-in artefact
 * it is, and not as a defect in the lookup.
 *
 * **Lengths are counted in characters, not bytes** - `mb_strlen` - which is
 * what `users.email`'s `VARCHAR(191)` counts and what
 * `authentication.limits.email` means. This class only reports the length;
 * deciding what is too long belongs to the slice that refuses it
 * ({@see LoginCredentials}), because the limit
 * is configuration and this is a value.
 */
final class EmailAddress
{
    private function __construct(
        /**
         * The address as it was typed, with surrounding whitespace removed.
         */
        public readonly string $submitted,
    ) {}

    /**
     * An address from whatever the client sent, trimmed.
     */
    public static function fromSubmitted(string $value): self
    {
        return new self(trim($value));
    }

    /**
     * The lookup key: `users.email_lower`'s value for this address.
     */
    public function normalised(): string
    {
        return mb_strtolower($this->submitted);
    }

    /**
     * Characters, not bytes - the unit both the column and the limit use.
     */
    public function length(): int
    {
        return mb_strlen($this->submitted);
    }

    /**
     * True when nothing but whitespace was submitted.
     */
    public function isEmpty(): bool
    {
        return $this->submitted === '';
    }
}
