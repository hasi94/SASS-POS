<?php

namespace Flexipos\Kernel\Tenancy\Secrets;

use LogicException;
use SensitiveParameter;
use WeakMap;

/**
 * Holds credential plaintext *outside* the object graph of the objects that
 * represent it.
 *
 * @internal Only Secret and ConnectionPassword may use this.
 *
 * Why this exists rather than a private property or a captured closure: every
 * generic inspector in PHP walks an object's own state, and the two obvious
 * storage choices are both reachable. `(array) $object` exposes a private
 * property, which is what Symfony's Caster::castObject() renders - and it
 * *merges* __debugInfo() alongside that rather than replacing it, so the
 * redacted key appears next to the real one. Holding the value in a closure is
 * no better: the cloner renders a closure's captured `use` bindings. Review R-1
 * measured both leaking through the real dump() helper.
 *
 * A WeakMap keyed by the holder leaves the holder with **no properties at all**,
 * so `(array) $object` is empty, a reflection walk finds nothing, `var_export()`
 * yields `::__set_state(array())`, and a dumper has nothing to show but the
 * virtual key __debugInfo() contributes. It is the one storage choice that does
 * not depend on any inspector choosing to honour a hook.
 *
 * Weak, so an entry disappears when its holder is garbage collected; the
 * plaintext never outlives the object that represents it.
 */
final class CredentialValues
{
    /**
     * @var WeakMap<object, string>|null
     */
    private static ?WeakMap $values = null;

    public static function put(object $holder, #[SensitiveParameter] string $value): void
    {
        self::$values ??= new WeakMap;
        self::$values[$holder] = $value;
    }

    /**
     * @throws LogicException when the holder has no value, which means it was
     *                        produced by something other than its constructor -
     *                        a clone, or an unserialize that got past the
     *                        refusals. Failing loudly beats returning '' and
     *                        opening a connection with an empty password.
     */
    public static function get(object $holder): string
    {
        $value = self::$values?->offsetExists($holder) === true
            ? self::$values[$holder]
            : null;

        if (! is_string($value)) {
            throw new LogicException(sprintf(
                'No credential value is registered for this %s. It was not created through its constructor, '.
                'which is the only thing allowed to hold one.',
                $holder::class
            ));
        }

        return $value;
    }
}
