<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\PublishCatalogue;

/**
 * What one `schema:publish` run did, as data rather than as console output.
 *
 * `inserted` is empty on a second run, which is the observable AC-31's
 * idempotence half is asserted on: "the second run changes nothing" is a claim
 * about writes, and a row count alone cannot tell a no-op from a delete
 * followed by an identical insert.
 */
final readonly class PublishCatalogueResult
{
    /**
     * @param  list<string>  $inserted  the migrations this run wrote, in version order
     * @param  list<array{version: int, migration: string, phase: string, checksum: string, is_destructive: bool, requires_offline: bool}>  $rows  the whole catalogue, as it now stands in the database
     */
    public function __construct(
        public array $inserted,
        public array $rows,
    ) {}
}
