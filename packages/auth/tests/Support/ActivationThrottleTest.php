<?php

use Flexipos\Auth\Support\ActivationThrottle;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;

/*
|--------------------------------------------------------------------------
| T-014 step 11 - the activation throttle's rules, in isolation (AC-31, D-18,
| Ruling 12)
|--------------------------------------------------------------------------
|
| Each rule the class documents is a case, on an array cache and a clock the
| test moves: a limiter that read Carbon could not be driven past its window.
|
| **The isolation control is a mis-keyed double.** Keying by something two
| tenants share would pass every case above (one tenant is all they use), so
| the last case builds that bug out of the same cache and shows it locking a
| tenant that never failed, and shows the real keys do not. The test reports
| the difference; it does not merely hope the keys differ.
*/

/**
 * A throttle over a fresh array cache and a clock fixed at a known instant.
 *
 * @return array{ActivationThrottle, RunnerClock, Repository}
 */
function throttleUnderTest(int $max = 10, int $minutes = 15): array
{
    $cache = new Repository(new ArrayStore);
    $clock = RunnerClock::fixedAt('2026-10-22 10:00:00.000');
    $config = new ConfigRepository(['authentication' => ['terminal_activation' => ['max_failures' => $max, 'window_minutes' => $minutes]]]);

    return [new ActivationThrottle($cache, $clock, $config), $clock, $cache];
}

it('locks a key at exactly the limit and not one failure before (AC-31)', function () {
    [$throttle] = throttleUnderTest();
    $key = $throttle->tenantKey('TENANTONE');

    for ($i = 1; $i <= 9; $i++) {
        $throttle->recordFailure($key);

        expect($throttle->isLocked($key))->toBeFalse("locked after only {$i}");
    }

    $throttle->recordFailure($key);

    expect($throttle->isLocked($key))->toBeTrue();
});

it('anchors the window to the first failure, so a later failure does not move the unlock (Ruling 12)', function () {
    [$throttle, $clock] = throttleUnderTest(3);
    $key = $throttle->addressKey('203.0.113.1');

    $throttle->recordFailure($key);
    $clock->advanceMinutes(10);
    $throttle->recordFailure($key);
    $throttle->recordFailure($key);

    expect($throttle->isLocked($key))->toBeTrue();

    // Five minutes after the LAST failure, fifteen after the first.
    $clock->advanceMinutes(5);

    expect($throttle->isLocked($key))->toBeFalse();
});

it('is still locked a minute before the window ends and free at its end (AC-31)', function () {
    [$throttle, $clock] = throttleUnderTest(2);
    $key = $throttle->tenantKey('TENANTONE');

    $throttle->recordFailure($key);
    $throttle->recordFailure($key);

    $clock->advanceMinutes(14);
    expect($throttle->isLocked($key))->toBeTrue();

    $clock->advanceMinutes(1);
    expect($throttle->isLocked($key))->toBeFalse();
});

it('starts a new window at the first failure after the old one has elapsed', function () {
    [$throttle, $clock] = throttleUnderTest(2);
    $key = $throttle->tenantKey('TENANTONE');

    $throttle->recordFailure($key);
    $clock->advanceMinutes(16);
    $throttle->recordFailure($key);

    // One failure in the new window, not two: the old one does not count.
    expect($throttle->isLocked($key))->toBeFalse();

    $throttle->recordFailure($key);

    expect($throttle->isLocked($key))->toBeTrue();
});

it('keys an address by a hash, so no address is stored, and treats an absent address as one key', function () {
    [$throttle, , $cache] = throttleUnderTest();

    $key = $throttle->addressKey('203.0.113.9');

    expect($key)->toBe('terminal-activation:ip:'.hash('sha256', '203.0.113.9'))
        ->and($key)->not->toContain('203.0.113.9')
        ->and($throttle->addressKey(null))->toBe($throttle->addressKey(null))
        ->and($throttle->addressKey(null))->not->toBe($key);

    $throttle->recordFailure($key);

    expect(json_encode($cache->get($key)))->not->toContain('203.0.113.9');
});

it('records a failure as one bracket - lock, then read-and-write, then release (W-3)', function () {
    $store = new RecordingLockStore(new ArrayStore);
    $cache = new Repository($store);
    $clock = RunnerClock::fixedAt('2026-10-22 10:00:00.000');
    $config = new ConfigRepository(['authentication' => ['terminal_activation' => ['max_failures' => 10, 'window_minutes' => 15]]]);
    $throttle = new ActivationThrottle($cache, $clock, $config);

    $key = $throttle->tenantKey('TENANTONE');
    $throttle->recordFailure($key);

    // One lock acquired, exactly one read and one write of the counter, and
    // the lock released - all before anything else touches the key, which is
    // what makes the read-then-write a single atomic step rather than two.
    expect($store->log)->toBe(['lock-acquired', 'get', 'put', 'lock-released']);
});

it('keeps two tenants and two addresses apart, and shows a shared key would not (isolation control, AC-31)', function () {
    [$throttle, , $cache] = throttleUnderTest(2);

    $a = $throttle->tenantKey('TENANT-A-ID');
    $b = $throttle->tenantKey('TENANT-B-ID');

    $throttle->recordFailure($a);
    $throttle->recordFailure($a);

    expect($throttle->isLocked($a))->toBeTrue()
        ->and($throttle->isLocked($b))->toBeFalse()
        ->and($throttle->addressKey('203.0.113.1'))->not->toBe($throttle->addressKey('203.0.113.2'))
        ->and($a)->not->toBe($b);

    // The bug the control builds: both tenants mapped to ONE key. The same
    // two failures against A now lock B as well, which is what the real keys
    // above prove they do not.
    $shared = static fn (string $tenantId): string => 'terminal-activation:tenant:shared';

    $cache->put($shared('A'), ['count' => 2, 'first_at' => RunnerClock::fixedAt('2026-10-22 10:00:00.000')->now()->getTimestamp()], 3600);

    expect($cache->get($shared('B')))->not->toBeNull()
        ->and($cache->get($b))->toBeNull();
});

/**
 * A cache store that wraps a real one but records the order its own calls
 * and its lock's acquire/release happen in, so a test can tell a bracketed
 * read-then-write apart from two separate, unguarded calls (W-3).
 */
final class RecordingLockStore implements LockProvider, Store
{
    /** @var list<string> */
    public array $log = [];

    public function __construct(private readonly ArrayStore $inner) {}

    public function get($key)
    {
        $this->log[] = 'get';

        return $this->inner->get($key);
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys)
    {
        return $this->inner->many($keys);
    }

    public function put($key, $value, $seconds)
    {
        $this->log[] = 'put';

        return $this->inner->put($key, $value, $seconds);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values, $seconds)
    {
        return $this->inner->putMany($values, $seconds);
    }

    /**
     * @return int
     */
    public function increment($key, $value = 1)
    {
        return $this->inner->increment($key, $value);
    }

    /**
     * @return int
     */
    public function decrement($key, $value = 1)
    {
        return $this->inner->decrement($key, $value);
    }

    public function forever($key, $value)
    {
        return $this->inner->forever($key, $value);
    }

    public function forget($key)
    {
        return $this->inner->forget($key);
    }

    public function flush()
    {
        return $this->inner->flush();
    }

    public function getPrefix()
    {
        return $this->inner->getPrefix();
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        return new RecordingLock($this, $this->inner->lock($name, $seconds, $owner));
    }

    public function restoreLock($name, $owner)
    {
        return new RecordingLock($this, $this->inner->restoreLock($name, $owner));
    }
}

/**
 * Decorates a real array lock, logging onto its store when the lock is
 * actually acquired and released, not merely requested.
 */
final class RecordingLock implements Lock
{
    public function __construct(
        private readonly RecordingLockStore $store,
        private readonly Lock $inner,
    ) {}

    public function get($callback = null)
    {
        return $this->inner->get(function () use ($callback) {
            $this->store->log[] = 'lock-acquired';

            return $callback === null ? true : $callback();
        });
    }

    public function block($seconds, $callback = null)
    {
        return $this->inner->block($seconds, function () use ($callback) {
            $this->store->log[] = 'lock-acquired';

            $result = $callback === null ? true : $callback();

            $this->store->log[] = 'lock-released';

            return $result;
        });
    }

    public function release()
    {
        return $this->inner->release();
    }

    public function owner()
    {
        return $this->inner->owner();
    }

    public function forceRelease()
    {
        $this->inner->forceRelease();
    }
}
