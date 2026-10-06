# FlexiPOS — Deployment on the Existing VPS and Scaling Plan

| | |
|---|---|
| Status | DRAFT v0.3 — companion to [architecture.md](architecture.md) §20–§21 |
| Date | 2026-09-28 |
| Target host | Hostinger **KVM 1**, Ubuntu 24.04, CloudPanel (Nginx + PHP-FPM + MariaDB managed by the panel), IPv4 72.60.220.171, root SSH |
| Observed | CPU 2 %, RAM 35 % used, disk 14 GB of 50 GB used, bandwidth 4 TB/month |
| Plan spec (confirm with `nproc`, `free -m`, `df -h`) | 1 vCPU, 4 GB RAM, 50 GB NVMe |

Contents: 1 Rules · 2 Topology · 3 Containers and resource budget · 4 Ports, networks, storage · 5 Installation runbook · 6 Operations · 7 Lean fallback profile · 8 Capacity of this VPS and move-out plan · 9 Scaling strategy to 500 / 3 000 / 300 000

---

## 1. Rules (from the VPS constraint)

1. Nothing already on the VPS is modified, replaced or restarted. CloudPanel keeps managing its sites, Nginx, PHP-FPM and MariaDB.
2. The POS stack binds exactly one host port, on loopback only: `127.0.0.1:8088`. No `0.0.0.0` bindings, no host networking.
3. All POS containers run on private Docker networks; the backend network is `internal: true`.
4. Every container has memory and CPU limits; the stack's total stays under the free RAM with headroom.
5. Public entry is a new CloudPanel **Reverse Proxy** site (`pos.<domain>` → `http://127.0.0.1:8088`) with the panel's Let's Encrypt certificate. No manual edits to Nginx config outside that site.
6. Docker Engine is the only host-level addition (package install). Its iptables rules affect only published ports, and we publish none to the public interface.
7. Everything lives under `/opt/flexipos` (compose files, `.env`, backups) and named Docker volumes; nothing under CloudPanel's `/home/*` site directories.

---

## 2. Topology

```text
Internet ──443──► CloudPanel Nginx (existing)  site: pos.<domain>  (reverse proxy, TLS, HTTP/2, gzip)
                        │ proxy_pass http://127.0.0.1:8088 ; proxy_set_header X-Forwarded-* ; websocket upgrade
                        ▼  (loopback only)
                 ┌──────────────────────────── Docker: network pos_edge ────────────────────────────┐
                 │  pos-gateway (Traefik 3)  ─► /            → pos-web   (nginx, Angular static)    │
                 │                           ─► /api/v1/*    → pos-api   (host-all: 13 modules)     │
                 │                           ─► /health/*    → pos-api                              │
                 └───────────────┬──────────────────────────────────────────────────────────────────┘
                                 │ network pos_backend (internal: true)
      pos-api (nginx + php-fpm)  pos-worker (queues + stream consumers + outbox relay)  pos-scheduler (cron)
      pos-mysql (MySQL 8.4: master DB + one DB per tenant)   pos-redis-data (streams, queues, locks; noeviction)   pos-redis-cache (LRU)
```

POS terminals and the Print Agent reach the platform only through `https://pos.<domain>`. The Print Agent itself runs on the shop PCs, not on the VPS.

---

## 3. Containers and resource budget

| Container | Image | Role | RAM limit | CPU (shares/limit) | Notes |
|---|---|---|---|---|---|
| pos-gateway | traefik:3 | routing, rate limit, access log | 64 MB | 0.1 | dashboard disabled; file provider |
| pos-web | nginx:alpine | Angular admin + POS static files | 32 MB | 0.1 | immutable asset caching |
| pos-api | flexipos/host (php:8.3-fpm-alpine + nginx) | all 13 modules, `MODULES=all` | 384 MB | 0.6 | `pm=dynamic`, `max_children=6`, `memory_limit=96M`, OPcache on, `realpath_cache` tuned |
| pos-worker | flexipos/host | `module:consume` per module group + Laravel queues + outbox relay (supervisord, 3 processes) | 256 MB | 0.3 | processes: relay+consumers, queue:default, queue:sync |
| pos-scheduler | flexipos/host | `schedule:run` every minute | 48 MB | 0.05 | could be a cron in pos-worker to save 48 MB |
| pos-mysql | mysql:8.4 | master DB + tenant DBs | 640 MB | 0.5 | `innodb_buffer_pool_size=256M`, `innodb_log_file_size=128M`, `max_connections=60`, `performance_schema=OFF`, binlog ON (7 days) for point-in-time restore |
| pos-redis-data | redis:7-alpine | streams, queues, locks | 128 MB | 0.1 | `maxmemory 96mb`, `maxmemory-policy noeviction`, AOF `everysec`, streams capped by `MAXLEN` |
| pos-redis-cache | redis:7-alpine | cache, rate limits | 64 MB | 0.05 | `maxmemory 48mb`, `allkeys-lru`, no persistence |
| **Total** | | | **≈ 1.6 GB limit, ≈ 1.2 GB typical** | ≈ 1 vCPU shared | leaves ≈ 1 GB free on a 4 GB box at today's 35 % usage |

CPU is the real constraint: one vCPU is shared with the existing apps. Expect ≈ 3 online sales/s sustained and short bursts above that; PHP-FPM saturation shows up as gateway 502/504 and is the signal to move to stage 1 (§8).

Disk: images ≈ 1.2 GB, MySQL data growth ≈ 0.5 GB per 100 000 sales (with items, movements, events before archival), Redis AOF < 200 MB, backups 7 daily + 4 weekly compressed dumps ≈ 2–4 GB at pilot scale. Fits in the ≈ 36 GB free with alerting at 80 %.

---

## 4. Ports, networks, storage

| Item | Value |
|---|---|
| Host port bindings | `127.0.0.1:8088 → pos-gateway:80` only |
| Ports NOT touched | 22, 80, 443 (CloudPanel Nginx), 8443 (CloudPanel UI), 3306 (panel MariaDB), 6379 if the panel's Redis exists, anything else in `ss -tulpn` at install time (recorded in the runbook) |
| Internal container ports | gateway 80, web 80, api 80, mysql 3306, redis 6379 (reachable only inside `pos_backend`/`pos_edge`) |
| Networks | `pos_edge` (gateway ↔ web/api), `pos_backend` (`internal: true`; api/worker/scheduler ↔ mysql/redis) |
| Volumes | `pos_mysql_data`, `pos_redis_data`, `pos_storage` (uploads, plugin packages, exports), `pos_gateway_logs` |
| Bind mounts | `/opt/flexipos/.env` (600, root), `/opt/flexipos/gateway/` (config), `/opt/flexipos/backups/` |
| Outbound | HTTPS to mail/SMS providers, Sentry, image registry; nothing inbound except via CloudPanel Nginx |

---

## 5. Installation runbook (Phase 0 task FP-020)

1. **Pre-flight (read-only)**: `nproc`, `free -m`, `df -h`, `ss -tulpn`, `docker --version || echo none`, `ufw status`, note CloudPanel version. Record results in `docs/releases/vps-preflight-<date>.md`.
2. **Docker Engine**: install from Docker's apt repository (`docker-ce`, `docker-compose-plugin`); do not enable the `docker` group for the CloudPanel site users. Confirm CloudPanel sites still respond.
3. **Directory**: `/opt/flexipos/{compose,gateway,backups,scripts}`; copy `infra/compose/vps.yml`, `gateway/*`, `.env.vps.example → .env` (fill secrets: DB passwords per service, JWT private key path, app keys, mail/SMS credentials, Sentry DSN).
4. **Images**: `docker compose --env-file .env -f compose/vps.yml pull` (images from the CI registry, pinned tags).
5. **Database init**: `docker compose run --rm pos-api php artisan platform:install` creates the master database and runs its migrations; each tenant database is then created by the provisioning job when a tenant is added.
6. **Start**: `docker compose up -d`; verify `curl -s http://127.0.0.1:8088/health/ready`.
7. **CloudPanel site**: Sites → Add Site → Reverse Proxy → domain `pos.<domain>`, target `http://127.0.0.1:8088`; enable Let's Encrypt; in the site's Nginx vhost settings (panel UI) add websocket upgrade headers and `client_max_body_size 25m` for imports.
8. **Smoke**: create the platform admin (`php artisan platform:admin:create`), log in, create a tenant, run the provisioning saga check (`php artisan tenant:provision-status <id>`).
9. **Backups**: install the `pos-backup` cron in root's crontab (nightly `mysqldump --single-transaction` per schema, gzip, 7-day rotation, optional `rclone` to object storage).
10. **Monitoring**: enable the gateway access log, Sentry DSN, and an external uptime check on `https://pos.<domain>/health/live`.

Uninstall is `docker compose down -v` plus deleting the CloudPanel site; nothing else on the host changes.

---

## 6. Operations

| Task | How |
|---|---|
| Deploy a release | CI builds tagged images → on VPS: `compose pull`, `run --rm pos-api php artisan module:migrate --all` (expand-only), `compose up -d` (≈ 5–10 s API restart; acceptable for pilot), verify `/health/ready` |
| Rollback | `compose up -d` with previous tags; expand-only migrations keep old code working |
| Logs | `docker compose logs -f pos-api`, json-file driver with `max-size=20m`, `max-file=5` |
| Backups | nightly dumps per schema + MySQL binlog (7 days) for point-in-time; restore drill monthly; per-tenant export via `php artisan tenant:export <id>` |
| Secrets | `.env` mode 600; JWT private key in `/opt/flexipos/keys` (600); rotation command `php artisan identity:rotate-keys` |
| Resource alerts | `docker stats` sampling by the scheduler → metric; alert at RAM > 85 % of limits or host free RAM < 500 MB; disk > 80 % |
| Updates of the host | unattended-upgrades for security patches; Docker engine updates in a maintenance window |
| Print Agent releases | MSI uploaded to `pos_storage`; version announced via heartbeat |

---

## 7. Lean fallback profile (if RAM becomes short)

`compose/vps-lean.yml` overrides: drop `pos-mysql` and point the 13 connections at CloudPanel's MariaDB with 13 dedicated users created through the panel (saves ≈ 600 MB), merge `pos-scheduler` into `pos-worker` (saves ≈ 48 MB), single Redis with `noeviction` and TTLs on cache keys (saves ≈ 64 MB). Cost: MariaDB 10.11/11 instead of MySQL 8.4 (CI keeps a MariaDB job to prove compatibility) and a shared failure domain with the existing apps. Use only with product-owner sign-off (open question Q5).

---

## 8. Capacity of this VPS and move-out plan

| Question | Answer |
|---|---|
| What can it serve? | pilot: ≤ 20 tenants, ≤ 100 terminals, ≈ 3 sales/s bursts, reporting for pilot volumes |
| First signal to move | PHP-FPM pool saturation (502/504 at the gateway), consumer lag > 60 s at peak, host RAM > 85 % |
| Move to a dedicated VPS (stage 1) | rsync `/opt/flexipos`, dump/restore the master and every tenant database, start the same compose (`vps.yml` with higher limits), switch DNS; downtime = dump/restore window (minutes at pilot size). Nothing on the shared VPS needs cleanup except `compose down -v` and the panel site |
| Move to Azure/AWS (stage 2) | same images; compose translated to Container Apps/ECS/AKS/EKS manifests; MySQL schemas restored into managed MySQL; Redis to managed Redis; `pos_storage` to Blob/S3; gateway becomes Traefik behind the cloud load balancer or the provider's ingress |

---

## 9. Scaling strategy to 500 tenants, 3 000 terminals, 300 000 sales/day

Load model: 300 000 sales/day ≈ 3.5/s average, planned peak **35/s** (10×). Per online sale: 1 gateway request, 1–2 internal calls, 6–8 events, ≈ 12 rows. Pull traffic ≈ 10 req/s. Yearly rows at target: sales 110 M, sale_items 400 M, stock_movements 400 M; events ≈ 2.5 M/day.

| Stage | Infra | Capacity | Changes |
|---|---|---|---|
| 0 Pilot | this VPS, one host-all | ≤ 100 terminals, ≈ 3 sales/s | measure only |
| 1 Dedicated VPS | 4 vCPU / 16 GB (or app 4/8 + DB 2/8) | ≤ 1 000 terminals, 10–15 sales/s | worker per queue/stream, MySQL buffer pool 8 GB, monthly partitions, optional reporting replica |
| 2 Cloud | autoscaled containers, managed MySQL + replica, managed Redis, object storage, CDN | **3 000 terminals, 35 sales/s** | hosts split: `host-txn` (sales, payment, sync) ×3–6, `host-data` ×2–4, `host-core` ×2, `host-rpt` on replica; consumers scaled by lag; RabbitMQ/Kafka only if stream lag persists |
| 3 Beyond | several database servers, tenants placed by the mapping; multi-region | thousands of tenants | tenants spread across database servers via the mapping, regional deployment, analytics warehouse for reporting |

Levers in order: host replicas → consumer workers → MySQL vertical → read replica → split hosts → shard schemas by tenant → dedicated instance per service. Budgets: checkout p95 < 300 ms online, 50-item sync batch < 2 s, pull delta < 200 ms, consumer lag < 5 s at peak.
