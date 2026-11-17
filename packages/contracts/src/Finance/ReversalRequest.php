<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * A request to reverse one posted entry (T-033 AC-49).
 *
 * Every value is `mixed` and unvalidated here, as in {@see PostingRequest}.
 */
final readonly class ReversalRequest
{
    private const KEYS = ['entry_id', 'entry_date', 'reason'];

    public function __construct(
        public mixed $entryId,
        public mixed $entryDate,
        public mixed $reason,
        public ?string $actorId,
    ) {}

    /**
     * Build from a decoded body, refusing every key the contract does not define.
     *
     * @param  array<array-key, mixed>  $body
     *
     * @throws PostingRefused unknown_field, naming every offending key
     */
    public static function fromArray(array $body, ?string $actorId): self
    {
        $unknown = [];

        foreach (array_keys($body) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                $unknown[] = (string) $key;
            }
        }

        if ($unknown !== []) {
            throw PostingRefused::unknownFields($unknown, 'The reversal request carries keys the contract does not define.');
        }

        return new self($body['entry_id'] ?? null, $body['entry_date'] ?? null, $body['reason'] ?? null, $actorId);
    }
}
