<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\PostManualJournalEntry;

use Flexipos\Contracts\Finance\JournalSourceType;
use Flexipos\Contracts\Finance\PostedEntry;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Contracts\Finance\PostingRequest;
use Flexipos\Contracts\Finance\PostingService;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\TenantConnection;
use LogicException;

/**
 * Post a manual journal entry (T-033 Ruling 12, AC-71, AC-73).
 *
 * Needs `finance.journal.post_manual`, checked first, before the body is read
 * and before a transaction opens. The permission refusal is a
 * {@see FinanceRefused}; every other refusal is the service's own
 * {@see PostingRefused}, unchanged. The type is fixed to `manual` here, so a
 * body naming `source_type` is an unknown field; the actor is the
 * authenticated employee, so `posted_by` is that employee and `is_auto` is
 * false.
 *
 * Opens its own transaction (the service never does), with one retry for an
 * engine deadlock (`attempts: 2`).
 */
final class PostManualJournalEntryHandler
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
    public function handle(PostManualJournalEntry $command): PostedEntry
    {
        $this->authorize->require($command->actorId, FinancePermissions::JOURNAL_POST_MANUAL);

        $actorId = $command->actorId ?? throw new LogicException('Authorization passed for a null actor.');

        $request = PostingRequest::fromArray($command->body, $actorId, ['source_type'])
            ->withSourceType(JournalSourceType::Manual->value);

        return $this->tenant->get()->transaction(fn (): PostedEntry => $this->service->post($request), 2);
    }
}
