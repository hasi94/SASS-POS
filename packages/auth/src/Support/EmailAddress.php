<?php

declare(strict_types=1);

namespace Flexipos\Auth\Support;

use Flexipos\Auth\Features\Login\LoginCredentials;

/**
 * The one place a submitted address becomes a lookup key.
 *
 * **Two forms, and they are not interchangeable.** {@see self::submitted()}
 * is what the person typed, trimmed and otherwise untouched - the form
 * `login_history.email` records as evidence when it is address-shaped
 * ({@see self::forHistory()}, D-38) and the form
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
 * **The shape test is a shape test and nothing more** (D-38). AC-23 stores
 * the submitted value in `login_history.email` only when it is address-shaped
 * and the marker {@see self::NOT_AN_EMAIL} otherwise, because a password typed
 * into the email field was otherwise kept in clear text in a table that is
 * never pruned (D-17). {@see self::isAddressShaped()} answers that question
 * and nothing else: it never asks whether the address exists, never contacts a
 * resolver, and is not used by the lookup - `user@exmaple.con` is a typo'd
 * domain, is address-shaped, and is exactly the signal an investigation reads,
 * so it is kept byte for byte. The residual D-38 accepts is written into
 * AC-26: an address-shaped password such as `P@ss.word1` is stored, because no
 * shape rule can separate it from an address.
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
    /**
     * What `login_history.email` holds when what was submitted is not an
     * address (AC-23, D-38).
     *
     * A fixed marker rather than an empty string or a null: the column is not
     * nullable, and a reader of the table has to be able to tell "something
     * was typed here that was not an address" from "an address was typed".
     * Its length is measured against the column's limit by a test, because a
     * constant nobody measures is the kind that fails on a column constraint.
     */
    public const NOT_AN_EMAIL = '(not an email)';

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

    /**
     * Whether the trimmed value has the shape of an address (D-38).
     *
     * The rule, exactly as AC-23 words it: no whitespace, exactly one `@`, a
     * non-empty part before it, and after it a non-empty part containing a
     * dot that neither starts nor ends with a dot. Nothing more - this is not
     * RFC 5322 and must not grow into it, because every rule added here
     * silently discards a class of real evidence from `login_history`.
     *
     * A value PCRE cannot scan is treated as not address-shaped: the fallback
     * is the marker, which loses text that has no pattern value, while the
     * other direction would store an unreadable byte string as an address.
     */
    public function isAddressShaped(): bool
    {
        if ($this->submitted === '' || preg_match('/\s/u', $this->submitted) !== 0) {
            return false;
        }

        $at = strpos($this->submitted, '@');

        if ($at === false || substr_count($this->submitted, '@') !== 1) {
            return false;
        }

        $local = substr($this->submitted, 0, $at);
        $domain = substr($this->submitted, $at + 1);

        return $local !== ''
            && $domain !== ''
            && str_contains($domain, '.')
            && ! str_starts_with($domain, '.')
            && ! str_ends_with($domain, '.');
    }

    /**
     * What `login_history.email` records for this value (AC-23, D-38): the
     * trimmed value when it is address-shaped, the marker when it is not.
     */
    public function forHistory(): string
    {
        return $this->isAddressShaped() ? $this->submitted : self::NOT_AN_EMAIL;
    }
}
