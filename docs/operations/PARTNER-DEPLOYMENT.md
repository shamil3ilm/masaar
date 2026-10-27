# Partner deployment

For a partner who hosts the platform on their own infrastructure. The
installation itself is [DEPLOYMENT.md](DEPLOYMENT.md); this page is what
differs for a partner.

## Who provides what

| The partner | The platform's maintainers |
|---|---|
| A host or cluster in the Kingdom for Saudi taxpayers (2+ vCPU, 4 GB+ per container) | The image, versioned ([DOCKER-REGISTRY.md](DOCKER-REGISTRY.md)), or the `Dockerfile` |
| MySQL 8 (a managed database is fine) | `docker-compose.yml`, `docker-compose.prod.yml`, `docker/.env.template` |
| HTTPS on their own domain | Migrations, run by the image |
| An object store for backups | A licence key and its secret ([LICENSING.md](LICENSING.md)) |
| Mail delivery, monitoring, on-call | The API description, `docs/openapi.yaml` |

## Differences from a self-hosted install

- **Licensing is on.** Set `PLATFORM_LICENSE_KEY` and
  `PLATFORM_LICENSE_SECRET` as issued; keep `PLATFORM_LICENSE_ENABLED` true.
  Production refuses to start without the secret, and the API answers 403
  `LICENSE_INVALID` without a valid key.
- **The image comes from the registry.** Log in with the credentials you were
  given, then set `IMAGE_NAME` and `IMAGE_TAG` in `.env` to the image and the
  release you were told to run; `docker compose ... up -d` without `--build`.
- **A managed database** replaces the `db` service's data: point `DB_HOST`,
  `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` at it, create the
  database as `utf8mb4`, and leave `DB_DUMP_SSL_FLAG` empty if its
  certificate is trusted. The application's user needs to create and alter
  tables for migrations.
- **Another load balancer** instead of Traefik: terminate HTTPS there, route
  only to containers whose `/api/health/ready` answers 200, and list its
  addresses in `TRUSTED_PROXIES` so the rate limits and the audit log see the
  real client.

## Upgrades and rollback

```bash
alias dc='docker compose -f docker-compose.yml -f docker-compose.prod.yml'
dc exec app php artisan backup:run              # first, if the release migrates
# set IMAGE_TAG to the new release in .env, then:
dc pull app && dc up -d
dc exec app php artisan migrate --force         # unless AUTO_MIGRATE=true
curl -fsS https://<your-domain>/api/health/ready
```

To roll back, set the previous `IMAGE_TAG` and `dc up -d`. A release that
migrated may need its migrations undone first; ask before rolling back across
one.

## Monitoring

| Endpoint | Meaning |
|---|---|
| `GET /api/health` | Liveness: PHP answers. |
| `GET /api/health/ready` | Readiness: the database and cache answer, and in production each country that is on can serve (for ZATCA's production gateway: the CA chain readable and a revocation list for every active certificate). 503 names the failing check. |
| `GET /api/metrics` | Prometheus text: latencies, queue depths, failed jobs, the ZATCA circuit breaker, the offline queue. Closed unless `METRICS_TOKEN` or `METRICS_ALLOWED_IPS` is set. |

ZATCA being unreachable does not fail readiness: documents wait in the
offline queue and the platform keeps serving. Day-to-day checks and incident
steps are in [the runbooks](runbooks/OPERATIONS.md).

## Reporting a problem

Send the `X-Request-Id` of a failing response, the output of
`/api/health/ready`, the ZATCA environment of the organization concerned, and
what you expected. Security issues go to the address in
[SECURITY.md](../../SECURITY.md).
