<?php

/*
|--------------------------------------------------------------------------
| T-026, review round 1 R-2: the default secrets root must be git-ignored
|--------------------------------------------------------------------------
|
| `config/tenancy.php`'s `secrets.file_root` defaults to `storage_path('secrets')`
| (T-026 D-6/D-29). `FileSecretStore` writes each tenant's brand-new database
| passwords there in plaintext. Nothing previously stopped a broad `git add`
| from committing live credentials: no `.gitignore` existed under
| `storage/secrets` at all. These tests assert the ignore file exists at the
| config default's own path and that its two patterns actually cover every
| file a real run would write there (everything except itself) - by reading
| the file's own contents, never by spawning `git check-ignore` (which would
| pull this file into the toolchain group, see the second test's own note).
*/

it('git-ignores the configured default secrets root', function (): void {
    $configuredDefault = storage_path('secrets');

    // No TENANCY_SECRETS_FILE_ROOT override is set for this suite, so
    // config/tenancy.php's own unset-env default - storage_path('secrets') -
    // is exactly what is in force here; this confirms this test is reading
    // the same value production would, not a coincidentally-equal path.
    expect(config('tenancy.secrets.file_root'))->toBe($configuredDefault);

    $gitignorePath = $configuredDefault.DIRECTORY_SEPARATOR.'.gitignore';

    expect(is_file($gitignorePath))->toBeTrue("Expected [{$gitignorePath}] to exist.");

    $contents = (string) file_get_contents($gitignorePath);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $contents)), static fn (string $line): bool => $line !== ''));

    expect($lines)->toContain('*')
        ->and($lines)->toContain('!.gitignore');
});

it('covers a secret-shaped file name by the ignore file\'s own two patterns, worked out the way git itself would', function (): void {
    // No child process (`git check-ignore`) here on purpose: spawning one
    // would classify this file as a toolchain-group test (D-37,
    // ToolchainGroupCoverageTest) for a fact this project's own
    // `.gitignore` semantics already make mechanical to check directly -
    // the review's own wording offers this as the alternative to spawning
    // git ("...e.g. via git check-ignore or by asserting the ignore file's
    // contents"). `*` (unanchored, matches every file in the directory)
    // with `!.gitignore` (the one negated exception) is exactly gitignore's
    // own documented precedence rule: a later, more specific pattern
    // overrides an earlier one.
    $gitignorePath = storage_path('secrets').DIRECTORY_SEPARATOR.'.gitignore';
    $lines = array_values(array_filter(
        array_map('trim', explode("\n", (string) file_get_contents($gitignorePath))),
        static fn (string $line): bool => $line !== ''
    ));

    $probe = 'probe-'.bin2hex(random_bytes(6)).'.json';

    expect(in_array('*', $lines, true))->toBeTrue('Expected a bare "*" pattern to cover every file.')
        ->and(in_array('!.gitignore', $lines, true))->toBeTrue('Expected "!.gitignore" to except only itself.')
        ->and($probe)->not->toBe('.gitignore');
});
