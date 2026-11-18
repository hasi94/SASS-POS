<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Hr;

use InvalidArgumentException;

/**
 * The live employees who report to one employee, bounded (T-038 AC-82).
 *
 * `truncated` is true when more than {@see self::MAXIMUM} reports exist and
 * `ids` then holds exactly the first {@see self::MAXIMUM} in id order.
 *
 * The constructor accepts anything array-shaped and **checks** it: a
 * contract value crosses a package boundary, and a native `list<string>`
 * hint alone is not enforced at run time.
 */
final readonly class DirectReports
{
    public const MAXIMUM = 1000;

    /** @var list<string> */
    public array $ids;

    /**
     * @param  array<mixed>  $ids  a list of non-empty strings, at most {@see self::MAXIMUM}
     */
    public function __construct(
        array $ids,
        public bool $truncated,
    ) {
        if (! array_is_list($ids)) {
            throw new InvalidArgumentException('[ids] must be a list.');
        }

        if (count($ids) > self::MAXIMUM) {
            throw new InvalidArgumentException('[ids] holds more than '.self::MAXIMUM.' entries.');
        }

        $clean = [];

        foreach ($ids as $id) {
            if (! is_string($id) || $id === '') {
                throw new InvalidArgumentException('[ids] must hold non-empty strings.');
            }

            $clean[] = $id;
        }

        if ($truncated && count($clean) !== self::MAXIMUM) {
            throw new InvalidArgumentException('[truncated] is true but the list is not at the maximum.');
        }

        $this->ids = $clean;
    }
}
