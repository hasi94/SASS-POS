<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * The closed refusal vocabulary of {@see PostingService} (T-033 AC-16a).
 *
 * The period gate's and the chart's own codes ({@see PeriodRefusal},
 * {@see AccountRefusal}) are not repeated here: {@see PostingRefused} carries
 * them unchanged.
 */
enum PostingRefusal: string
{
    case UnknownField = 'unknown_field';
    case InvalidSourceType = 'invalid_source_type';
    case InvalidSourceId = 'invalid_source_id';
    case InvalidSourcePurpose = 'invalid_source_purpose';
    case InvalidDate = 'invalid_date';
    case InvalidDescription = 'invalid_description';
    case InvalidBranch = 'invalid_branch';
    case InvalidLines = 'invalid_lines';
    case TooFewLines = 'too_few_lines';
    case TooManyLines = 'too_many_lines';
    case InvalidAccountId = 'invalid_account_id';
    case InvalidMemo = 'invalid_memo';
    case InvalidDimension = 'invalid_dimension';
    case InvalidAmount = 'invalid_amount';
    case LineBothSides = 'line_both_sides';
    case LineZero = 'line_zero';
    case EntryUnbalanced = 'entry_unbalanced';
    case TotalOutOfRange = 'total_out_of_range';
    case InvalidActor = 'invalid_actor';
    case IdempotencyConflict = 'idempotency_conflict';
    case UnknownReference = 'unknown_reference';
    case EntryNotFound = 'entry_not_found';
    case NotReversible = 'not_reversible';
    case AlreadyReversed = 'already_reversed';
    case ReversalBeforeOriginal = 'reversal_before_original';
    case ReasonRequired = 'reason_required';
    case InvalidReason = 'invalid_reason';
}
