<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The connection this package's handlers write through.
 *
 * **It resolves the stanza by its configured name, never by the literal
 * `tenant`.** `config('tenancy.connection')` is the one authority for that
 * name and a handler writing the literal would be a second one - the shape
 * FP-184 collects. Two dozen slices are about to be written; a copy of this
 * four-line method in each of them is two dozen places to edit the day the
 * stanza is renamed, and twenty-three places for a typo to hide.
 *
 * **It builds nothing.** `Tenancy::enter()` configures the stanza and is the
 * only thing that may (T-009 AC-52); this class asks the manager for a
 * connection that is already configured. A handler reached before a tenant
 * is entered therefore fails on the placeholder stanza rather than quietly
 * writing somewhere.
 *
 * **Every consumer in this package reads the name through here, and the two
 * that once did not were folded onto it.** `SyncPermissionRegistryHandler`
 * (step 6) and `DatabasePermissionResolver` (step 9) each carried a private
 * copy of the four lines below. They could not disagree about the *name* -
 * all three read the same key - but one fact with three homes is the shape
 * FP-181 and FP-184 collect, and T-006's closing finding measured it: every
 * one of its six defects happened in a fact that had more than one home.
 * `docs/patterns.md` §3's "shared code earns its place by being used twice"
 * is satisfied the moment a second caller exists, so the duplicates had no
 * shelter in the three-use rule either.
 *
 * The fold is **observable, not cosmetic**, and the thing that makes it so
 * is `TenantConnectionAuthorityTest`: it moves `tenancy.connection` to a
 * second migrated database and asserts the sync and the resolver both follow
 * it, with a third configured-but-unnamed database as the negative control.
 * Reintroducing a private copy anywhere turns that test red.
 */
final class TenantConnection
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    public function get(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->database->connection((string) $this->config->get('tenancy.connection', 'tenant'));

        return $connection;
    }
}
