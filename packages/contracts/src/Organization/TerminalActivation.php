<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Organization;

/**
 * Activate a terminal inside the tenant database auth has already entered
 * (T-014 AC-29).
 *
 * **The caller's callable runs inside the tenant transaction, after every
 * tenant write, and its return value is returned.** Auth has to mint a master
 * refresh row for the new terminal, and two orderings are wrong: minting
 * first would leave a refresh row behind a refused activation (AC-30 forbids
 * it), and minting after the commit would leave an active terminal with no
 * token if the mint failed. Running it last, inside the transaction, means a
 * throwing mint rolls the tenant side back and nothing is left in either
 * database.
 *
 * Implemented by `packages/organization`. Auth reaches it through this
 * contract only.
 */
interface TerminalActivation
{
    /**
     * @param  callable(ActivatedTerminal): mixed  $issueInsideTransaction
     *
     * @throws TerminalActivationRefused before any write
     */
    public function activate(ActivationAttempt $attempt, callable $issueInsideTransaction): mixed;
}
