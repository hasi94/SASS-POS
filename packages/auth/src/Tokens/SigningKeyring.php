<?php

declare(strict_types=1);

namespace Flexipos\Auth\Tokens;

use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Keys\KeyPairGenerator;
use Flexipos\Auth\Keys\PrivateKeyResolver;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * `signing_keys`, read: which key signs, and which key may verify.
 *
 * The rotation is three states and they are not symmetric: **`active` signs
 * and verifies, `retiring` verifies only, `retired` verifies never.** That is
 * the whole of the keyring's rule, and it lives here rather than in the two
 * handlers so that a key's status is consulted in one place.
 *
 * **There is no cache, and that is a deliberate deviation from the plan's
 * wording.** Plan section 4 asks for public keys cached per process, "keyed by
 * `kid` **and** status, so AC-29's retired-key case cannot be served from
 * cache". Implemented literally that cache cannot save anything: the status
 * has to be read from the master on every verification - the spec's
 * performance NFR says in terms that "a cache must not let a retired key
 * verify past its retirement" - and the row that carries the status carries
 * the public key with it, so by the time the cache key is known the cached
 * value has already been fetched. A cache that cannot avoid the read it exists
 * to avoid is not a cache; it is a second copy of a row with a staleness
 * window. The read is a primary-key equality on a table with one row per key,
 * and it is one query on the verify path, which is what the NFR budgets ("no
 * master query beyond what verification of the key needs").
 * VerifyAccessTokenTest pins the behaviour the cache was supposed to preserve:
 * a key moved to `retired` inside one process stops verifying immediately.
 *
 * **The algorithm is ES256 and is named by the code, never by the row.** The
 * `algorithm` column is a record of what a key *is*; if it disagrees with what
 * this platform signs with, that is a misconfiguration and signing is refused
 * (`NoActiveSigningKey::wrongAlgorithm()`). Taking the algorithm from data is
 * alg-confusion with extra steps.
 *
 * **The private key is never here.** The row holds `private_key_ref` and a
 * `PrivateKeyResolver` dereferences it at signing time (D-23). This class
 * never stores the material it is handed.
 */
final class SigningKeyring
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRING = 'retiring';

    public const STATUS_RETIRED = 'retired';

    /**
     * The statuses a key may verify under. `retired` is absent, which is the
     * rule rather than an oversight.
     */
    public const VERIFYING_STATUSES = [self::STATUS_ACTIVE, self::STATUS_RETIRING];

    /**
     * The one status that signs.
     */
    public const SIGNING_STATUS = self::STATUS_ACTIVE;

    /**
     * The algorithm this platform signs and verifies with, named here and in
     * KeyPairGenerator and nowhere else - not in configuration, where an
     * operator could widen it.
     */
    public const ALGORITHM = KeyPairGenerator::ALGORITHM;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly PrivateKeyResolver $privateKeys,
    ) {}

    /**
     * The key that signs, with its material resolved.
     *
     * Ordered so the answer is deterministic when more than one key is active
     * during a rotation: the oldest active key keeps signing until it is moved
     * to `retiring`, which is what makes a rotation a deliberate act rather
     * than a race.
     *
     * @return array{kid: string, algorithm: string, private_key: string}
     */
    public function signingKey(): array
    {
        $row = $this->table()
            ->where('status', self::SIGNING_STATUS)
            ->orderBy('created_at')
            ->orderBy('kid')
            ->first(['kid', 'algorithm', 'private_key_ref']);

        if ($row === null) {
            throw NoActiveSigningKey::forSigning();
        }

        /** @var array<string, mixed> $row */
        $row = (array) $row;

        $kid = (string) $row['kid'];
        $algorithm = (string) $row['algorithm'];

        if ($algorithm !== self::ALGORITHM) {
            throw NoActiveSigningKey::wrongAlgorithm($kid, $algorithm, self::ALGORITHM);
        }

        return [
            'kid' => $kid,
            'algorithm' => self::ALGORITHM,
            'private_key' => $this->privateKeys->resolve((string) $row['private_key_ref']),
        ];
    }

    /**
     * One key's row, or null when the `kid` names none.
     *
     * Public because the verifier distinguishes "no such key" from "a key that
     * no longer verifies" when it records why it refused; the gate itself is
     * verificationKey() below and is not restated anywhere.
     *
     * @return array{kid: string, status: string, algorithm: string, public_key: string}|null
     */
    public function find(string $kid): ?array
    {
        if ($kid === '') {
            return null;
        }

        $row = $this->table()->where('kid', $kid)->first(['kid', 'status', 'algorithm', 'public_key']);

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = (array) $row;

        return [
            'kid' => (string) $row['kid'],
            'status' => (string) $row['status'],
            'algorithm' => (string) $row['algorithm'],
            'public_key' => (string) $row['public_key'],
        ];
    }

    /**
     * The public key a `kid` may be verified with, or null when it may not be
     * - because no such key exists, or because its status is not one that
     * verifies.
     */
    public function verificationKey(string $kid): ?string
    {
        $row = $this->find($kid);

        if ($row === null || ! in_array($row['status'], self::VERIFYING_STATUSES, true)) {
            return null;
        }

        return $row['public_key'];
    }

    private function table(): Builder
    {
        return $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table('signing_keys');
    }
}
