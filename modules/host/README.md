# FlexiPOS module host

The one Laravel application in the monorepo. It owns no business logic of its own: it boots
the modules named in the `MODULES` environment variable, each of which lives in
its own Composer path package under `modules/<name>/`, and exposes the two health probes.

- `GET /health/live` → `{"status":"ok"}` — liveness, registered outside every middleware
  group so it answers while the database is down
- `GET /health/ready` → `{"status":"ok","modules":[...]}` — which modules this
  process actually booted

Adding a module to a running host is a configuration change: `MODULES=demo,billing`. The
mechanism, and the manifest contract a module must satisfy, are documented in
`app/Support/ModuleHost/ModuleRegistry.php`. Before running `config:cache`,
`route:cache`, or building the autoloader with `--classmap-authoritative`, read the four
constraints in `config/modulehost.php`.

## Commands

PHP and Composer are not on `PATH` on the development machine; see
[`../../CLAUDE.md`](../../CLAUDE.md) for the absolute paths to use.

```bash
cd modules/host
composer install
composer check          # lint + stan + deptrac + test — the CI gate
composer test:fast      # inner loop: everything except the toolchain group
composer test:slow      # only the toolchain group
php artisan module:make billing
```

The `toolchain` group is the set of test files that spawn a child process to boot a whole
tool. It is declared in one place, `tests/Helpers.php`'s `toolchainGroupFiles()`, and
`tests/Feature/ToolchainGroupCoverageTest.php` fails if that list stops matching the files
that really spawn — so `test:fast` cannot quietly decay, and `composer check`, which passes no
group flag at all, cannot lose a test to the split.

## More

- [`../../README.md`](../../README.md) — what FlexiPOS is, the monorepo layout, how to add
  a module
- [`../../docs/architecture.md`](../../docs/architecture.md) — modules, contracts, events

## Licence

Proprietary. `composer.json` declares `"license": "proprietary"`; nothing in this
repository is open source.
