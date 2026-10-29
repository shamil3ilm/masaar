# Load test: tills on one organization

Audience: operators and engineers sizing a deployment for a busy store.

`scripts/load/tills.php` drives a running server the way a store's tills do:
many tills on one organization, each issuing simplified invoices through
`POST /api/v1/pipeline/submit` at a steady rate without waiting for the
previous answer. Invoices are issued and not filed (`auto_submit: false`),
so nothing reaches the tax authority. It is never run in CI.

```
php scripts/load/tills.php seed   --tills=10 [--limit=100000] > plan.json
php artisan serve --port=8123            # or the deployment's own server
php scripts/load/tills.php run    --plan=plan.json --url=http://127.0.0.1:8123[,http://...] --rate=5 --seconds=20 [--file=1]
php scripts/load/tills.php verify --plan=plan.json
php scripts/load/tills.php filed  --plan=plan.json      # after a --file=1 run
```

Point the script and the server at the same database, one you can throw
away: `seed` adds an organization, its tills (each with credentials of its
own, so each has its own chain and issuing lock) and an API licence, and
deletes nothing. `run` prints what was issued, the statuses and the latency
percentiles. `verify` checks every chain the run signed on: counters one
apart from 1, each document naming its predecessor's hash, one chain entry
per issued invoice.

## A pool of PHP workers, with filing through the queue

The runs below the next heading were each one PHP process. These are
several, as a deployment has, on one MySQL database:

- **Servers.** Eight PHP built-in servers (`php -S`, the same front
  controller `artisan serve` uses) with OPcache on, ports 8201-8208. The
  script shares the tills out between them (`--url` with a list), each till
  always on the same one, as a load balancer with sticky sessions would.
- **Workers.** Four `queue:work --queue=zatca-submissions,webhooks,default`
  on the database queue (`QUEUE_CONNECTION=database`, `ZATCA_QUEUE_CONNECTION`
  empty).
- **Authority.** `scripts/load/authority.php` behind `php -S`, one per worker
  (`ZATCA_SANDBOX_URL`), answering every report after 300 ms, about the real
  gateway's time. Nothing reached the tax authority.
- **Limits lifted.** The licence's calls a minute (`seed --limit=100000`) and
  the servers' `RATE_LIMIT_SUBMISSION`, `ZATCA_RATE_LIMIT_PER_MINUTE` and
  `ZATCA_DEVICE_RATE_LIMIT_PER_MINUTE` (100000 each), so what is measured is
  the platform's capacity, not the allowance a shipped deployment gives one
  store (300 a minute an organization, 120 a device).
- **Shape.** One organization, ten tills each with credentials of its own (so
  ten devices, ten chains, ten issuing locks), simplified invoices, 30 seconds
  a run.

The machine was a 16-thread Windows developer workstation, and it was not
idle: other test suites ran on it throughout, so the processor was near
full before a run began. Read the numbers as a floor and as a comparison
between the runs, not as what a dedicated server serves.

| Run | Offered | Issued a second (organization) | Issued a second (each device) | Latency p50 / p95 | Errors |
|---|---|---|---|---|---|
| Issue only | 5/s (0.5 a till) | 5.0 | 0.47-0.50 | 0.8 s / 1.3 s | 1 × 503 `SYS_008` (a deadlock outside issuing, retryable) |
| Issue only | 10/s (1 a till) | 7.0 | 0.70 | 1.3 s / 12.7 s | none |
| Issue and file | 5/s (0.5 a till) | 2.3 | 0.22-0.24 | 11.0 s / 31.9 s | 2 × 207 (issued, not queued) |

Filing in the third run: 145 of the 148 issued were reported, at 2.5 a second,
each answered 1 second after it was queued at the median and 2 at the 95th
percentile (5 at most). The other three were left `queued` with no job and no
error recorded: issued, and not handed to the queue. `fatoora:recover-submissions`
queues such a row again once it is stale; why the hand-over failed is not yet
known. Every chain verified intact after each run (`verify`).

Read it as follows:

- **The issuing request is the limit, not the queue.** Eight processes issued
  at most about seven invoices a second, roughly a second of processor time
  each (signing, the XML, the hash chain) on a busy machine. Offered more,
  requests queued at the servers and the p95 grew.
- **The database queue kept up.** Four workers drained the submissions as
  fast as they were made, a second or two behind; the queue was never the
  backlog. Filing in the same run more than halved issuing, because the
  workers and the servers shared the same processors - a deployment that
  runs its workers on their own machines, or with processors to spare, does
  not pay that.
- **Each device issued in parallel.** With credentials of its own, a till's
  rate is the organization's divided by the tills; no till waited on another.
- **A busy device is told so within seconds.** `ISSUING_LOCK_WAIT_SECONDS`
  (5) bounds the wait for a device another request holds
  (`DeviceConcurrencyTest`, `BusyDeviceTest` on MySQL); before, it was the
  database's 50 seconds.

### Redis

Not measured. Redis 5 is on the machine, but its PHP 8.4 has no Redis
client: the phpredis extension there is built for PHP 8.1, and predis is
not a dependency. Switching is configuration alone once a client is
installed - `ZATCA_QUEUE_CONNECTION=redis` (and `REDIS_*`) moves submissions
and webhook deliveries, their worker pools, heartbeat and queue metrics to
Redis, and `QUEUE_CONNECTION=redis` moves the rest - and the same script, run
with that set, measures it.

### Recommendation

Keep the database queue as the default. Measured, it drained filing a second
or two behind issuing while issuing itself was the limit, so moving the
queue would not raise what a store can issue. Size a busy deployment by PHP
processes for issuing - about one per invoice a second at these costs, more
on a shared machine - and give each till credentials of its own. Move filing
to Redis (`ZATCA_QUEUE_CONNECTION`) when the queue is measured as the
backlog: queue lag (`filed`'s queued-to-answered times) growing while the
workers are not short of processor.

## What it showed (one process)

Ten tills at five requests a second each (fifty a second offered), for
twenty seconds, against `php artisan serve` on a Windows developer machine
with MySQL:

| | Tills without credentials of their own | Tills with their own |
|---|---|---|
| Issued | 148, about 1.9 a second | 140, about 1.8 a second |
| Refused | 39 with 429, 15 with 500 | 77 with 429 |
| Latency (p50 / p95) | 36 s / 59 s | 40 s / 58 s |
| Chains | one chain (head office's), 210 documents, intact | ten chains, 14 to 17 documents each, all intact |

Read it as follows:

- **The server was the limit, not the platform.** `php artisan serve` is one
  PHP process on Windows, so requests ran one at a time at about half a
  second each, and the rest queued. That is the per-request cost, not what
  a deployment with a pool of PHP workers serves. To serve fifty a second at
  that cost a deployment needs about twenty-five workers handling issuing
  requests, and enough database connections for them.
- **The per-organization limit refuses the rest.** Fifty a second is 3,000
  a minute. The submission band allows an organization
  `RATE_LIMIT_SUBMISSION` a minute, which must agree with
  `ZATCA_RATE_LIMIT_PER_MINUTE` (both 300 as shipped, five a second), and
  each device `ZATCA_DEVICE_RATE_LIMIT_PER_MINUTE` (120). A store this busy
  is answered 429 by design; the config comments say why the ceiling sits
  under what the authority accepts. Confirm the authority's figure before
  raising both together.
- **A till needs credentials of its own to run in parallel.** A branch
  without its own signs as head office, on head office's chain and issuing
  lock, so every till waits on one device. With their own, the ten chains
  are independent.
- **The chain held under load** in both arrangements: no gap, no repeat, and
  every document named its predecessor.
- The 500s are explained and fixed; see the next section.

## The 500s, and what a busy till is told now

`php artisan serve` runs one request at a time, so on its own it cannot
show two requests contending. Five servers on one database, two tills
each, can: seed with `--shared=1` (no till credentials of its own), split
the plan's `branches` into five plans, and run one `run` per server.

The first run's fifteen bodies were not kept. Two single-server reruns on a
fresh organization answered no 500; one that reused a plan answered 500 for
every invoice number already issued, from the unique index (now 409, below).
With requests truly at once, the 500 is a deadlock in
the audit log, not in signing. Every request
writes audit entries (the draft, the issue) onto its organization's audit
hash chain, and each entry inserted the chain's head row with INSERT IGNORE
before locking it. On MySQL an ignored duplicate takes a shared lock, so two
requests of one organization each held one and each waited for the other's
to lock the row: MySQL 1213, chosen against one of them, answered by the
catch-all handler as 500. A shared device makes it likelier only because
every request of the store waits in the same queue and so writes at the same
moments. The head is now created or locked by one upsert, which locks the row
exclusively, so the second request waits for the first. Locking the row
before inserting it is not enough: tried first, it left four 500s on a fresh
organization, whose head did not exist yet, so both reads locked the gap and
both then waited to insert into it.

A request that still cannot have what it needs is answered `503 SYS_008`
with `Retry-After` as long as an issuance waits for a busy device (5 seconds,
`ISSUING_LOCK_WAIT_SECONDS`; docs/integration/ERRORS.md), never 500: a device's
lock waited out, a deadlock in issuing (tried three times first, as a
transaction of its own), or a deadlock anywhere else in the API. Nothing is
issued by it - the counter, the chain and the number sequence stay where
they were - and the request sent again under its `Idempotency-Key` issues
the draft it made, once. Sent again without a key, it meets its own number
and is answered `409 VAL_026`, where the unique index answered 500.

Five servers, two tills each at five requests a second, twenty seconds, on
one organization, MySQL, one shared licence (its 120 a minute is most of the
429s):

| | Before | After (two runs) | After, tills with their own credentials |
|---|---|---|---|
| Issued | 119 | 120, 123 | 152 |
| 5xx | 1 × 500 (1213 deadlock on `audit_chain_heads`) | none | none |
| Chain (`fatoora:verify-hash-chain`) | intact | intact | ten chains, intact |
| Audit chain (`platform:verify-audit-chain`) | intact | intact | intact |

The before run was cut short by the licence's minute limit, which answered
most requests 429 at once; the after runs reached the server more often and
queued behind the one device, hence latencies near the client's 60-second
timeout.

## The offline drain

The scheduled drain (`fatoora:process-offline`, every five minutes) sends
documents signed while the authority was unreachable. It signs nothing:
each item carries the signed document, and an item is claimed by one
conditional update, so two drains never send it twice. Fanning it out as
one queued job per device would therefore not touch the chain. It is not
done, because the drain's run-wide behaviour would change: the fair share
per organization per run, the time box that ends a run before the next is
due, and the stop when a whole round files nothing (the authority turning
documents away) are all decided across the run, not per device. A per-device
job needs those decided again, and they are worth deciding when a drain is
measured as the bottleneck; at present the live issuing path above is.

Still not built, after the pool runs above: the live path is still the
limit, and filing through the queue kept up with it. The drain itself was not
measured. By arithmetic only - one process, one item at a time, 300 ms at the
authority - a run files some 600 to 800 items in its four minutes, so a store
offline for an hour at five invoices a second (18,000) takes several runs to
catch up; each organization's share per run (1,000) is the tighter bound.
Measure a drain after a simulated outage with the stand-in authority before
fanning it out.
