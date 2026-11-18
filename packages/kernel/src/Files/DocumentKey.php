<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Files;

use Illuminate\Support\Str;

/**
 * A stored document's key: `<tenant>/<area>/<ULID>` (T-038 plan Ruling 6,
 * AC-70, D-10).
 *
 * The tenant segment is the shared storage root's only tenant boundary, so it
 * is part of the key and checked on every read by the caller. The ULID is
 * minted here, never taken from a request. **Parsing is the validation**: a
 * key that does not parse never reaches a disk. The accepted alphabet is
 * deliberately smaller than "no `..`": every segment is letters, digits, `_`
 * and `-`, so a key contains no `.`, no `\`, no `:`, no NUL, no drive letter
 * and no empty segment, and cannot name a parent directory or an absolute
 * path.
 */
final readonly class DocumentKey
{
    private const TENANT = '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';

    private const AREA_SEGMENT = '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';

    private const ULID = '/\A[0-9A-HJKMNP-TV-Z]{26}\z/';

    private function __construct(
        private string $tenant,
        private string $area,
        private string $ulid,
    ) {}

    /**
     * Mint a fresh key for a tenant and an area such as `hr/employee-documents`.
     *
     * @throws DocumentStoreFailed when the tenant segment or the area is not acceptable
     */
    public static function mint(string $tenantId, string $area): string
    {
        // The tenant must be one segment on its own: joined into a path first,
        // `a/b` would parse as tenant `a` with a longer area.
        if (preg_match(self::TENANT, $tenantId) !== 1) {
            throw self::refused();
        }

        return self::parse($tenantId.'/'.$area.'/'.strtoupper((string) Str::ulid()))->toString();
    }

    /**
     * @throws DocumentStoreFailed
     */
    public static function parse(string $key): self
    {
        if (strlen($key) > 255) {
            throw self::refused();
        }

        $segments = explode('/', $key);

        // A tenant, at least one area segment, and the ULID.
        if (count($segments) < 3) {
            throw self::refused();
        }

        $tenant = $segments[0];
        $ulid = $segments[count($segments) - 1];
        $area = array_slice($segments, 1, -1);

        if (preg_match(self::TENANT, $tenant) !== 1 || preg_match(self::ULID, $ulid) !== 1) {
            throw self::refused();
        }

        foreach ($area as $segment) {
            if (preg_match(self::AREA_SEGMENT, $segment) !== 1) {
                throw self::refused();
            }
        }

        return new self($tenant, implode('/', $area), $ulid);
    }

    public function tenantSegment(): string
    {
        return $this->tenant;
    }

    /**
     * The area between the tenant and the ULID, for example `hr/employee-documents`.
     */
    public function area(): string
    {
        return $this->area;
    }

    public function ulid(): string
    {
        return $this->ulid;
    }

    public function toString(): string
    {
        return $this->tenant.'/'.$this->area.'/'.$this->ulid;
    }

    private static function refused(): DocumentStoreFailed
    {
        return DocumentStoreFailed::because('The document key is not acceptable.');
    }
}
