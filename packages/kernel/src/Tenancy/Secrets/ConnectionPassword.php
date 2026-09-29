<?php

namespace Flexipos\Kernel\Tenancy\Secrets;

use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use Stringable;

/**
 * The resolved tenant credential as it sits inside the `tenant` connection
 * stanza, and nowhere else.
 *
 * **This is deliberately not a Secret.** Secret::__toString() returns
 * `[redacted]`, which is what makes an accidental interpolation of a Secret
 * harmless; this class's __toString() returns the *real* value, because that is
 * its entire purpose - the connector reads `$config['password']` and hands it
 * straight to `new PDO(...)`, which coerces a Stringable in weak typing mode.
 * Collapsing the two classes would make every accidental interpolation of a
 * Secret anywhere start leaking. Flexipos\Kernel\Tenancy\Tenancy is the only
 * code that turns one into the other, so Secret::reveal() remains the single
 * audited call site.
 *
 * Why an object rather than a plain string: PHP records the arguments of
 * `call_user_func($this->extensions[$name], $config, $name)` inside
 * DatabaseManager::makeConnection(), and that is an internal variadic call, so
 * #[\SensitiveParameter] on our own closure parameter cannot mark it. Symfony's
 * FlattenException::flattenArgs() copies string argument values verbatim into a
 * flattened trace and its fallback HTML renderer prints them - but it records an
 * *object* as ['object', get_debug_type($value)] and discards the value
 * entirely. Measured on PHP 8.2.12 / framework v12.69.2: the object is better at
 * every surface and worse at none.
 *
 * **This object has no properties.** The value lives in CredentialValues, a
 * WeakMap keyed by this instance, so `(array) $password` is empty and a dumper,
 * a reflection walk or var_export() has nothing to render but the virtual key
 * __debugInfo() contributes. That is what closed review R-1: a private property
 * was rendered by the real dump() helper right next to the redacted key, because
 * Symfony's cloner *merges* __debugInfo() rather than replacing the cast array.
 *
 * A VarDumper caster is registered as well, in TenancyServiceProvider, and it is
 * deliberately not the load-bearing part: the framework constructs and captures
 * its cloner during its own provider registration, before any application
 * provider runs, so casters added later never reach it. Having nothing to leak
 * is the only mitigation that does not depend on registration order.
 *
 * There is still no __set_state(), so a var_export of a stanza holding one
 * cannot be loaded back. `config:cache` is safe by construction anyway: no
 * tenant is ever entered in a config-caching process, so it only ever sees the
 * placeholder stanza.
 *
 * Which surfaces do and do not show the value is settled by measurement in
 * QaCredentialSurfaceSweepTest, not by reasoning here about which magic method
 * each one honours. An earlier version of this docblock reasoned it out, got
 * dd()/dump() wrong, and that was QA BUG-1.
 *
 * The coercion dependency fails **closed**. If the framework ever adds
 * declare(strict_types=1) or types Connector::createPdoConnection()'s $password,
 * `new PDO(...)` raises a TypeError and no tenant connection opens at all -
 * loudly, on the first `composer update`, rather than quietly leaking again.
 * TenancyFrameworkContractTest names that contract so the failure is diagnosable
 * in one read.
 */
final readonly class ConnectionPassword implements JsonSerializable, Stringable
{
    public function __construct(#[SensitiveParameter] string $value)
    {
        CredentialValues::put($this, $value);
    }

    /**
     * The real value. This is the coercion the database connector relies on.
     */
    public function __toString(): string
    {
        return CredentialValues::get($this);
    }

    /**
     * A clone would be an object with no registered value, and __clone() only
     * ever sees the copy, so the value cannot be carried across. Refusing is
     * fail-closed: the alternative is an object that coerces to '' and opens a
     * connection with an empty password.
     */
    public function __clone(): void
    {
        throw new LogicException(
            'A Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword cannot be cloned: the tenant connection stanza '.
            'is built once, inside a tenant scope, and never copied.'
        );
    }

    /**
     * What var_dump(), print_r() and the dumpers show.
     *
     * This is now the *only* thing they can show, because the object carries no
     * state of its own. Note that a cloner merges this alongside an object's
     * real properties rather than replacing them, so it would not be sufficient
     * on its own - it is sufficient here only because there are no properties
     * (QA BUG-1, review R-1).
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => TenancyException::REDACTED];
    }

    public function jsonSerialize(): string
    {
        return TenancyException::REDACTED;
    }

    /**
     * A tenant connection stanza must never be serialised: that is storage, and
     * a credential that is stored is a credential that leaked. Both hooks throw,
     * so removing either one later still leaves the other refusing.
     *
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new LogicException(
            'A Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword cannot be serialised: the tenant connection '.
            'stanza exists only inside a tenant scope and is never carried across a boundary.'
        );
    }

    /**
     * @return list<string>
     */
    public function __sleep(): array
    {
        throw new LogicException(
            'A Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword cannot be serialised: the tenant connection '.
            'stanza exists only inside a tenant scope and is never carried across a boundary.'
        );
    }

    /**
     * @param  array<string, string>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException(
            'A Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword cannot be unserialised: it is never written '.
            'anywhere to be read back.'
        );
    }
}
