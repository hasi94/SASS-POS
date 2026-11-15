<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\RenameRole;

/**
 * One request to change a role's name and description (AC-21, AC-52).
 *
 * **The key is not a field.** A role's key is what every grant, every log
 * line and every later export names it by; changing it would silently
 * re-point anything holding the old one. Renaming is the display name's job,
 * which is why this command carries no `key` and the handler writes none.
 *
 * `$description` is written as given, so passing null clears it. That is the
 * difference between "leave it alone" and "empty it", and a command with no
 * way to express the second would make a description permanent once set.
 */
final class RenameRole
{
    public function __construct(
        public readonly string $roleId,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $actorId = null,
    ) {}
}
