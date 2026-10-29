# Load test: tills on one organization

Audience: operators and engineers sizing a deployment for a busy store.

`scripts/load/tills.php` drives a running server the way a store's tills do:
many tills on one organization, each issuing simplified invoices through
`POST /api/v1/pipeline/submit` at a steady rate without waiting for the
previous answer. Invoices are issued and not filed (`auto_submit: false`),
so nothing reaches the tax authority. It is never run in CI.

```
php scripts/load/tills.php seed   --tills=10 > plan.json
php artisan serve --port=8123            # or the deployment's own server
php scripts/load/tills.php run    --plan=plan.json --url=http://127.0.0.1:8123 --rate=5 --seconds=20
php scripts/load/tills.php verify --plan=plan.json
```

Point the script and the server at the same database, one you can throw
away: `seed` adds an organization, its tills (each with credentials of its
own, so each has its own chain and issuing lock) and an API licence, and
deletes nothing. `run` prints what was issued, the statuses and the latency
percentiles. `verify` checks every chain the run signed on: counters one
apart from 1, each document naming its predecessor's hash, one chain entry
per issued invoice.

## What it showed

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
with `Retry-After: 2` (docs/integration/ERRORS.md), never 500: a device's
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
