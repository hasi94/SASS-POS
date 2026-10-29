<?php

use Flexipos\Access\Support\ClientUserAgent;
use Flexipos\Kernel\Http\RequestId;
use Illuminate\Container\Container;
use Illuminate\Http\Request;

/*
 * T-017 step 2 (AC-2).
 *
 * Built exactly like Flexipos\Access\Support\ClientIp, and named after its own
 * "null outside a request" case so a reviewer can check the two behave alike
 * (AC-1's stated control, which this class shares).
 *
 * No TestCase binding: a bare Illuminate\Container\Container proves the
 * resolver reads whatever the container currently holds.
 */

/**
 * Bind a request into a bare container the way the real Application aliases
 * it, with the given User-Agent header and, unless told otherwise, the
 * RequestId attribute a real request would carry once it passed through the
 * minting middleware.
 */
function clientUserAgentContainer(?string $userAgent, bool $insideRequest = true): Container
{
    $container = new Container;

    // Built through the constructor directly, never Request::create(): that
    // factory fills a missing User-Agent with the literal "Symfony" for
    // browser-like test fixtures, which would make "no header at all"
    // unreachable.
    $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/probe'];

    if ($userAgent !== null) {
        $server['HTTP_USER_AGENT'] = $userAgent;
    }

    $request = new Request(server: $server);

    if ($insideRequest) {
        $request->attributes->set(RequestId::ATTRIBUTE, 'the-request-id');
    }

    $container->instance(Request::class, $request);
    $container->alias(Request::class, 'request');

    return $container;
}

it('gives null when the container holds no request at all', function () {
    $container = new Container;

    expect(ClientUserAgent::fromRequests($container)->header())->toBeNull();
});

it('gives null for a request without the id attribute - the console case', function () {
    $container = clientUserAgentContainer('Mozilla/5.0', insideRequest: false);

    expect(ClientUserAgent::fromRequests($container)->header())->toBeNull();
});

it('gives null when there is no header', function () {
    $container = clientUserAgentContainer(null);

    expect(ClientUserAgent::fromRequests($container)->header())->toBeNull();
});

it('gives null for an empty header, not an empty string (AC-2 s control)', function () {
    $container = clientUserAgentContainer('');

    expect(ClientUserAgent::fromRequests($container)->header())->toBeNull()
        ->and(ClientUserAgent::fromRequests($container)->header())->not->toBe('');
});

it('gives null for a whitespace-only header', function () {
    $container = clientUserAgentContainer('   ');

    expect(ClientUserAgent::fromRequests($container)->header())->toBeNull();
});

it('stores 300 characters as 255', function () {
    $long = str_repeat('a', 300);
    $container = clientUserAgentContainer($long);

    $header = ClientUserAgent::fromRequests($container)->header();

    expect($header)->not->toBeNull()
        ->and(mb_strlen((string) $header))->toBe(255)
        ->and($header)->toBe(str_repeat('a', 255));
});

it('is lazy: swapping the container s request between two calls changes the answer', function () {
    $container = clientUserAgentContainer('first-agent');
    $resolver = ClientUserAgent::fromRequests($container);

    expect($resolver->header())->toBe('first-agent');

    $second = Request::create('/probe-2', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'second-agent']);
    $second->attributes->set(RequestId::ATTRIBUTE, 'another-id');
    $container->instance(Request::class, $second);

    expect($resolver->header())->toBe('second-agent');
});

it('none() always gives null, and fixed() gives the cut value', function () {
    expect(ClientUserAgent::none()->header())->toBeNull()
        ->and(ClientUserAgent::fixed('a chosen agent')->header())->toBe('a chosen agent')
        ->and(ClientUserAgent::fixed('')->header())->toBeNull()
        ->and(ClientUserAgent::fixed(str_repeat('b', 300))->header())->toBe(str_repeat('b', 255));
});
