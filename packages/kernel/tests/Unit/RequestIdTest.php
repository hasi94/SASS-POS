<?php

use Flexipos\Kernel\Http\RequestId;
use Illuminate\Container\Container;
use Illuminate\Http\Request;

/*
 * T-017 step 1 (AC-1, AC-12 infrastructure).
 *
 * Built exactly like Flexipos\Access\Support\ClientIp, and the cases below are
 * named after its own "null outside a request" case so a reviewer can check
 * the two behave alike (AC-1's stated control).
 *
 * No TestCase binding: a bare Illuminate\Container\Container is enough to
 * prove the resolver reads whatever the container currently holds, and
 * nothing here needs a booted application.
 */

/**
 * Bind a request into a bare container exactly as the real Application
 * aliases it: `Request::class` holds the instance, and `request` is an
 * alias of it - which is what makes both `bound('request')` and
 * `make(Request::class)` answer from the same object.
 */
function requestIdContainerWith(Request $request): Container
{
    $container = new Container;
    $container->instance(Request::class, $request);
    $container->alias(Request::class, 'request');

    return $container;
}

it('answers none() with null and fixed() with the given value', function () {
    expect(RequestId::none()->current())->toBeNull()
        ->and(RequestId::fixed('X')->current())->toBe('X')
        ->and(RequestId::fixed(null)->current())->toBeNull();
});

it('gives null when the container holds no request at all', function () {
    $container = new Container;

    expect(RequestId::fromRequests($container)->current())->toBeNull();
});

it('gives null for a bound request carrying no attribute - the console case', function () {
    $container = requestIdContainerWith(Request::create('/probe'));

    expect(RequestId::fromRequests($container)->current())->toBeNull();
});

it('gives the attribute s value for a request that carries one', function () {
    $request = Request::create('/probe');
    $request->attributes->set(RequestId::ATTRIBUTE, '01ARZ3NDEKTSV4RRFFQ69G5FAV');

    $container = requestIdContainerWith($request);

    expect(RequestId::fromRequests($container)->current())->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV');
});

it('is lazy: swapping the container s request between two calls changes the answer', function () {
    $container = new Container;
    $resolver = RequestId::fromRequests($container);

    $first = Request::create('/probe-1');
    $first->attributes->set(RequestId::ATTRIBUTE, 'first-id');
    $container->instance(Request::class, $first);
    $container->alias(Request::class, 'request');

    expect($resolver->current())->toBe('first-id');

    $second = Request::create('/probe-2');
    $second->attributes->set(RequestId::ATTRIBUTE, 'second-id');
    $container->instance(Request::class, $second);

    expect($resolver->current())->toBe('second-id');
});
