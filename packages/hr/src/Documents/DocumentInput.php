<?php

declare(strict_types=1);

namespace Flexipos\Hr\Documents;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Exceptions\HrRefused;
use Flexipos\Hr\Support\Fields;
use Flexipos\Hr\Support\Input;
use Flexipos\Hr\Support\Limits;

/**
 * Pure parsing of the document metadata requests (T-038 plan step 8; AC-66,
 * AC-74, AC-75, D-33).
 *
 * The file itself never passes through here: it arrives as a
 * {@see StagedFile} and is judged by {@see FileRules}. This class settles the
 * metadata: the closed type set, the title, the two dates and their order,
 * the confidentiality flag and its defaults, the note. Failures are collected
 * across fields into one `validation` refusal; no message echoes a value.
 */
final class DocumentInput
{
    /** @var list<string> the metadata a caller may supply */
    public const WRITABLE = ['type', 'title', 'issue_date', 'expiry_date', 'is_confidential', 'notes'];

    /**
     * What an update may not change (AC-74): refused `not_updatable_here`.
     * On an **add** the same names are simply unknown fields, refused
     * `validation` (AC-66: a client-supplied storage field is refused whole).
     *
     * @var list<string>
     */
    public const NOT_UPDATABLE = [
        'file', 'file_path', 'checksum', 'size_bytes', 'mime', 'employee_id', 'original_name',
        'id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
    ];

    /**
     * An add body, every writable field normalised. `is_confidential`
     * defaults to **true** when omitted (AC-75).
     *
     * @param  array<array-key, mixed>  $body
     * @return array{type: string, title: string, issue_date: ?string, expiry_date: ?string, is_confidential: bool, notes: ?string}
     *
     * @throws HrRefused validation or date_order
     */
    public static function add(array $body): array
    {
        Fields::only($body, self::WRITABLE);

        $missing = array_values(array_filter(['type', 'title'], static fn (string $field): bool => ! array_key_exists($field, $body)));
        $parsed = self::parse($body, $missing);

        $result = [
            'type' => (string) $parsed['type'],
            'title' => (string) $parsed['title'],
            'issue_date' => self::nullableString($parsed['issue_date'] ?? null),
            'expiry_date' => self::nullableString($parsed['expiry_date'] ?? null),
            'is_confidential' => ($parsed['is_confidential'] ?? true) === true,
            'notes' => self::nullableString($parsed['notes'] ?? null),
        ];

        self::assertRules($result);

        return $result;
    }

    /**
     * An update body against the stored document.
     *
     * Returns the normalised `patch` and the `merged` result the rules ran
     * on: a change of `type` to medical must not leave the document
     * non-confidential, and an `issue_date` change must not strand a stored
     * `expiry_date`.
     *
     * @param  array<array-key, mixed>  $body
     * @param  array<string, mixed>  $current  the stored type, dates and is_confidential
     * @return array{patch: array<string, mixed>, merged: array<string, mixed>}
     *
     * @throws HrRefused not_updatable_here, validation or date_order
     */
    public static function update(array $body, array $current): array
    {
        Fields::only($body, self::WRITABLE, self::NOT_UPDATABLE);

        $patch = self::parse($body, []);
        $merged = array_merge($current, $patch);

        self::assertRules($merged);

        return ['patch' => $patch, 'merged' => $merged];
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @param  list<string>  $missing
     * @return array<string, mixed>
     */
    private static function parse(array $body, array $missing): array
    {
        $parsed = [];
        $failed = $missing;

        foreach ($body as $field => $value) {
            $field = (string) $field;

            try {
                $parsed[$field] = match ($field) {
                    'type' => Input::enum($value, DocumentType::class, $field)->value,
                    'title' => Input::text($value, Limits::DOCUMENT_TITLE, true, $field),
                    'issue_date', 'expiry_date' => $value === null ? null : Input::date($value, $field),
                    'is_confidential' => Input::flag($value, $field),
                    'notes' => Input::text($value, Limits::DOCUMENT_NOTES, false, $field),
                    default => throw HrRefused::validation($field, "The {$field} is not accepted here."),
                };
            } catch (HrRefused $refused) {
                $failed[] = $field;
            }
        }

        if ($failed !== []) {
            $failed = array_values(array_unique($failed));

            throw HrRefused::because(
                HrRefusal::Validation,
                'These fields are invalid: '.implode(', ', $failed).'.',
                $failed[0],
                ['fields' => $failed],
            );
        }

        return $parsed;
    }

    /**
     * The two cross-field rules, on a normalised (or merged) result.
     *
     * @param  array<string, mixed>  $values
     */
    private static function assertRules(array $values): void
    {
        // D-33: a medical document is always confidential.
        if (($values['type'] ?? null) === DocumentType::Medical->value && ($values['is_confidential'] ?? true) === false) {
            throw HrRefused::validation('is_confidential', 'A medical document must be confidential.');
        }

        $issue = $values['issue_date'] ?? null;
        $expiry = $values['expiry_date'] ?? null;

        // Equal is accepted; a past expiry is accepted (already-expired paperwork is still paperwork).
        if (is_string($issue) && is_string($expiry) && $expiry < $issue) {
            throw HrRefused::because(
                HrRefusal::DateOrder,
                'The expiry_date must be on or after the issue_date.',
                'expiry_date',
                ['earlier' => 'issue_date', 'later' => 'expiry_date'],
            );
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
