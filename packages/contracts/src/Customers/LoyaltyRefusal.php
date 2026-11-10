<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Customers;

/**
 * The closed refusal vocabulary of `LoyaltyLedger` (T-028 plan Ruling 2).
 *
 * One case per reason the loyalty writer or `LoyaltyCalculator` can refuse.
 * Every case is reachable from the published contract alone; nothing here
 * names a table or a column.
 */
enum LoyaltyRefusal: string
{
    /** The named customer does not exist. */
    case UnknownCustomer = 'unknown_customer';

    /** The customer is soft-deleted or merged into another customer (D-30). */
    case CustomerNotLive = 'customer_not_live';

    /** The named program does not exist. */
    case UnknownProgram = 'unknown_program';

    /** `earn` or `redeem` against a program whose `is_active` is false (D-23, F-6). */
    case ProgramInactive = 'program_inactive';

    /** `points` is not an int or a `^-?\d+(\.\d{1,4})?$` string (Ruling 3.1). */
    case InvalidPoints = 'invalid_points';

    /** `points` is zero. */
    case ZeroPoints = 'zero_points';

    /** The entry type's hard sign (D-26, F-9) was violated. */
    case WrongSign = 'wrong_sign';

    /** A `redeem` would drive the balance below zero (D-13). */
    case InsufficientPoints = 'insufficient_points';

    /** An operand or the resulting balance does not fit `DECIMAL(18,4)` (Ruling 3.3). */
    case OutOfRange = 'out_of_range';

    /** `value` is not an int or a plain decimal string. */
    case InvalidValue = 'invalid_value';

    /** The named employee does not exist. */
    case UnknownEmployee = 'unknown_employee';

    /** The replay key matches a stored row with a different payload (F-15). */
    case ReplayConflict = 'replay_conflict';
}
