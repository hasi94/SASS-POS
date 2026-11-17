<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ReverseJournalEntry;

use Flexipos\Contracts\Finance\PostedEntry;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Contracts\Finance\PostingService;
use Flexipos\Contracts\Finance\ReversalRequest;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\TenantConnection;
use LogicException;

/**
 * Reverse a posted journal entry by hand (T-033 Ruling 12, AC-70, AC-73).
 *
 * Needs `finance.journal.reverse`, checked first, before any read of the
 * entry and before a transaction opens. A document module reversing its own
 * entry calls `PostingService::reverse` directly and needs no key (D-10, AC-58);
 * only this human path does. The refusal for a missing key is a
 * {@see FinanceRefused}; every other refusal is the service's
 * {@see PostingRefused}, unchanged. The actor is the authenticated employee.
 *
 * Opens its own transaction, with one retry for an engine deadlock
 * (`attempts: 2`).
 */
final class ReverseJournalEntryHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly Authorize $authorize,
        private readonly PostingService $service,
    ) {}

    /**
     * @throws FinanceRefused not_permitted
     * @throws PostingRefused
     */
    public function handle(ReverseJournalEntry $command): PostedEntry
    {
        $this->authorize->require($command->actorId, FinancePermissions::JOURNAL_REVERSE);

        $actorId = $command->actorId ?? throw new LogicException('Authorization passed for a null actor.');

        $request = new ReversalRequest($command->entryId, $command->entryDate, $command->reason, $actorId);

        return $this->tenant->get()->transaction(fn (): PostedEntry => $this->service->reverse($request), 2);
    }
}
