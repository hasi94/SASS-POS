<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\AddCustomerNote;

final readonly class AddCustomerNoteResult
{
    public function __construct(
        public string $noteId,
    ) {}
}
