<?php

declare(strict_types=1);

namespace Flexipos\Finance\Accounts;

use Flexipos\Contracts\Finance\AccountSubtype;
use Flexipos\Contracts\Finance\AccountType;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Contracts\Finance\NormalBalance;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Support\Fields;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\Limits;

/**
 * Turns a submitted account body into an {@see AccountDraft}, refusing the
 * whole request on the first problem (T-032 plan Ruling 4; AC-1 to AC-9).
 *
 * Pure: it checks what a request can be checked for without the database,
 * which is every field on its own and the pairings that need only the
 * request (type with normal balance, type with subtype, control type with
 * type, subtype and postability). Whatever needs the stored account (the
 * parent, the usage ports, the single-holder check) is a handler's, under a
 * lock. The seed test (AC-29) passes every seeded row through `forCreate`,
 * so the data cannot violate a rule the API enforces.
 *
 * Key checks come first and are whole-request: every unknown key and every
 * key no one may send is named in one refusal (AC-8, D-10).
 */
final class AccountInput
{
    /** Keys that name no changeable field of an account but are legal to send as null (D-9). */
    private const CURRENCY = 'currency';

    /** Keys an update refuses by name because they have their own operation (AC-8, D-24). */
    private const OWN_OPERATION = ['parent_id', 'is_active'];

    /**
     * @param  array<array-key, mixed>  $body
     *
     * @throws FinanceRefused
     */
    public static function forCreate(array $body): AccountDraft
    {
        self::keys($body, ['code', 'name', 'description', 'type', 'subtype', 'normal_balance', 'parent_id',
            'is_postable', 'is_active', 'control_type', 'sort_order']);

        $code = Input::code($body['code'] ?? null);
        $name = (string) Input::text($body['name'] ?? null, Limits::ACCOUNT_NAME, true, 'name');
        $description = Input::text($body['description'] ?? null, Limits::DESCRIPTION, false, 'description');
        $type = Input::enum($body['type'] ?? null, AccountType::class, 'type');
        $subtype = self::subtypeOf($body);
        $supplied = self::normalBalanceOf($body);
        $parentId = ($body['parent_id'] ?? null) === null ? null : Input::id($body['parent_id'], 'parent_id');
        $isPostable = array_key_exists('is_postable', $body) ? Input::flag($body['is_postable'], 'is_postable') : true;
        $isActive = array_key_exists('is_active', $body) ? Input::flag($body['is_active'], 'is_active') : true;
        $control = array_key_exists('control_type', $body)
            ? Input::enum($body['control_type'], ControlType::class, 'control_type')
            : ControlType::None;
        $sortOrder = array_key_exists('sort_order', $body) ? Input::smallUnsigned($body['sort_order'], 'sort_order') : 0;

        $balance = AccountRules::derive($type, $supplied);
        AccountRules::subtype($type, $subtype);
        AccountRules::control($control, $type, $subtype, $isPostable);

        return new AccountDraft(
            ['code', 'name', 'description', 'type', 'subtype', 'normal_balance', 'parent_id', 'is_postable',
                'is_active', 'control_type', 'sort_order'],
            $code,
            $name,
            $description,
            $type,
            $subtype,
            $balance,
            $parentId,
            $isPostable,
            $isActive,
            $control,
            $sortOrder,
        );
    }

    /**
     * Only the fields the body names; `parent_id` and `is_active` are refused
     * here because re-parent, deactivate and reactivate own them (AC-8, D-24).
     *
     * @param  array<array-key, mixed>  $body
     *
     * @throws FinanceRefused
     */
    public static function forUpdate(array $body): AccountDraft
    {
        self::keys($body, ['code', 'name', 'description', 'type', 'subtype', 'normal_balance', 'is_postable',
            'control_type', 'sort_order', ...self::OWN_OPERATION]);

        $own = array_values(array_intersect(self::OWN_OPERATION, array_map('strval', array_keys($body))));

        if ($own !== []) {
            throw FinanceRefused::because(
                FinanceRefusal::NotUpdatableHere,
                implode(', ', $own).' cannot be changed here; use the dedicated operation.',
                $own[0],
                ['fields' => $own],
            );
        }

        $supplied = [];
        $values = [];

        if (array_key_exists('code', $body)) {
            $values['code'] = Input::code($body['code']);
            $supplied[] = 'code';
        }

        if (array_key_exists('name', $body)) {
            $values['name'] = (string) Input::text($body['name'], Limits::ACCOUNT_NAME, true, 'name');
            $supplied[] = 'name';
        }

        if (array_key_exists('description', $body)) {
            $values['description'] = Input::text($body['description'], Limits::DESCRIPTION, false, 'description');
            $supplied[] = 'description';
        }

        if (array_key_exists('type', $body)) {
            $values['type'] = Input::enum($body['type'], AccountType::class, 'type');
            $supplied[] = 'type';
        }

        if (array_key_exists('subtype', $body)) {
            $values['subtype'] = self::subtypeOf($body);
            $supplied[] = 'subtype';
        }

        if (array_key_exists('normal_balance', $body)) {
            $values['normal_balance'] = Input::enum($body['normal_balance'], NormalBalance::class, 'normal_balance');
            $supplied[] = 'normal_balance';
        }

        if (array_key_exists('is_postable', $body)) {
            $values['is_postable'] = Input::flag($body['is_postable'], 'is_postable');
            $supplied[] = 'is_postable';
        }

        if (array_key_exists('control_type', $body)) {
            $values['control_type'] = Input::enum($body['control_type'], ControlType::class, 'control_type');
            $supplied[] = 'control_type';
        }

        if (array_key_exists('sort_order', $body)) {
            $values['sort_order'] = Input::smallUnsigned($body['sort_order'], 'sort_order');
            $supplied[] = 'sort_order';
        }

        // Pairings the request alone settles. Anything that needs the stored
        // account (a type without a subtype, say) is the handler's.
        if (isset($values['type'])) {
            if (isset($values['normal_balance'])) {
                AccountRules::derive($values['type'], $values['normal_balance']);
            }

            if (array_key_exists('subtype', $values)) {
                AccountRules::subtype($values['type'], $values['subtype']);
            }
        }

        return new AccountDraft(
            $supplied,
            $values['code'] ?? null,
            $values['name'] ?? null,
            $values['description'] ?? null,
            $values['type'] ?? null,
            $values['subtype'] ?? null,
            $values['normal_balance'] ?? null,
            null,
            $values['is_postable'] ?? null,
            null,
            $values['control_type'] ?? null,
            $values['sort_order'] ?? null,
        );
    }

    /**
     * Whole-request key check (AC-8). `currency` is accepted only as null.
     *
     * @param  array<array-key, mixed>  $body
     * @param  list<string>  $allowed
     */
    private static function keys(array $body, array $allowed): void
    {
        $forbidden = Fields::ACCOUNT_FORBIDDEN;

        if (($body[self::CURRENCY] ?? null) !== null) {
            $forbidden[] = self::CURRENCY;
        }

        Fields::only($body, [...$allowed, self::CURRENCY], $forbidden);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private static function subtypeOf(array $body): ?AccountSubtype
    {
        $value = $body['subtype'] ?? null;

        return $value === null ? null : Input::enum($value, AccountSubtype::class, 'subtype');
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private static function normalBalanceOf(array $body): ?NormalBalance
    {
        if (! array_key_exists('normal_balance', $body)) {
            return null;
        }

        return Input::enum($body['normal_balance'], NormalBalance::class, 'normal_balance');
    }
}
