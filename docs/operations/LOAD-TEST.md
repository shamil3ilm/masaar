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
- The 500s appeared only with the shared head-office device and were not
  reproduced with tills of their own. Their cause was not established.

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
