<?php

declare(strict_types=1);

namespace Flexipos\Hr\Profiles;

/**
 * The closed set of employment types (T-038 "Closed value sets", AC-41),
 * from `docs/database.md`. Stored as `VARCHAR(30)`, never a native enum.
 */
enum EmploymentType: string
{
    case Permanent = 'permanent';
    case Contract = 'contract';
    case Probation = 'probation';
    case PartTime = 'part_time';
    case Casual = 'casual';
    case Intern = 'intern';
}
