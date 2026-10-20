<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Http\Request;

/**
 * The three things every authentication slice records about the caller, read
 * from the request in one place.
 *
 * `login_history` takes an `ip`, a `user_agent` and a `device_id`, and three
 * controllers would otherwise each decide where those come from. They come
 * from here, so a change of header is one edit and the three endpoints cannot
 * disagree about what a device id is.
 *
 * **The two lengths are the columns', and they are enforced here because this
 * is where a request-supplied string becomes a column value.**
 * `login_history.user_agent` is `VARCHAR(255)` and `device_id` is
 * `VARCHAR(100)`; MySQL in strict mode refuses an over-long value with an
 * error, so without a cut here a caller could fail a login by sending a long
 * header - turning an audit field into a denial of service. The record is
 * worth more truncated than absent, and the slice is not the place for it: a
 * non-HTTP caller passes what it means to store.
 *
 * **It is also where the four endpoints read a submitted value from.**
 * {@see self::body()} exists because `$request->input()` merges the query
 * string, and a credential read through it would be written to the web
 * server's access log; the reasoning is on that method.
 *
 * Nothing here is ever read for tenancy (AC-45). The tenant a request runs
 * under comes from its token's `tenant` claim and from nowhere else; these
 * three are evidence about the caller, and `RequireTenantToken` does not
 * consult them.
 */
final class AuthRequestContext
{
    /**
     * The header a client names its installation in. There is no device
     * registry yet - `devices` and `terminals` are tenant tables a later task
     * owns - so this is recorded as submitted and trusted for nothing.
     */
    public const DEVICE_HEADER = 'X-Device-Id';

    /**
     * `login_history.user_agent`.
     */
    public const USER_AGENT_LIMIT = 255;

    /**
     * `login_history.device_id`.
     */
    public const DEVICE_ID_LIMIT = 100;

    /**
     * One value of the request **body**, and never of the query string.
     *
     * **`$request->input()` is not a body read and must not be used for a
     * credential.** Its implementation is `data_get($this->getInputSource()
     * ->all() + $this->query->all(), ...)`, so the URL's parameters are
     * merged in as a fallback: a `POST /api/v1/auth/login?password=hunter2`
     * with an empty body authenticates. The spec's NFR is absolute -
     * **tokens never travel in URLs** - and a query string is the one place
     * a credential cannot be recalled from: nginx writes the request URI to
     * `access.log` by default (`docs/deployment-vps.md`), nothing here
     * prunes that file, and browser history, proxy logs and `Referer` carry
     * it too. R-1.
     *
     * `$request->post()` is not sufficient either, because a JSON request
     * never populates the `request` bag. So the source is chosen the way the
     * framework chooses it for a body - the decoded JSON for a JSON request,
     * the POST bag otherwise - with the query bag simply absent. The
     * framework's own `getInputSource()` would do this, but it is
     * `protected` and also falls back to the query bag for a GET; these four
     * endpoints are POST-only and a credential read must never see the URL
     * whatever the verb.
     *
     * An absent key is `null`. Every caller already refuses null as a
     * missing credential, so there is no new branch anywhere.
     */
    public static function body(Request $request, string $key): mixed
    {
        $source = $request->isJson() ? $request->json() : $request->request;

        return data_get($source->all(), $key);
    }

    private function __construct(
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly ?string $deviceId,
    ) {}

    public static function from(Request $request): self
    {
        return new self(
            $request->ip(),
            self::cut($request->userAgent(), self::USER_AGENT_LIMIT),
            self::cut($request->headers->get(self::DEVICE_HEADER), self::DEVICE_ID_LIMIT),
        );
    }

    /**
     * A trimmed value no longer than the column, or null when there is
     * nothing to record. An empty header is an absent one.
     */
    private static function cut(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $limit);
    }
}
