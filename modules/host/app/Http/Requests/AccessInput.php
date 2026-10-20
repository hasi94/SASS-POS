<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\UpdateEmployee\UpdateEmployeeHandler;
use Illuminate\Http\Request;

/**
 * Reading a submitted value for an access endpoint, in one place.
 *
 * **Every read is a body read** (AC-48 sink 6, T-009 R-1). The source is
 * {@see AuthRequestContext::body()}, which is the host's single
 * implementation of "the decoded JSON for a JSON request, the POST bag
 * otherwise, and the query bag never" - `$request->input()` merges the
 * query string, so a PIN posted to `?pin=1234` with an empty body would
 * authenticate and would be written to nginx's access log on the way in.
 * The method is not re-implemented here; this class is the access task's
 * vocabulary on top of it.
 *
 * **A wrong type is `validation`, never a `TypeError`.** The handlers take
 * `string` and `bool` parameters, so a submitted array or integer reaching
 * one is a 500. These helpers turn every such submission into the refusal
 * AC-25 and AC-26 ask for, which is also the one a client can act on.
 */
final class AccessInput
{
    /**
     * One body value, untouched and of any type.
     *
     * Used where the slice itself judges the type - a PIN, which AC-47
     * requires to be refused as `validation` when it is not a string.
     */
    public static function raw(Request $request, string $key): mixed
    {
        return AuthRequestContext::body($request, $key);
    }

    /**
     * A required string field.
     *
     * @throws AccessRefused validation when absent or not a string
     */
    public static function text(Request $request, string $key): string
    {
        $value = self::raw($request, $key);

        if (! is_string($value)) {
            throw AccessRefused::validation("[{$key}] must be submitted as a string.");
        }

        return $value;
    }

    /**
     * An optional string field: null when absent, refused when present and
     * not a string.
     *
     * @throws AccessRefused validation when present and not a string
     */
    public static function optionalText(Request $request, string $key): ?string
    {
        $value = self::raw($request, $key);

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw AccessRefused::validation("[{$key}] must be submitted as a string.");
        }

        return $value;
    }

    /**
     * An optional boolean field.
     *
     * Deliberately strict: `"true"` is a string and is refused. A flag that
     * accepted several spellings would make `is_default=false` and
     * `is_default="false"` mean different things on different clients, and
     * AC-35's one-default rule is not a place for that.
     *
     * @throws AccessRefused validation when present and not a boolean
     */
    public static function flag(Request $request, string $key, bool $default = false): bool
    {
        $value = self::raw($request, $key);

        if ($value === null) {
            return $default;
        }

        if (! is_bool($value)) {
            throw AccessRefused::validation("[{$key}] must be submitted as true or false.");
        }

        return $value;
    }

    /**
     * A required list of strings.
     *
     * An empty list is valid and means "no permissions" (AC-20); it is a
     * different submission from an absent field, which is refused.
     *
     * @return list<string>
     *
     * @throws AccessRefused validation when absent, not a list, or holding a non-string
     */
    public static function textList(Request $request, string $key): array
    {
        $value = self::raw($request, $key);

        if (! is_array($value)) {
            throw AccessRefused::validation("[{$key}] must be submitted as a list of strings.");
        }

        $list = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw AccessRefused::validation("[{$key}] must hold strings only.");
            }

            $list[] = $item;
        }

        return $list;
    }

    /**
     * Every submitted body field, for the slices that judge the field set
     * themselves.
     *
     * {@see UpdateEmployeeHandler}
     * refuses an update naming `code`, `user_id`, `pin_hash`,
     * `perm_version` or `status` (AC-26, D-32), and that refusal is only
     * possible if the handler sees what was submitted. A controller that
     * picked out the three allowed fields would turn the refusal into the
     * silent ignore AC-26 exists to forbid.
     *
     * @return array<string, mixed>
     */
    public static function fields(Request $request): array
    {
        $source = $request->isJson() ? $request->json() : $request->request;

        $all = $source->all();

        $fields = [];

        foreach ($all as $key => $value) {
            $fields[(string) $key] = $value;
        }

        return $fields;
    }

    /**
     * The requested page size, from the query string.
     *
     * The one value here that is read from the URL, and it is read there on
     * purpose: a page size is not a credential, it belongs in a `GET`'s
     * query string, and AC-29 clamps rather than refuses an over-large one.
     * Anything that is not a positive integer is ignored rather than
     * refused, so the caller gets the default page instead of an error.
     */
    public static function pageSize(Request $request): ?int
    {
        $value = $request->query('page_size');

        if (! is_string($value) || preg_match('/\A[0-9]+\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
