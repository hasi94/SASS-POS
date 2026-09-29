<?php

namespace Flexipos\Kernel\Tenancy\Secrets;

use Flexipos\Kernel\Tenancy\Exceptions\CredentialNotAvailable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Env;

/**
 * `env://TENANT_000123_APP_PASSWORD` - the process environment.
 *
 * docs/database.md section 4.4 marks this scheme "local XAMPP development only,
 * refused when APP_ENV=production", and that refusal is enforced here rather
 * than left to a deployment checklist: a process outside local development that
 * finds an env:// reference in the mapping has a misconfigured secret store, and
 * connecting anyway would hide it.
 *
 * The document says "refused when APP_ENV=production"; this class refuses
 * *unless* the environment is a known local one. Matching production by name
 * protects only deployments that happen to use that name, and the failure mode
 * of getting it wrong is handing out a development-only credential path in a
 * real deployment (review R-6). An allow-list fails closed on names nobody
 * anticipated.
 *
 * Env::getRepository()->get() is used instead of env(): it returns the raw
 * string, while env() coerces "true", "false", "null" and "" into other types -
 * which would quietly mangle a password that happens to look like one of them.
 */
final class EnvSecretResolver implements SecretResolver
{
    public const SCHEME = 'env';

    /**
     * The only environments in which a credential may come from the process
     * environment. Anything not named here - including an environment nobody
     * anticipated - is refused.
     *
     * @var list<string>
     */
    public const LOCAL_ENVIRONMENTS = ['local', 'development', 'testing'];

    public function __construct(private readonly Application $app) {}

    public function resolve(string $reference): Secret
    {
        // Allow-list, not a deny-list. Matching "production" by name only
        // protects environments that happen to be called that: a deployment
        // using "prod", "live", "staging" or any house name would have been
        // handed the development-only scheme (review R-6). Deny unless the
        // environment is a known local one.
        if (! $this->app->environment(self::LOCAL_ENVIRONMENTS)) {
            throw CredentialNotAvailable::forReference(
                $reference,
                sprintf(
                    'the env:// credential scheme is for local development only and is refused in the [%s] '.
                    'environment; it is permitted only in [%s]. Use a real secret store reference (file://, '.
                    'vault://, awssm://, azkv://) instead.',
                    (string) $this->app->environment(),
                    implode(', ', self::LOCAL_ENVIRONMENTS)
                )
            );
        }

        $variable = SchemeSecretResolver::targetOf($reference);

        if ($variable === '') {
            throw CredentialNotAvailable::forReference(
                $reference,
                'the reference names no environment variable.'
            );
        }

        $value = Env::getRepository()->get($variable);

        if (! is_string($value) || $value === '') {
            throw CredentialNotAvailable::forReference(
                $reference,
                sprintf('the environment variable [%s] is not set, or is empty.', $variable)
            );
        }

        return new Secret($value);
    }
}
