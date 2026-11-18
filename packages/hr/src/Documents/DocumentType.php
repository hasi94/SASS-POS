<?php

declare(strict_types=1);

namespace Flexipos\Hr\Documents;

/**
 * The closed set of document types (T-038 "Closed value sets", AC-66), from
 * `docs/database.md`. Stored as `VARCHAR(30)`, never a native enum.
 */
enum DocumentType: string
{
    case Contract = 'contract';
    case NicCopy = 'nic_copy';
    case Passport = 'passport';
    case Cv = 'cv';
    case Certificate = 'certificate';
    case Appraisal = 'appraisal';
    case Warning = 'warning';
    case Medical = 'medical';
    case Other = 'other';
}
