# Deployment

Audience: operators installing, configuring and upgrading a production deployment.

How to run the platform in production: one host, Docker Compose, the
`Dockerfile` and the two compose files in this repository. Day-to-day running
is in [the operations runbook](runbooks/OPERATIONS.md); the go-live list is
[PRODUCTION-READINESS.md](PRODUCTION-READINESS.md).

## What runs

| Service | What it is |
|---|---|
| `app` (two replicas) | nginx, php-fpm, the queue workers and the scheduler, under supervisor (`docker/supervisor/supervisord.conf`): two `default` workers, three on `zatca-submissions`, one on `webhooks`, one on `peppol`. |
| `db` | MySQL 8, with the data in the `platform-mysql` volume. |
| `traefik` | The only way in: ports 80 and 443, certificates issued automatically for `APP_DOMAIN`. |
| `redis` | Optional, only with `COMPOSE_PROFILES=redis`. Cache, sessions and queues use the database by default. |

The host needs Docker Engine with Compose 2.24 or later, a synchronised
clock, and about 4 cores and 8 GB of memory (2 cores and 4 GB at the least).

## Where to host it

For a taxpayer resident in Saudi Arabia the server, and its backups, must be
in the Kingdom (ZATCA Tax Invoicing and Records guideline, section 8.3.1).

The documented host is an Oracle Cloud instance:

1. **Home region Saudi Arabia Central (Riyadh) or Saudi Arabia West
   (Jeddah).** Always Free resources exist only in the home region, and the
   home region cannot be changed after sign-up.
2. **Shape VM.Standard.A1.Flex (ARM)**, up to 4 OCPUs and 24 GB. The smaller
   AMD micro shape cannot hold MySQL and the workers. Every image the build
   uses is published for arm64, so nothing needs a `--platform` flag.
   Ubuntu 24.04 as the image; a 100 GB boot volume.
3. **A reserved public IP** (the VNIC's address changed from ephemeral to
   reserved), so DNS survives a restart.
4. **Two firewalls, both closed by default.** Open TCP 80 and 443 in both or
   the site answers nothing:
   - the VCN's security list: ingress rules for 80 and 443 from `0.0.0.0/0`;
   - the instance's own `iptables` (commands below).
5. **A DNS A record** for the host name pointing at the reserved IP. Traefik
   asks for the certificate once the name resolves.

## Install

On stock Ubuntu the Compose package is `docker-compose-v2`
(`docker-compose-plugin` exists only in Docker's own repository):

```bash
sudo apt update && sudo apt install -y docker.io docker-compose-v2 git iptables-persistent
sudo usermod -aG docker "$USER"          # sign out and in again
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
sudo netfilter-persistent save
timedatectl                              # "System clock synchronized: yes"
git clone <repository> platform && cd platform
cp docker/.env.template .env && chmod 600 .env
```

## Configure

Fill in every `<PLACEHOLDER>` in `.env`. Generate each secret once and keep a
copy outside the server (a password manager): a restore needs them
([BACKUP-AND-RESTORE.md](BACKUP-AND-RESTORE.md)).

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
echo "JWT_SECRET=$(openssl rand -hex 32)"
echo "API_KEY_PEPPER=$(openssl rand -base64 32)"
echo "ZATCA_CREDENTIAL_KEY=base64:$(openssl rand -base64 32)"
echo "BACKUP_ARCHIVE_PASSWORD=$(openssl rand -base64 32)"
echo "DB_PASSWORD=$(openssl rand -hex 16)"; echo "DB_ROOT_PASSWORD=$(openssl rand -hex 16)"
```

`API_KEY_PEPPER` must be set before the first API key is issued: changing it
later invalidates every key. Losing `ZATCA_CREDENTIAL_KEY` makes every stored
device certificate unreadable.

### What production refuses to start without

With `APP_ENV=production` the application stops at boot, naming the setting,
rather than run half-configured (`AppServiceProvider`):

| Check | Refused when |
|---|---|
| `APP_DEBUG` | true. |
| `SESSION_ENCRYPT` | not true. |
| `RATE_LIMIT_SUBMISSION`, `ZATCA_RATE_LIMIT_PER_MINUTE` | they differ: both throttle submissions. |
| `ZATCA_ENVIRONMENT` | `sandbox`, or a name with no endpoint (in any environment). |
| `PLATFORM_LICENSE_SECRET` | empty while licensing is enforced. |
| `APP_URL` | empty, a placeholder or localhost. |
| `JWT_SECRET`, `API_KEY_PEPPER`, `ZATCA_CREDENTIAL_KEY` | empty or a placeholder. |
| `MAIL_MAILER` | `log` or `array`. |
| `MAIL_FROM_ADDRESS` | empty, a placeholder or at example.com. |
| `PEPPOL_ASP_CLIENT_ID`, `PEPPOL_ASP_CLIENT_SECRET`, `PEPPOL_ASP_WEBHOOK_SECRET` | empty while `PEPPOL_ASP_BASE_URL` is set. |

The settings in the second half are checked only where the platform serves
(web requests, `queue:work`, `schedule:run`), so a one-off command such as
`jwt:secret` can still be run to fill them in.

### Licensing

`PLATFORM_LICENSE_ENABLED` defaults to true. While it is true every API
request (not the web console) is checked against `PLATFORM_LICENSE_KEY`,
verified with `PLATFORM_LICENSE_SECRET` and, when
`PLATFORM_LICENSE_SERVER_URL` is set, confirmed with the licence server;
without a valid key the API answers 403 `LICENSE_INVALID`. A deployment the
operator runs itself, not under a partner licence, sets
`PLATFORM_LICENSE_ENABLED=false`. See [LICENSING.md](LICENSING.md).

### ZATCA environment

`ZATCA_ENVIRONMENT` is only the default for organizations that have no
environment of their own. A platform admin sets each organization's
environment on its page in the console (sandbox, simulation, production), and
every certificate is kept with the environment that issued it. Production
refuses `sandbox` as the default; use `production`, or `simulation` on a
server that only hosts simulation pilots. The sandbox files only for ZATCA's
test VAT number 399999999900003, which is what `platform:rehearse` uses.

### Redis (optional)

Nothing requires Redis: invoice counters use database row locks and the
scheduler's `onOneServer` locks use the `cache_locks` table. To use it, set
`COMPOSE_PROFILES=redis`, a `REDIS_PASSWORD`, and `CACHE_STORE`,
`SESSION_DRIVER` and `QUEUE_CONNECTION` to `redis`.

## Start

Always name both compose files: without `-f`, Compose loads
`docker-compose.override.yml`, the development port, instead of Traefik.

```bash
alias dc='docker compose -f docker-compose.yml -f docker-compose.prod.yml'
# First start only: AUTO_MIGRATE=true in .env, so the entrypoint migrates.
dc up -d --build --wait --wait-timeout 600
curl -fsS https://<APP_DOMAIN>/api/health/ready
# Then set AUTO_MIGRATE=false again and run: dc up -d
```

`SSL_EMAIL` receives the certificate authority's notices. Without
`AUTO_MIGRATE` run `dc exec app php artisan migrate --force` instead.

### The database account that migrates

Migrations 1814 and 1817 create triggers that refuse any change to an issued
invoice and to the audit log. Two things must hold where they run:

- **The server lets a non-SUPER account create a trigger.** MySQL 8 has
  binary logging on, and then refuses with error 1419 unless
  `log_bin_trust_function_creators=1`. The compose `db` service sets it; on a
  managed MySQL set it in the server's parameter group or configuration.
- **The migrating account holds `TRIGGER`** on the database (the database
  user the compose `db` service creates from `DB_USERNAME` is granted `ALL` on
  it).

A migration that is refused says which of the two to fix, and nothing after it
runs.

Two ways to run it:

| Setup | What it guarantees |
|---|---|
| One account (`DB_USERNAME`) migrates and serves | The triggers refuse every row change the application, a console or a script makes through that account. The account could still drop a trigger or truncate a table, so the guarantee rests on nobody using it for that; the audit chain and `fatoora:verify-hash-chain` show what such a change did |
| A migration account (`DB_MIGRATION_USERNAME`, `DB_MIGRATION_PASSWORD`) with `TRIGGER`, `ALTER`, `DROP`, `CREATE`, `INDEX`, `REFERENCES`; the application account with `SELECT`, `INSERT`, `UPDATE`, `DELETE` only | The application account cannot remove a trigger, truncate a table or change the schema, so the triggers hold against anything that runs as the application. The entrypoint uses the migration account for the migrate step alone |

When `DB_MIGRATION_USERNAME` is empty the application account migrates.

The first platform admin, then the two-factor enrolment link:

```bash
dc exec app php artisan platform:create-admin ops@<your-domain>
dc exec app php artisan platform:enrolment-link ops@<your-domain>    # a new link; --reset for a lost phone
```

`platform:create-admin` prints a one-time enrolment link; a platform admin
cannot sign in at `/admin` without enrolling. Later admins get their link from
another admin in the console.

## Verify

```bash
dc ps                                                    # app, db, traefik healthy
dc exec app php artisan schedule:list                    # the scheduled commands
dc exec app php artisan fatoora:verify-hash-chain
dc exec app php artisan platform:rehearse --admin=ops@<your-domain>
```

`platform:rehearse` runs onboarding, issuing and filing against the sandbox
with a rehearsal organization of its own
([the rehearsal runbook](runbooks/PILOT-REHEARSAL.md)). Before switching a
real organization to simulation run `platform:simulation-preflight`; see
[the pilot runbook](runbooks/PILOT-SIMULATION.md).

## What the edge does

- **Errors while PHP is down.** nginx answers 500 to 504 with
  `public/50x.html` (or `50x.json` for the API). The entrypoint writes them
  in this deployment's brand with `php artisan platform:static-errors`.
- **Rate limiting.** On the API paths nginx limits each client to 100
  requests a second with a burst of 50 and answers 429, not 503. Inside, `RateLimitApi` applies the
  `RATE_LIMIT_*` budgets and also answers 429.
- **Health.** `/api/health` (liveness, used by the container health check)
  and `/api/health/ready` (database and cache, and the ZATCA certificate
  chain and revocation lists where production filing needs them; Traefik
  routes only to replicas that pass). Both bypass the licence check.

## Update

```bash
git pull
dc up -d --build
dc exec app php artisan migrate --force # unless AUTO_MIGRATE=true
```

Take a backup before a release that migrates (`php artisan backup:run`).
