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
| `storage/secrets` at all. This test asserts the directory `git check-ignore`
| actually sees matches the config default, and that the ignore file itself
| covers every file a real run would write there (everything except itself).
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

it('actually blocks a secret-shaped file from being added to git', function (): void {
    $root = storage_path('secrets');
    $probe = $root.DIRECTORY_SEPARATOR.'probe-'.bin2hex(random_bytes(6)).'.json';

    file_put_contents($probe, '{"password":"should-never-be-committed"}');

    try {
        $process = proc_open(
            ['git', 'check-ignore', '--quiet', $probe],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path()
        );

        expect($process)->not->toBeFalse('Expected git to be available to run check-ignore.');

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // git check-ignore exits 0 when the path IS ignored.
        expect($exitCode)->toBe(0, "Expected [{$probe}] to be reported as git-ignored.");
    } finally {
        if (is_file($probe)) {
            unlink($probe);
        }
    }
});
