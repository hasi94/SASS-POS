<?php

declare(strict_types=1);

namespace Flexipos\Hr\Support;

/**
 * The widths every HR string input is validated against (T-038 plan step 3).
 *
 * **One number, two homes, and a test between them.** Each constant is also
 * the length a migration declares for the column it validates: `HrWidthsTest`
 * reads the four HR migrations and asserts every `string()` and `char()`
 * width equals its constant, so a validator and its column cannot drift.
 * Lengths are counted in characters, never bytes.
 */
final class Limits
{
    /** `departments.code` and `designations.code` (AC-17). */
    public const CODE = 20;

    /** `departments.name` and `designations.name` (AC-16, AC-32). */
    public const NAME = 150;

    /** Every enumerated `VARCHAR(30)`: employment type, gender, marital status, document type. */
    public const ENUM = 30;

    /** `employee_profiles.exit_reason`. */
    public const EXIT_REASON = 500;

    /** `employee_profiles.nationality`. */
    public const NATIONALITY = 60;

    /** `employee_profiles.nic` and `passport_no`. */
    public const IDENTITY_NUMBER = 30;

    /** `employee_profiles.personal_email` and `work_email`. */
    public const EMAIL = 191;

    /** `employee_profiles.phone`. */
    public const PHONE = 30;

    /**
     * `employee_profiles.notes`: a TEXT column, so the bound is the
     * application's own, in characters (the plan is silent; chosen well below
     * the column's byte capacity so any four-byte text fits).
     */
    public const PROFILE_NOTES = 10000;

    /** Each string of `employee_profiles.emergency_contact` (AC-47): inside JSON, so no column width. */
    public const EMERGENCY_CONTACT_PART = 100;

    /** Each string of `employee_profiles.address` (AC-47): inside JSON, so no column width. */
    public const ADDRESS_PART = 150;

    /** `employee_documents.title`. */
    public const DOCUMENT_TITLE = 150;

    /** `employee_documents.file_path`: a storage key. */
    public const FILE_PATH = 255;

    /** `employee_documents.original_name`. */
    public const ORIGINAL_NAME = 255;

    /** `employee_documents.mime`. */
    public const MIME = 100;

    /** `employee_documents.checksum`: SHA-256 hex. */
    public const CHECKSUM = 64;

    /** `employee_documents.notes`. */
    public const DOCUMENT_NOTES = 1000;
}
