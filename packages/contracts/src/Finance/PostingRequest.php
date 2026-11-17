<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * A request to post one balanced entry (T-033 Ruling 2).
 *
 * Every value is `mixed` and unvalidated here: the service parses the whole
 * request in one deterministic pass (D-21), so a malformed value is a
 * {@see PostingRefused} and never an exception from a constructor.
 */
final readonly class PostingRequest
{
    private const ENTRY_KEYS = [
        'source_type', 'source_id', 'source_purpose', 'entry_date', 'description', 'branch_id', 'lines',
    ];

    private const LINE_KEYS = ['account_id', 'debit', 'credit', 'memo'];

    public function __construct(
        public mixed $sourceType,
        public mixed $sourceId,
        public mixed $sourcePurpose,
        public mixed $entryDate,
        public mixed $description,
        public mixed $branchId,
        public mixed $lines,
        public ?string $actorId,
    ) {}

    /**
     * Build from a decoded body, refusing every key the contract does not define.
     *
     * Entry level and line level are checked together and **every** offending
     * key is named in one refusal (AC-16): an entry key by its name, a line key
     * by its path (`lines.1.tax_id`). `$exclude` removes keys from the accepted
     * entry-level set, so a handler that fixes the type itself can make a
     * supplied `source_type` an unknown field (AC-71). A line's dimension keys
     * sit flat on the line in the body and are gathered into
     * {@see PostingLine::$dimensions}. An omitted `debit` or `credit` becomes
     * `'0'` (AC-19); an explicit `null` is kept and refused later.
     *
     * @param  array<array-key, mixed>  $body
     * @param  list<string>  $exclude
     *
     * @throws PostingRefused unknown_field
     */
    public static function fromArray(array $body, ?string $actorId, array $exclude = []): self
    {
        $accepted = array_values(array_diff(self::ENTRY_KEYS, $exclude));
        $unknown = [];

        foreach (array_keys($body) as $key) {
            if (! in_array($key, $accepted, true)) {
                $unknown[] = (string) $key;
            }
        }

        $lines = $body['lines'] ?? null;

        if (is_array($lines) && array_is_list($lines)) {
            $built = [];

            foreach ($lines as $index => $raw) {
                $built[] = is_array($raw) ? self::line($raw, $index, $unknown) : $raw;
            }

            $lines = $built;
        }

        if ($unknown !== []) {
            throw PostingRefused::unknownFields($unknown, 'The request carries keys the contract does not define.');
        }

        return new self(
            $body['source_type'] ?? null,
            $body['source_id'] ?? null,
            $body['source_purpose'] ?? null,
            $body['entry_date'] ?? null,
            $body['description'] ?? null,
            $body['branch_id'] ?? null,
            $lines,
            $actorId,
        );
    }

    /**
     * The same request with its type replaced, for a handler that fixes it.
     */
    public function withSourceType(mixed $sourceType): self
    {
        return new self(
            $sourceType,
            $this->sourceId,
            $this->sourcePurpose,
            $this->entryDate,
            $this->description,
            $this->branchId,
            $this->lines,
            $this->actorId,
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @param  list<string>  $unknown  collected by reference
     */
    private static function line(array $raw, int $index, array &$unknown): PostingLine
    {
        $dimensions = [];

        foreach (JournalDimension::cases() as $dimension) {
            if (array_key_exists($dimension->value, $raw)) {
                $dimensions[$dimension->value] = $raw[$dimension->value];
            }
        }

        foreach (array_keys($raw) as $key) {
            if (! in_array($key, self::LINE_KEYS, true) && JournalDimension::tryFrom((string) $key) === null) {
                $unknown[] = 'lines.'.$index.'.'.$key;
            }
        }

        return new PostingLine(
            $raw['account_id'] ?? null,
            array_key_exists('debit', $raw) ? $raw['debit'] : '0',
            array_key_exists('credit', $raw) ? $raw['credit'] : '0',
            $raw['memo'] ?? null,
            $dimensions,
        );
    }
}
