<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\Logout;

use Flexipos\Auth\Support\GenericRefusal;

/**
 * The one answer logout ever gives (AC-49).
 *
 * **There is one instance's worth of information here and it is a
 * constant.** A valid token, an unknown one, an expired one and one that was
 * revoked an hour ago all produce this, byte for byte, because any
 * difference would say whether a value was ever a real token - which is a
 * disclosure, and a free one for anybody holding a list of guesses.
 *
 * It is a class rather than a bare array for the reason
 * {@see GenericRefusal} is one: "identical across
 * causes" is a property of having a single producer, not of several
 * producers agreeing. There is no argument through which a cause could
 * reach the body.
 */
final class LogoutResult
{
    /**
     * The one body.
     *
     * @return array{status: string}
     */
    public static function body(): array
    {
        return ['status' => 'signed_out'];
    }

    /**
     * @return array{status: string}
     */
    public function toResponse(): array
    {
        return self::body();
    }
}
