<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

/**
 * A second declaring provider, which has to be a second **class**.
 *
 * Measured 2026-10-20: `Application::markAsRegistered()` stores providers in
 * an array keyed by class name, so registering a second instance of
 * {@see FixturePermissionProvider} replaces the first rather than joining
 * it - with `force: true` as well as without. A case about two providers
 * disagreeing over one key is therefore unreachable with one class, and the
 * first draft of it passed for the wrong reason: the registry saw a single
 * provider and had nothing to disagree with.
 *
 * It adds no behaviour. Its name is its whole contribution.
 */
final class SecondFixturePermissionProvider extends FixturePermissionProvider {}
