<?php

declare(strict_types=1);

namespace Flexipos\Finance\Journal;

use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Contracts\Finance\ReversalRequest;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\Limits;

/**
 * Every pure check of a reversal request, in request order (T-033 Ruling 8
 * step 2; AC-52, AC-53, AC-56).
 *
 * A malformed entry id is `entry_not_found`, not a shape error: an id that
 * cannot name an entry names no entry. The date is required and never
 * defaulted. The reason is required (missing or blank is `reason_required`)
 * and bounded (over 500 characters, a control character or a non-string is
 * `invalid_reason`).
 */
final class ReversalInput
{
    private function __construct() {}

    /**
     * @throws PostingRefused
     */
    public static function parse(ReversalRequest $request): ParsedReversal
    {
        $entryId = PostingInput::id($request->entryId, 'entry_id', PostingRefusal::EntryNotFound);
        $date = PostingInput::date($request->entryDate, 'entry_date');

        return new ParsedReversal($entryId, $date, self::reason($request->reason), $request->actorId);
    }

    private static function reason(mixed $value): string
    {
        if ($value === null || PostingInput::isBlank($value)) {
            throw PostingRefused::because(PostingRefusal::ReasonRequired, 'A reversal needs a reason.', 'reason');
        }

        try {
            return (string) Input::text($value, Limits::REASON, true, 'reason');
        } catch (FinanceRefused $refused) {
            throw PostingRefused::because(PostingRefusal::InvalidReason, $refused->getMessage(), 'reason');
        }
    }
}
