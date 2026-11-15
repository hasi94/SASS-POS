<?php

use Flexipos\Kernel\Schema\Support\SourceRules;

/*
|--------------------------------------------------------------------------
| QA, T-008, AC-16: is there any re-read-and-retry path at all?
|--------------------------------------------------------------------------
|
| AC-16 is an [E] criterion and stays NOT VERIFIED behind ENGINE_HOLD (G-1):
| whether a server really rejects the cached credential, and whether the retry
| then succeeds with the new reference, needs a real server. This file claims
| nothing about that.
|
| What needs no engine is whether the behaviour exists. AC-16 requires that
| when opening the connection fails because the cached credential is rejected,
| the cached entries for the tenant are dropped, the mapping is re-read and the
| connection is retried exactly once. The only way production code can drop a
| tenant's cached mapping is CachingTenantDatabaseRepository::evict(), and the
| only callers in the branch are the runner's own handlers, after a status or
| version write (D-19). Nothing in the connection-opening path - the code under
| packages/kernel/src/Tenancy - evicts, re-reads or retries.
|
| This is a wiring check and the weakest kind of evidence: it goes green the
| moment something under Tenancy/ calls evict(), and says nothing about whether
| the call is on the right failure or happens exactly once. It exists because
| the absence of the path is invisible to every other test in the branch (the
| cache's own tests call evict() themselves).
|
| Comments are stripped first; the control feeds the same scan a snippet that
| does call it.
*/

/**
 * Whether the given PHP source calls ->evict( on anything.
 */
function qaCallsEvict(string $source): bool
{
    return preg_match('/->\s*evict\s*\(/', SourceRules::codeOnly($source)) === 1;
}

it('can see an evict call and ignores a comment that only names it', function () {
    expect(qaCallsEvict('<?php $this->repository->evict($tenantId);'))->toBeTrue()
        ->and(qaCallsEvict("<?php // ->evict(\$tenantId) would go here\nrun();"))->toBeFalse();
});

it('has an evict call in the connection-opening path, outside the cache itself', function () {
    $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Tenancy';
    $callers = [];
    $scanned = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        if ($file->getFilename() === 'CachingTenantDatabaseRepository.php') {
            continue;
        }

        $scanned++;

        if (qaCallsEvict((string) file_get_contents($file->getPathname()))) {
            $callers[] = $file->getFilename();
        }
    }

    // The scan read the tenancy source, not an empty directory.
    expect($scanned)->toBeGreaterThan(10)
        ->and($callers)->not->toBe([]);
});
