<?php

declare(strict_types=1);

namespace Flexipos\Finance\Exceptions;

/**
 * The closed refusal vocabulary {@see FinanceRefused} carries (T-032 spec
 * AC-1 to AC-66, D-25, D-30).
 *
 * Every case is a stable snake_case identifier a client may switch on, so a
 * rename is a coordinated edit. Cases are grouped by the part of the spec
 * that names them. Two published contract vocabularies, `AccountRefusal` and
 * `PeriodRefusal`, are separate: they are what another module sees from
 * `ChartOfAccounts` and `PeriodGate`.
 */
enum FinanceRefusal: string
{
    // Input.

    /** A field is missing, of the wrong type, out of range, over-long, or not accepted here (AC-4, AC-5, AC-8, AC-9). */
    case Validation = 'validation';

    /** The account code is not a well-formed code (AC-2). */
    case InvalidCode = 'invalid_code';

    /** A date is not a real strict `Y-m-d` (AC-32, AC-48). */
    case InvalidDate = 'invalid_date';

    /** An update body named a field that has its own operation (AC-8, D-24). */
    case NotUpdatableHere = 'not_updatable_here';

    /** The caller lacks the permission key (AC-64). */
    case NotPermitted = 'not_permitted';

    /** The page size is outside 1 to 200 (AC-61). */
    case PageSizeInvalid = 'page_size_invalid';

    /** The pagination cursor is malformed (AC-61). */
    case CursorInvalid = 'cursor_invalid';

    // Account fields.

    /** The supplied normal balance differs from the one the type derives (AC-6). */
    case NormalBalanceMismatch = 'normal_balance_mismatch';

    /** The subtype does not belong to the type (AC-7). */
    case SubtypeMismatch = 'subtype_mismatch';

    /** Another account, live or soft-deleted, already has the code (AC-3). */
    case CodeInUse = 'code_in_use';

    /** Another year or period already has the name (AC-34). */
    case NameInUse = 'name_in_use';

    // Hierarchy (AC-10 to AC-15), in the order AC-10 checks them.
    case ParentNotFound = 'parent_not_found';
    case ParentNotLive = 'parent_not_live';
    case ParentInactive = 'parent_inactive';
    case ParentNotHeader = 'parent_not_header';
    case ParentTypeMismatch = 'parent_type_mismatch';
    case DepthExceeded = 'depth_exceeded';
    case Cycle = 'cycle';
    case HasChildren = 'has_children';
    case HasPostings = 'has_postings';
    case TypeChangeRefused = 'type_change_refused';

    // Control and system accounts (AC-16 to AC-19, AC-22).
    case ControlTypeMismatch = 'control_type_mismatch';
    case ControlAccountExists = 'control_account_exists';
    case ControlTypeLocked = 'control_type_locked';
    case CodeLocked = 'code_locked';
    case SystemAccountImmutable = 'system_account_immutable';

    // Lifecycle (AC-20, AC-23, AC-24).
    case AccountNotFound = 'account_not_found';
    case AccountNotLive = 'account_not_live';
    case InUse = 'in_use';
    case AlreadyInactive = 'already_inactive';
    case AlreadyActive = 'already_active';

    // Fiscal years and periods (AC-33, AC-37, AC-38 to AC-46).
    case FiscalYearNotContiguous = 'fiscal_year_not_contiguous';
    case FiscalYearNotFound = 'fiscal_year_not_found';
    case FiscalYearNotOpen = 'fiscal_year_not_open';
    case FiscalYearClosed = 'fiscal_year_closed';
    case FiscalYearInUse = 'fiscal_year_in_use';
    case LaterYearNotOpen = 'later_year_not_open';
    case NotLatestYear = 'not_latest_year';
    case PeriodNotFound = 'period_not_found';
    case InvalidTransition = 'invalid_transition';
    case StatusChanged = 'status_changed';
    case PeriodOrderViolation = 'period_order_violation';
    case CloseBlocked = 'close_blocked';
    case ReasonRequired = 'reason_required';
    case PeriodsNotClosed = 'periods_not_closed';
}
