<?php

namespace Flexipos\Kernel\Tenancy;

use SensitiveParameter;

/**
 * Mints, verifies and revokes the authenticator the tenant connection stanza
 * carries (FP-161).
 *
 * The authenticator is a per-enter() random token bound, through a private
 * record on this object, to a digest of the whole stanza. It is NOT a secret:
 * it authenticates exactly one stanza - the legitimate one - so printing it,
 * logging it or dumping it gains a forger nothing. What cannot be reproduced is
 * a *record* for a token, and what cannot be kept valid is a record's digest
 * once any value in the stanza changes.
 *
 * Why the content and not the identity of something. MEASURED (plan R1, both
 * designs implemented side by side): a sentinel object held in a private WeakMap
 * **accepts** the AC-14 forgery, because the authority of an object is its
 * identity and an array copy carries the same instance. A random token stored
 * only in the stanza fails for the same reason. Anything F2 can read, F2 can
 * copy; binding to the content is what makes a changed database detectable.
 *
 * Why the whole stanza rather than a whitelist of redirecting keys. MEASURED:
 * ConfigurationUrlParser expands a `url` key into host and database *before* the
 * factory sees the stanza, and ConnectionFactory::make() branches to a
 * read/write connection on `isset($config['read'])` alone. A whitelist would
 * have to track every key the framework can be redirected by; the whole stanza
 * needs no list and goes stale for nobody.
 *
 * WHAT THIS DOES NOT DEFEND, written here rather than in a review comment
 * (AC-22, decision D-6):
 *
 * - **F3 is not defended, by decision.** Reflection into this live scoped
 *   object, or rebuilding the container, mints a valid authenticator. The
 *   forgers that *are* defended are F1 (knows the published source, including
 *   every constant) and F2 (runs inside the scope and can read the stanza), and
 *   the proof of both is executable: AC-13 and AC-14 in
 *   TenantStanzaForgeryTest, run against every construction route.
 * - **The mapping source is trusted.** TenantDatabaseRepository is built from
 *   config('tenancy.databases'), so code that can rewrite that key before the
 *   singleton resolves can map any tenant id to any database. That is not a
 *   forgery of the stanza and not in this task's threat model - FP-203 replaces
 *   the in-memory repository with the master-database reader - but it is the
 *   real floor under the isolation guarantee and belongs beside the mechanism
 *   rather than only in a plan.
 * - **A genuine stanza can be rebuilt under a different connection name**
 *   (DB::build(['name' => 'other', ...]) with the stanza as-is). It reaches only
 *   the forger's own tenant and is guarded per query like any other connection;
 *   what it escapes is the name-based teardown. FP-163 territory.
 * - **spl_object_id reuse** in the digest's object encoding would need the
 *   original object to be freed first; config() holds a strong reference to the
 *   stanza's ConnectionPassword for the life of the record, so an id cannot be
 *   recycled while the record it is part of is live.
 *
 * Scoped, beside TenantContext and Tenancy, so a queue worker's
 * forgetScopedInstances() between jobs drops every record with them.
 */
final class TenantConnectionAuthority
{
    /**
     * The key the stanza carries the token under.
     *
     * Private, and named by nothing else: the factory asks carries() and
     * verify() instead, so there is one class to change and no published name
     * that authenticates anything (AC-20).
     */
    private const AUTHENTICATOR = 'flexipos_tenant_authenticator';

    /**
     * Token => the tenant it was minted for, and the digest of the stanza it
     * was minted over.
     *
     * Two strings per record and never the stanza itself, so no credential
     * object is retained here and nothing outlives revoke() (AC-26, AC-30).
     *
     * @var array<string, array{tenant: string, digest: string}>
     */
    private array $records = [];

    /**
     * Return the stanza with a freshly minted authenticator, and record what it
     * vouches for.
     *
     * One record at a time, by construction: a new authorisation drops the
     * previous one, so a stanza kept from an earlier enter() is refused even
     * for the same tenant (AC-19).
     *
     * @param  array<string, mixed>  $stanza
     * @return array<string, mixed>
     */
    public function authorise(#[SensitiveParameter] array $stanza, string $tenantId): array
    {
        $this->records = [];

        $token = bin2hex(random_bytes(16));
        $stanza[self::AUTHENTICATOR] = $token;

        $this->records[$token] = [
            'tenant' => $tenantId,
            'digest' => self::digest($stanza),
        ];

        return $stanza;
    }

    /**
     * The tenant this stanza is vouched for, or null.
     *
     * Null covers every way of failing: no authenticator, an authenticator no
     * record knows, or a record whose digest no longer matches the stanza it
     * was just handed.
     *
     * @param  array<string, mixed>  $stanza
     */
    public function verify(#[SensitiveParameter] array $stanza): ?string
    {
        $token = $stanza[self::AUTHENTICATOR] ?? null;

        if (! is_string($token) || ! array_key_exists($token, $this->records)) {
            return null;
        }

        $record = $this->records[$token];

        return hash_equals($record['digest'], self::digest($stanza)) ? $record['tenant'] : null;
    }

    /**
     * Whether the stanza carries an authenticator at all, whatever its worth.
     *
     * This is the question the connection factory asks to decide that a stanza
     * is tenant-shaped even when it was handed no connection name - which is
     * what stops make($legitimateStanza, null) producing an unguarded tenant
     * connection.
     *
     * @param  array<string, mixed>  $stanza
     */
    public function carries(#[SensitiveParameter] array $stanza): bool
    {
        return array_key_exists(self::AUTHENTICATOR, $stanza);
    }

    /**
     * Drop every record, so nothing authorised in this scope verifies again.
     *
     * Called from Tenancy::resetTenantConnection() after the purge and the
     * placeholder write, so an open transaction is still rolled back against a
     * live connection first (AC-31).
     */
    public function revoke(): void
    {
        $this->records = [];
    }

    /**
     * A canonical digest of the whole stanza, less the authenticator itself and
     * the connection name.
     *
     * Those two keys and no others, and the exclusions are not a convenience:
     *
     * - the authenticator cannot be part of what it authenticates;
     * - `name` is the only framework-written key a digest ever sees, because it
     *   is the only one written *before* the factory is called:
     *   DatabaseManager::build() writes it into the stanza whether the caller
     *   supplied one or not - `$config['name'] ??= calculateDynamicConnectionName($config)`
     *   invents `dynamic_<md5 of the stanza>` when none is given
     *   (DatabaseManager.php:119 and :132, MEASURED on v12.69.2 and pinned by
     *   TenancyFrameworkContractTest) - and then hands that stanza on to
     *   connectUsing() and so to this factory. With `name` in the digest,
     *   DB::build() with the genuine stanza would be refused either way for
     *   carrying a key the framework added, which is AC-23's third column.
     *   `name` also decides nothing about which database is opened; it names
     *   the connection.
     *
     * `prefix` is not a third exclusion, and that is what the "before the
     * factory is called" qualifier buys: ConnectionFactory::parseConfig() adds
     * `prefix` as well as `name` (ConnectionFactory.php:60-63), but it runs
     * inside the *inner* factory's make(), after the choke point has already
     * called verify(), so neither addition is ever visible to a digest. The
     * rule behind the list, for any exclusion added later (plan R12): exclude a
     * key only when the framework writes it before the factory is called *and*
     * it cannot redirect which database is opened. `name` meets both, the
     * authenticator is ours and is excluded for the first reason above, and
     * nothing else in a stanza qualifies.
     *
     * What excluding `name` leaves is not an exception but a property (plan
     * R12): an authorised stanza authenticates one tenant's database, for one
     * scope, and not the connection name it is opened under. The same stanza
     * may therefore be opened again under any name - or under none, since
     * build() invents one - and that connection reaches only the forger's own
     * tenant, is guarded per query like every other tenant connection, and is
     * refused the moment the scope ends. What it escapes is the name-based
     * teardown. FP-163 territory.
     *
     * Everything else stays in, with no whitelist of "redirecting" keys to keep
     * up to date: `database`, `host`, `port`, `username`, an injected
     * `read`/`write` split, a `url` the parser would expand, and any key the
     * framework has not invented yet.
     *
     * @param  array<string, mixed>  $stanza
     */
    private static function digest(#[SensitiveParameter] array $stanza): string
    {
        unset($stanza[self::AUTHENTICATOR], $stanza['name']);

        return hash('sha256', self::canonical($stanza));
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function canonical(#[SensitiveParameter] array $value): string
    {
        ksort($value);

        $parts = [];

        foreach ($value as $key => $item) {
            $parts[] = strlen((string) $key).':'.$key.'=>'.self::encode($item);
        }

        return '{'.implode(',', $parts).'}';
    }

    /**
     * Each type tagged and each string length-prefixed, so no two different
     * stanzas can canonicalise to the same text by splicing values together.
     *
     * An object is encoded as its class and object id and is NEVER stringified:
     * that keeps the credential's plaintext out of the digest input entirely,
     * and it keeps ConnectionPassword's refusal to be serialised from ever
     * being triggered by a verification.
     */
    private static function encode(#[SensitiveParameter] mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'bool:'.($value ? '1' : '0'),
            is_int($value) => 'int:'.$value,
            is_float($value) => 'float:'.var_export($value, true),
            is_string($value) => 'string:'.strlen($value).':'.$value,
            is_array($value) => 'array:'.self::canonical($value),
            is_object($value) => 'object:'.$value::class.'#'.spl_object_id($value),
            default => 'other:'.get_debug_type($value),
        };
    }
}
