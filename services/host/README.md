# FlexiPOS service host

The one Laravel application in the monorepo. It owns no business logic of its own: it boots
the service packages named in the `SERVICES` environment variable, each of which lives in
its own Composer path package under `services/<name>/`, and exposes the two health probes.

- `GET /health/live` → `{"status":"ok"}` — liveness, registered outside every middleware
  group so it answers while the database is down
- `GET /health/ready` → `{"status":"ok","services":[...]}` — which service packages this
  process actually booted

Adding a service to a running host is a configuration change: `SERVICES=demo,billing`. The
mechanism, and the manifest contract a service package must satisfy, are documented in
`app/Support/ServiceHost/ServiceRegistry.php`. Before running `config:cache`,
`route:cache`, or building the autoloader with `--classmap-authoritative`, read the four
constraints in `config/servicehost.php`.

## Commands

PHP and Composer are not on `PATH` on the development machine; see
[`../../CLAUDE.md`](../../CLAUDE.md) for the absolute paths to use.

```bash
cd services/host
composer install
composer check          # lint + stan + deptrac + test — the CI gate
php artisan svc:make billing
```

## More

- [`../../README.md`](../../README.md) — what FlexiPOS is, the monorepo layout, how to add
  a service
- [`../../docs/architecture.md`](../../docs/architecture.md) — services, contracts, events

## Licence

Proprietary. `composer.json` declares `"license": "proprietary"`; nothing in this
repository is open source.
