<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api\Features\ListErrorCodes;

use Flexipos\Kernel\Api\ErrorCatalogue;

/**
 * Reads {@see ErrorCatalogue::all()} into the sorted rows `api:error-codes`
 * prints, and the same rows the OpenAPI document embeds (AC-14, AC-34).
 */
final class ListErrorCodesHandler
{
    public function __construct(private readonly ErrorCatalogue $catalogue) {}

    /**
     * @return list<array{code: string, module: string, status: int, message: string}>
     */
    public function handle(ListErrorCodes $query): array
    {
        $rows = [];

        foreach ($this->catalogue->all() as $entry) {
            $rows[] = [
                'code' => $entry->code,
                'module' => $this->catalogue->moduleOf($entry->code) ?? '',
                'status' => $entry->status,
                'message' => $entry->message,
            ];
        }

        return $rows;
    }
}
