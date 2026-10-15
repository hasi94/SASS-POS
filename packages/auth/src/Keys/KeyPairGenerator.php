<?php

declare(strict_types=1);

namespace Flexipos\Auth\Keys;

use RuntimeException;

/**
 * The one place an ES256 key pair is generated (Ruling 1).
 *
 * **Why this class exists at all, and it is a measured local hazard rather
 * than a design preference.** `openssl_pkey_new()` fails on the development
 * machine this project is built on with no arguments: the OpenSSL error queue
 * reports `BIO routines::no such file`, because no `openssl.cnf` is on the
 * default search path for the CLI SAPI. D-23 forbids a committed key pair and
 * has every test generate an ephemeral one at run time, so without a resolver
 * that call fails there and every token test with it.
 *
 * **The resolution order, and the three states it distinguishes:**
 *
 * 1. the `OPENSSL_CNF` environment variable, when it names a file. When it is
 *    set and names *no* file the generator **refuses** rather than falling
 *    through - an unset variable means "look elsewhere", a variable pointing
 *    at a missing file is a misconfiguration, and silently signing under a
 *    configuration the operator did not choose is the worse of the two
 *    failures;
 * 2. otherwise the first `openssl.cnf` found beside the PHP binary's own
 *    directory tree. Measured on the development machine: `php/extras/ssl/`
 *    holds one and it works, so the XAMPP Apache path never has to be
 *    hard-coded anywhere;
 * 3. otherwise **no `config` key at all**, which is what a Linux CI runner
 *    needs and the only correct answer where OpenSSL finds its own
 *    configuration. That branch is the one that fails on the development
 *    machine, and it exists anyway.
 *
 * **The curve is `prime256v1` and nothing else may be substituted.**
 * firebase/php-jwt 7.x derives a key-size floor from the algorithm name: for
 * ES256 that is 256 bits and a P-256 key reports exactly 256, so it passes on
 * the boundary with no margin. Any smaller curve raises
 * `DomainException("Provided key is too short")`, which reads as a library
 * fault rather than as a curve choice - KeyPairGeneratorTest pins both halves
 * so the next reader meets the boundary as a fact instead of as a surprise.
 *
 * **One pair per label for the life of the process.** Key generation is the
 * one genuinely expensive thing in a token test, and a suite that generates a
 * pair per case pays for it per case. The label exists so a test that needs a
 * second, genuinely different pair - AC-29's "signed by a key not in
 * `signing_keys`" - can have one without disabling the cache.
 *
 * No key material is committed, written to disk or logged: a pair exists as
 * two strings in this process and nowhere else (D-23).
 */
final class KeyPairGenerator
{
    /**
     * The only curve this project signs with.
     */
    public const CURVE = 'prime256v1';

    /**
     * The JOSE algorithm that curve implements.
     */
    public const ALGORITHM = 'ES256';

    /**
     * What a P-256 key reports, and exactly what the library's ES256 floor
     * demands.
     */
    public const KEY_BITS = 256;

    /**
     * The variable an operator or a runner uses to name the configuration.
     */
    public const CONFIG_VARIABLE = 'OPENSSL_CNF';

    /**
     * @var array<string, array{private: string, public: string}>
     */
    private static array $pairs = [];

    /**
     * A PEM pair, generated once per label per process.
     *
     * @return array{private: string, public: string}
     */
    public static function generate(string $label = 'default'): array
    {
        if (array_key_exists($label, self::$pairs)) {
            return self::$pairs[$label];
        }

        $config = self::configPath();

        $options = ['curve_name' => self::CURVE, 'private_key_type' => OPENSSL_KEYTYPE_EC];

        if ($config !== null) {
            $options['config'] = $config;
        }

        $key = openssl_pkey_new($options);

        if ($key === false) {
            throw new RuntimeException(sprintf(
                'could not generate a %s key pair (openssl configuration: %s): %s. '.
                'Point %s at an openssl.cnf if this host keeps one outside the default search path.',
                self::CURVE,
                $config ?? 'none',
                (string) openssl_error_string(),
                self::CONFIG_VARIABLE
            ));
        }

        $exported = openssl_pkey_export($key, $private, null, $config === null ? [] : ['config' => $config]);

        $details = openssl_pkey_get_details($key);

        if ($exported === false || ! is_array($details) || ! is_string($details['key'] ?? null)) {
            throw new RuntimeException('generated a key pair that could not be exported to PEM');
        }

        return self::$pairs[$label] = ['private' => (string) $private, 'public' => $details['key']];
    }

    /**
     * The configuration this machine resolves to, or null for "pass none".
     */
    public static function configPath(): ?string
    {
        $fromEnvironment = getenv(self::CONFIG_VARIABLE);

        return self::resolveConfigPath(
            is_string($fromEnvironment) ? $fromEnvironment : null,
            self::binaryTreeCandidates()
        );
    }

    /**
     * The order, as a pure function, so every branch is decidable without
     * touching the filesystem of the machine the test happens to run on.
     *
     * @param  list<string>  $candidates
     */
    public static function resolveConfigPath(?string $fromEnvironment, array $candidates): ?string
    {
        if ($fromEnvironment !== null && $fromEnvironment !== '') {
            if (! is_file($fromEnvironment)) {
                throw new RuntimeException(sprintf(
                    '%s names %s, which is not a file. Unset it to search beside the PHP binary, '.
                    'or point it at an openssl.cnf that exists.',
                    self::CONFIG_VARIABLE,
                    $fromEnvironment
                ));
            }

            return $fromEnvironment;
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Where an `openssl.cnf` sits relative to the running PHP binary, in the
     * order the second branch tries them.
     *
     * Path construction only - nothing here touches the disk, so the list is
     * the same on every machine and the decision stays in resolveConfigPath().
     *
     * @return list<string>
     */
    public static function binaryTreeCandidates(): array
    {
        $binary = dirname(PHP_BINARY);
        $separator = DIRECTORY_SEPARATOR;

        return [
            $binary.$separator.'openssl.cnf',
            $binary.$separator.'extras'.$separator.'ssl'.$separator.'openssl.cnf',
            $binary.$separator.'ssl'.$separator.'openssl.cnf',
            dirname($binary).$separator.'apache'.$separator.'conf'.$separator.'openssl.cnf',
        ];
    }
}
