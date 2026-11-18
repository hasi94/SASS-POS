<?php

declare(strict_types=1);

namespace Flexipos\Hr\Exceptions;

/**
 * The closed refusal vocabulary {@see HrRefused} carries (T-038 spec, the
 * list at the head of the acceptance criteria; D-20).
 *
 * Exactly twenty-seven cases. Every case is a stable snake_case identifier a
 * client may switch on, so a rename is a coordinated edit and `HrRefusalTest`
 * pins the set in both directions. A criterion that proves a refusal asserts
 * the cause, never just "refused".
 */
enum HrRefusal: string
{
    // Input and access.

    /** A field is missing, malformed, over-long, or not accepted here (names the fields). */
    case Validation = 'validation';

    /** The caller lacks the operation's permission key. */
    case NotPermitted = 'not_permitted';

    /** An update named a field that has its own operation, or an identity field access owns (D-21). */
    case NotUpdatableHere = 'not_updatable_here';

    /** The page size is outside 1 to 200 (D-25). */
    case PageSizeInvalid = 'page_size_invalid';

    /** The pagination cursor is malformed, foreign or half a key (D-25). */
    case CursorInvalid = 'cursor_invalid';

    // Lookups and references.

    /** The row, or the employee it belongs to, does not exist or is soft-deleted. */
    case NotFound = 'not_found';

    /** The employee has no profile. */
    case ProfileNotFound = 'profile_not_found';

    /** The employee already has a profile. */
    case ProfileExists = 'profile_exists';

    /** A referenced row does not exist or is soft-deleted. */
    case ReferenceNotFound = 'reference_not_found';

    /** A referenced row exists but is deactivated, so nothing new may point at it. */
    case ReferenceInactive = 'reference_inactive';

    // Uniqueness.

    /** A department or designation code is already used, even by a soft-deleted row. */
    case CodeInUse = 'code_in_use';

    /** A live sibling already has the name. */
    case NameInUse = 'name_in_use';

    // Hierarchy.

    /** A department or a reporting line would point at itself. */
    case SelfReference = 'self_reference';

    /** The change would close a loop. */
    case Cycle = 'cycle';

    /** The result would be deeper than the limit. */
    case DepthExceeded = 'depth_exceeded';

    /** A department still has live children. */
    case HasChildren = 'has_children';

    // Lifecycle and dependents.

    /** Something live still references the row. */
    case InUse = 'in_use';

    /** An employee is still a department head or a manager (access's own list carries the same word, D-43). */
    case HasDependents = 'has_dependents';

    /** Deactivating a row that is already inactive. */
    case AlreadyInactive = 'already_inactive';

    /** Reactivating a row that is already active. */
    case AlreadyActive = 'already_active';

    /** A date falls before the date it must follow. */
    case DateOrder = 'date_order';

    // Documents.

    /** The staged file is empty. */
    case FileEmpty = 'file_empty';

    /** The staged file is over the configured maximum. */
    case FileTooLarge = 'file_too_large';

    /** The detected content type, or the name's extension, is not allowed. */
    case FileTypeNotAllowed = 'file_type_not_allowed';

    /** The employee already holds the maximum number of live documents. */
    case DocumentLimit = 'document_limit';

    /** The document store failed. */
    case StorageUnavailable = 'storage_unavailable';

    /** The row exists but its bytes are missing, foreign or fail their checksum. */
    case DocumentCorrupt = 'document_corrupt';
}
