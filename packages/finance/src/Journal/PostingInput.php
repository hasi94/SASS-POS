<?php

declare(strict_types=1);

namespace Flexipos\Finance\Journal;

use Flexipos\Contracts\Finance\JournalDimension;
use Flexipos\Contracts\Finance\JournalSourceType;
use Flexipos\Contracts\Finance\PostingLine;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Contracts\Finance\PostingRequest;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\Limits;

/**
 * Every pure check of a posting request, in the one fixed order (T-033
 * Ruling 3 steps 1 to 4, D-21; AC-6 to AC-22).
 *
 * No database, no clock, no container. The order is: dimension keys of a
 * directly constructed request, then shape (the first failing field in
 * request order, lines ascending, dimensions in {@see JournalDimension}
 * order), then amounts, then balance. A shape error therefore wins over an
 * amount error, and an amount error over an imbalance.
 *
 * Finance's {@see Input} supplies the primitives and throws
 * {@see FinanceRefused}; this class maps each one to the contract's
 * {@see PostingRefused} code for that field. Regular expressions use `\A` and
 * `\z`, never `^` and `$`, because `$` matches before a trailing newline.
 */
final class PostingInput
{
    private const PURPOSE = '/\A[a-z][a-z0-9_]{0,29}\z/';

    private function __construct() {}

    /**
     * @throws PostingRefused
     */
    public static function parse(PostingRequest $request): ParsedPosting
    {
        self::rejectUnknownDimensions($request->lines);

        $type = self::sourceType($request->sourceType);
        $sourceId = self::sourceId($request->sourceId, $type);
        $purpose = self::sourcePurpose($request->sourcePurpose, $sourceId);
        $date = self::date($request->entryDate, 'entry_date');
        $description = self::optionalText($request->description, Limits::DESCRIPTION, 'description', PostingRefusal::InvalidDescription);
        $branchId = $request->branchId === null
            ? null
            : self::id($request->branchId, 'branch_id', PostingRefusal::InvalidBranch);

        $shaped = self::lines($request->lines);

        $lines = [];
        $debits = [];
        $credits = [];

        foreach ($shaped as $index => $line) {
            [$debit, $credit] = Amounts::line($line['source']->debit, $line['source']->credit, $index);

            $debits[] = $debit;
            $credits[] = $credit;
            $lines[] = [
                'account_id' => $line['account_id'],
                'debit' => $debit,
                'credit' => $credit,
                'memo' => $line['memo'],
                'dimensions' => $line['dimensions'],
            ];
        }

        [$totalDebit, $totalCredit] = Amounts::balance($debits, $credits);

        return new ParsedPosting(
            $type,
            $sourceId,
            $purpose,
            $date,
            $description,
            $branchId,
            $lines,
            $totalDebit,
            $totalCredit,
            $request->actorId,
        );
    }

    /**
     * A strict `Y-m-d` date (AC-9), refused as `invalid_date`.
     *
     * @throws PostingRefused
     */
    public static function date(mixed $value, string $field): string
    {
        try {
            return Input::date($value, $field);
        } catch (FinanceRefused $refused) {
            throw PostingRefused::because(PostingRefusal::InvalidDate, $refused->getMessage(), $field);
        }
    }

    /**
     * A 26-character ULID folded to upper case, refused under `$code`.
     *
     * @throws PostingRefused
     */
    public static function id(mixed $value, string $field, PostingRefusal $code): string
    {
        try {
            return Input::id($value, $field);
        } catch (FinanceRefused $refused) {
            throw PostingRefused::because($code, $refused->getMessage(), $field);
        }
    }

    /**
     * Null, or a trimmed text of 1 to `$max` characters (AC-10, AC-14).
     *
     * A blank or whitespace-only string is normalised to null **before**
     * {@see Input::text} sees it, because that primitive refuses blank (D-30).
     * A control character anywhere, including a trailing newline after text,
     * is still refused: only a value that is entirely whitespace is blank.
     *
     * @throws PostingRefused
     */
    public static function optionalText(mixed $value, int $max, string $field, PostingRefusal $code): ?string
    {
        if ($value === null || self::isBlank($value)) {
            return null;
        }

        try {
            return Input::text($value, $max, false, $field);
        } catch (FinanceRefused $refused) {
            throw PostingRefused::because($code, $refused->getMessage(), $field);
        }
    }

    /**
     * True for a valid-UTF-8 string made only of whitespace (possibly none).
     */
    public static function isBlank(mixed $value): bool
    {
        return is_string($value)
            && preg_match('//u', $value) === 1
            && preg_match('/\A[\p{Z}\s]*\z/u', $value) === 1;
    }

    /**
     * Dimension keys outside {@see JournalDimension} on a directly built
     * request are unknown fields, named by path (AC-15, AC-16).
     */
    private static function rejectUnknownDimensions(mixed $lines): void
    {
        if (! is_array($lines)) {
            return;
        }

        $unknown = [];

        foreach ($lines as $index => $line) {
            if (! $line instanceof PostingLine) {
                continue;
            }

            foreach (array_keys($line->dimensions) as $key) {
                if (JournalDimension::tryFrom((string) $key) === null) {
                    $unknown[] = 'lines.'.$index.'.'.$key;
                }
            }
        }

        if ($unknown !== []) {
            throw PostingRefused::unknownFields($unknown, 'A line carries dimensions the contract does not define.');
        }
    }

    /**
     * Exactly one value of the closed list, and never `reversal` (AC-6, AC-6a).
     */
    private static function sourceType(mixed $value): JournalSourceType
    {
        $type = is_string($value) ? JournalSourceType::tryFrom($value) : null;

        if ($type === null || $type === JournalSourceType::Reversal) {
            throw PostingRefused::because(
                PostingRefusal::InvalidSourceType,
                'The source_type is not one of the types a posting may carry.',
                'source_type',
            );
        }

        return $type;
    }

    /**
     * Required, except for `manual` and `opening` (AC-7).
     */
    private static function sourceId(mixed $value, JournalSourceType $type): ?string
    {
        if ($value === null && ($type === JournalSourceType::Manual || $type === JournalSourceType::Opening)) {
            return null;
        }

        return self::id($value, 'source_id', PostingRefusal::InvalidSourceId);
    }

    /**
     * Absent, or a lower-case word of 1 to 30 characters, and never without a
     * source id (AC-8). The pattern also keeps `-`, the stored sentinel of an
     * absent purpose, out of every real value.
     */
    private static function sourcePurpose(mixed $value, ?string $sourceId): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match(self::PURPOSE, $value) !== 1) {
            throw PostingRefused::because(
                PostingRefusal::InvalidSourcePurpose,
                'The source_purpose must be 1 to 30 lower-case letters, digits or underscores, starting with a letter.',
                'source_purpose',
            );
        }

        if ($sourceId === null) {
            throw PostingRefused::because(
                PostingRefusal::InvalidSourcePurpose,
                'A source_purpose needs a source_id.',
                'source_purpose',
            );
        }

        return $value;
    }

    /**
     * The shape of every line: count, account, memo, dimensions (AC-12 to AC-15).
     *
     * @return list<array{source: PostingLine, account_id: string, memo: ?string, dimensions: array<string, ?string>}>
     */
    private static function lines(mixed $lines): array
    {
        if (! is_array($lines) || ! array_is_list($lines)) {
            throw PostingRefused::because(PostingRefusal::InvalidLines, 'The lines must be a list.', 'lines');
        }

        if (count($lines) < Limits::MIN_LINES) {
            throw PostingRefused::because(
                PostingRefusal::TooFewLines,
                'An entry needs at least '.Limits::MIN_LINES.' lines.',
                'lines',
            );
        }

        if (count($lines) > Limits::MAX_LINES) {
            throw PostingRefused::because(
                PostingRefusal::TooManyLines,
                'An entry takes at most '.Limits::MAX_LINES.' lines.',
                'lines',
            );
        }

        $shaped = [];

        foreach ($lines as $index => $line) {
            if (! $line instanceof PostingLine) {
                throw PostingRefused::because(
                    PostingRefusal::InvalidLines,
                    "Line {$index} is not a posting line.",
                    'lines.'.$index,
                );
            }

            $shaped[] = [
                'source' => $line,
                'account_id' => self::id($line->accountId, 'lines.'.$index.'.account_id', PostingRefusal::InvalidAccountId),
                'memo' => self::optionalText($line->memo, Limits::MEMO, 'lines.'.$index.'.memo', PostingRefusal::InvalidMemo),
                'dimensions' => self::dimensions($line, $index),
            ];
        }

        return $shaped;
    }

    /**
     * All seven dimensions, in {@see JournalDimension} order, null when absent.
     *
     * @return array<string, ?string>
     */
    private static function dimensions(PostingLine $line, int $index): array
    {
        $dimensions = [];

        foreach (JournalDimension::cases() as $dimension) {
            $value = $line->dimensions[$dimension->value] ?? null;
            $field = 'lines.'.$index.'.'.$dimension->value;

            $dimensions[$dimension->value] = $value === null
                ? null
                : self::id($value, $field, PostingRefusal::InvalidDimension);
        }

        return $dimensions;
    }
}
