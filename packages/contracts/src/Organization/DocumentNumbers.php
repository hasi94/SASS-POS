<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Organization;

/**
 * The one allocator of gapless document numbers (T-030 AC-40, D-31, F-6;
 * `docs/database.md` section 6.1).
 *
 * **Callable inside the caller's own transaction, and meant to be.** The number
 * is taken from the `number_sequences` row under a row lock and the increment
 * commits or rolls back with the document that took it, so a rolled-back sale
 * leaves no gap and two sales cannot take one number. That lock is the hottest
 * in a shop (every completion, refund and shift open queues on the company-wide
 * row until commit), so a caller takes it **last** among the locks it needs.
 *
 * Which sequence answers: the branch's own row for the document type when one
 * exists, otherwise the company-wide row, which is created with a default prefix
 * and padding the first time it is needed. `sale`, `refund` and `cash_shift`
 * are independent sequences with different prefixes, because `sales.number` is
 * unique across both sale types.
 *
 * Implemented in `packages/organization`, the owner of `number_sequences`.
 */
interface DocumentNumbers
{
    /**
     * The next number for this document type, and the increment that consumed it.
     *
     * @param  string  $documentType  the sequence, for example `sale`, `refund` or `cash_shift`
     * @param  string|null  $branchId  the branch whose own sequence is preferred, or null for the company-wide one
     *
     * @throws DocumentNumbersRefused
     */
    public function next(string $documentType, ?string $branchId): string;
}
