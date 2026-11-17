<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * The one place a ledger row is written (T-033 AC-1).
 *
 * **Transaction.** Both operations must run inside the caller's open database
 * transaction and throw `LogicException` otherwise, before reading or writing
 * anything (AC-2). The service never opens, retries or commits a transaction,
 * so a document and its entry commit or roll back together (AC-3).
 *
 * **Lock order.** Call it after every other lock the caller takes, including
 * its own document number. Several calls in one transaction must be in
 * ascending `entry_date` order (the {@see PeriodGate} rule).
 *
 * **Permissions.** The service checks none; the human-facing handlers do (D-10).
 *
 * @see PostingRefused every refusal, whole-request, nothing written
 */
interface PostingService
{
    /**
     * Post one balanced entry, idempotently by source document (AC-34).
     *
     * @throws PostingRefused
     */
    public function post(PostingRequest $request): PostedEntry;

    /**
     * Reverse one posted entry with a new entry (AC-49).
     *
     * @throws PostingRefused
     */
    public function reverse(ReversalRequest $request): PostedEntry;
}
