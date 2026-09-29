<?php

namespace Flexipos\Kernel\Tenancy\Secrets;

use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use Stringable;

/**
 * A resolved credential, wrapped so that every way PHP has of looking inside an
 * object shows `[redacted]` and only one audited call - reveal() - hands the
 * value out.
 *
 * **This object has no properties.** The value lives in CredentialValues, a
 * WeakMap keyed by this instance, so `(array) $secret` is empty and there is
 * nothing for a dumper, a reflection walk or var_export() to find. An earlier
 * version held it in a captured closure and a redacting __debugInfo(), and both
 * of those leaked through the real dump() helper: Symfony's cloner renders a
 * closure's `use` bindings, and it *merges* __debugInfo() alongside an object's
 * real state instead of replacing it (QA BUG-1, review R-1).
 *
 * A VarDumper caster is registered as well, in TenancyServiceProvider, but it is
 * no longer what carries the guarantee - it cannot be, because the framework
 * builds and captures its cloner before any application provider runs, so that
 * cloner never sees a caster added later. Having nothing to leak is the part
 * that does not depend on registration order.
 */
final readonly class Secret implements JsonSerializable, Stringable
{
    public function __construct(#[SensitiveParameter] string $value)
    {
        CredentialValues::put($this, $value);
    }

    /**
     * The single audited call site. Every caller is expected to hand the result
     * straight to the thing that needs it and keep no copy.
     */
    public function reveal(): string
    {
        return CredentialValues::get($this);
    }

    /**
     * A clone would be an object with no registered value, and the value cannot
     * be recovered here because __clone() only ever sees the copy. Refusing is
     * both honest and fail-closed; nothing has a reason to duplicate a
     * credential.
     */
    public function __clone(): void
    {
        throw new LogicException(
            'A Flexipos\Kernel\Tenancy\Secrets\Secret cannot be cloned: resolve the credential reference again '.
            'instead of copying the value.'
        );
    }

    public function __toString(): string
    {
        return TenancyException::REDACTED;
    }

    /**
     * What var_dump(), print_r() and the dumpers show.
     *
     * This is now the *only* thing they can show, because the object carries no
     * state of its own. Note that a cloner merges this alongside an object's
     * real properties rather than replacing them, so it would not be sufficient
     * on its own - it is sufficient here only because there are no properties.
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
     * A Secret must never reach a queue payload, a cache entry or a session:
     * those are all storage, and a credential that is stored is a credential
     * that leaked. serialize() fails loudly instead of writing `[redacted]`,
     * which would be a silently broken connection later on.
     *
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new LogicException(
            'A Flexipos\Kernel\Tenancy\Secrets\Secret cannot be serialised: resolve the credential reference '.
            'again on the other side instead of carrying the value across a boundary.'
        );
    }

    /**
     * @param  array<string, string>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException(
            'A Flexipos\Kernel\Tenancy\Secrets\Secret cannot be unserialised: it is never written anywhere to '.
            'be read back.'
        );
    }
}
