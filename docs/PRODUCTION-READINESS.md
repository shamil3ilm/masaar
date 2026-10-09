# Masaar Production Readiness Guide

This document outlines pre-production validation steps, stress testing scenarios, and operational tuning recommendations.

---

## 1. Stress Testing & Chaos Engineering

### 1.1 High-Volume Invoice Burst Testing

```bash
# Scenario: 1000 invoices/minute across 10 organizations
# Test: ICV atomicity under concurrent load

# Using Apache Bench or k6
k6 run --vus 100 --duration 5m stress-test-invoices.js
```

**Test Cases:**
| Scenario | Expected Behavior | Validation |
|----------|------------------|------------|
| 100 concurrent invoice submissions | All ICVs unique, sequential per org | Query `SELECT organization_id, COUNT(DISTINCT icv) FROM invoices GROUP BY organization_id` |
| Redis failure during ICV allocation | Automatic fallback to DB transactions | Kill Redis, verify invoices continue with DB-level atomicity |
| Millisecond-burst (10 invoices in 1ms) | Microsecond timestamps differentiate | Check `AtomicIcvManager` timestamps differ |

### 1.2 Multi-DC / Network Partition Simulation

```bash
# Simulate network partition using iptables or tc
# Node A cannot reach Node B, but both can reach DB/Redis

# Test circuit breaker propagation
tc qdisc add dev eth0 root netem delay 500ms 200ms
```

**Test Cases:**
| Scenario | Expected Behavior | Validation |
|----------|------------------|------------|
| DC-A loses Redis connectivity | Circuit breaker opens on DC-A only initially | Check `ClusterCircuitBreaker::getClusterHealth()` |
| Redis Pub/Sub partition | State changes propagate when connection restored | Monitor `circuit_breaker:events` channel |
| Split-brain ICV allocation | DB unique constraint prevents duplicates | Attempt duplicate ICV insert, expect error |

### 1.3 Redis Failover Testing

```bash
# Test Redis Sentinel/Cluster failover
redis-cli -p 26379 SENTINEL failover mymaster
```

**Checklist** — these need a real failover drill against infrastructure, which
no test in this repository can stand in for. What the suite does establish
beforehand is listed against each, so a drill that fails tells you it is the
infrastructure and not the logic:

- [ ] ICV allocation continues during failover (DB fallback) — allocation under
      contention is covered by `tests/Feature/Invoice/IcvAllocationTest.php`
- [ ] Circuit breaker state persists after failover — state transitions by
      `tests/Feature/Compliance/CircuitBreakerTest.php`
- [ ] Offline queue processing resumes correctly — drain and retry by
      `tests/Feature/Compliance/OfflineFallbackTest.php`
- [ ] No duplicate ICVs issued during failover window — uniqueness under
      concurrent allocation by `IcvAllocationTest` and
      `tests/Feature/Compliance/SubmissionRaceTest.php`

### 1.4 Database Failover Testing

**Test Cases** — again a drill, with the logic covered beforehand:

- [ ] Read replica promotion maintains hash chain integrity — a fork in the
      chain is detected by `tests/Feature/Compliance/ChainForkTest.php`
- [ ] Pending transactions rollback cleanly
- [ ] Lock ownership tokens invalidate correctly

---

## 2. Long-Term Data Retention & Archival

### 2.1 Retention Requirements

| Data Type | Retention Period | Regulation |
|-----------|-----------------|------------|
| Invoices (cleared) | 7 years minimum | Saudi tax law |
| Hash chain history | 7 years minimum | Audit trail requirement |
| Certificate lineage | 10 years | Cryptographic audit |
| Audit logs | 7 years | Compliance requirement |

### 2.2 Archival Verification Tests

**Quarterly Verification (Automated):**
```php
// Run monthly to verify archived data remains queryable
$reconstructor = new ArchivedTenantReconstructor();

// Test oldest tenant
$oldestOrg = Organization::where('status', 'archived')
    ->orderBy('created_at')
    ->first();

$result = $reconstructor->listOrganizationInvoices(
    $oldestOrg->id,
    'system_verification',
    'Quarterly archival verification'
);

assert($result['success'] === true);
assert($result['total_invoices'] > 0);
```

**Annual Deep Verification:**
```php
// Verify hash chain reconstruction for all archived tenants
$archivedOrgs = Organization::where('status', 'archived')->get();

foreach ($archivedOrgs as $org) {
    $chain = $reconstructor->reconstructHashChain(
        $org->id,
        'annual_audit',
        'Annual compliance verification'
    );

    if (!$chain['integrity']['intact']) {
        alert("Hash chain integrity failure for {$org->id}");
    }
}
```

### 2.3 Storage Tiering Recommendations

| Age | Storage Tier | Access Pattern |
|-----|--------------|----------------|
| 0-1 year | Hot (SSD) | Frequent queries |
| 1-3 years | Warm (HDD) | Occasional queries |
| 3-7 years | Cold (Archive) | Rare, audit-only |

**Implementation Notes:**
- Use database partitioning by `issue_date` year
- Consider read replicas for archival queries
- Never delete; only archive to cold storage

---

## 3. Monitoring & Alert Tuning

### 3.1 Recommended Alert Thresholds

| Metric | Warning | Critical | Notes |
|--------|---------|----------|-------|
| Queue stuck items | 10 items > 30min | 50 items > 30min | Adjust based on volume |
| Queue growth rate | 100/hour | 500/hour | Relative to processing capacity |
| Retry exhaustion | 5 items/hour | 20 items/hour | May indicate systemic issue |
| Circuit breaker open | Any service | N/A | Always alert |
| Hash chain anomaly | Any warning | Any critical | Investigate immediately |
| Certificate expiry | 30 days | 7 days | Auto-renewal should prevent |

### 3.2 Alert Fatigue Mitigation

**Cooldown Configuration:**
```php
// QueueHealthMonitor cooldowns
private const ALERT_COOLDOWNS = [
    'stuck_items' => 30,      // minutes
    'retry_exhaustion' => 60, // minutes
    'queue_growth' => 15,     // minutes
    'processing_rate' => 30,  // minutes
    'silent_failures' => 60,  // minutes
];
```

**Aggregation Rules:**
- Group related alerts (e.g., multiple queue failures → single "queue health degraded")
- Use severity escalation (warning → critical after persistence)
- Implement "flapping" detection (rapid open/close cycles)

### 3.3 Dashboard Metrics

**Real-Time Dashboard:**
```
┌─────────────────────────────────────────────────────────┐
│ ZATCA Compliance Status                                 │
├─────────────────────────────────────────────────────────┤
│ Circuit Breaker: [CLOSED] ✓                            │
│ Queue Depth: 23 pending | 5 retrying | 0 exhausted     │
│ Processing Rate: 142/hour (target: 100+)               │
│ Hash Chain: Healthy (last scan: 2 hours ago)           │
│ Certificates: 45 days until next expiry                │
└─────────────────────────────────────────────────────────┘
```

### 3.4 Runbook Integration

Each alert should link to a runbook:

| Alert | Runbook |
|-------|---------|
| Circuit breaker open | `runbooks/circuit-breaker-open.md` |
| Queue exhaustion | `runbooks/queue-exhaustion.md` |
| Hash chain anomaly | `runbooks/hash-chain-investigation.md` |
| Key compromise suspected | `runbooks/key-compromise-response.md` |

---

## 4. Regulatory Confirmation Checklist

### 4.1 Pre-Production ZATCA Validation

- [ ] **Sandbox Testing**: Submit 100+ test invoices to ZATCA sandbox. The
      `sandbox` job in `.github/workflows/ci.yml` runs the round trip nightly
      and on request - CSR, CCSID, the six compliance documents, PCSID - and
      needs no credentials, so this can start today. It is six documents, not a
      hundred, so volume is still to do.
- [ ] **Error Handling**: Verify all ZATCA error codes handled correctly.
      `ErrorCode` enumerates 99 of them with a retryable flag and a category,
      and `SubmissionTracker` schedules the next attempt from its retry delay. What is not
      established is that the codes the authority actually returns are the ones
      enumerated, which only live traffic shows.
- [ ] **QR Code Validation**: Use ZATCA mobile app to scan generated QR codes.
      Yours - the TLV and its tags are checked by the SDK and by
      `ZatcaConformanceTest`, but only a phone proves the app reads it.
- [x] **XML Schema Validation**: ZATCA's own SDK validator runs over generated
      documents in `ZatcaConformanceTest` - UBL 2.1 schema, EN 16931, Schematron.
      Standard documents pass outright; simplified documents have one signature
      digest outstanding, see `docs/sa/HASHING-AND-SIGNING.md`.

### 4.2 Production Onboarding

- [ ] **CSID Enrollment**: Complete CCSID → PCSID enrollment
- [ ] **Certificate Verification**: Verify production certificates with ZATCA
- [ ] **First Invoice Test**: Submit first production invoice with monitoring
- [ ] **Clearance Verification**: Confirm clearance status in ZATCA portal

### 4.3 Documentation for Auditors

Prepare the following for regulatory audits:

| Document | Location | Purpose |
|----------|----------|---------|
| System Architecture | `docs/ARCHITECTURE.md` | Technical overview |
| Compliance Policies | `docs/COMPLIANCE-POLICIES.md` | Policy decisions |
| Data Flow Diagrams | `docs/DATA-FLOW.md` | Invoice lifecycle |
| Security Controls | `SECURITY.md` | Security measures |
| Audit Log Schema | `docs/AUDIT-SCHEMA.md` | Log interpretation |

### 4.4 Penetration Testing Requirements

Before production:
- [ ] External penetration test (API endpoints) — yours
- [ ] **Internal security review (key storage, certificate handling)** — one
      finding is already known and recorded in `CredentialStore`'s own
      docblock: one secret covers every tenant, and on a container-local disk a
      tenant onboarded on one replica cannot be signed for by another. Adequate
      for a single taxpayer; a blocker before a second one is onboarded.
- [x] **Dependency vulnerability scan** — `composer audit --locked` runs as the
      `security` job in CI on every push, and is currently clean.
- [ ] **OWASP Top 10 validation** — partly covered: `tests/Feature/Security`
      sweeps the router for unguarded routes and proves tenant scoping holds,
      and rate limiting is now enforced (5.2). Not a substitute for the
      external test above.

---

## 5. Go-Live Checklist

### 5.1 Infrastructure

Yours — none of it lives in this repository. `docker-compose.prod.yml`,
`docker/nginx` and `docker/supervisor` are the starting point; the supervisor
config already runs php-fpm, nginx, two default workers, three
`zatca-submissions` workers, one `webhooks` worker and the scheduler.

- [ ] Redis Sentinel/Cluster configured for HA
- [ ] Database replication configured
- [ ] Load balancer health checks configured — the endpoint exists and is
      exempt from the platform licence gate, so it answers before a key is issued
- [ ] SSL/TLS certificates valid and auto-renewing
- [ ] Backup verification completed

### 5.2 Application

- [x] **All migrations run successfully** — CI runs the suite against SQLite
      and again against MySQL 8.4, so a migration that only works on one driver
      fails the build. See the `mysql` job in `.github/workflows/ci.yml`.
- [ ] Feature flags set for production
- [x] **Kill switch tested** — `tests/Feature/Compliance/KillSwitchTest.php`.
      Still to do: a runbook entry saying who may throw it and what it stops.
- [x] **Rate limits configured and enforced** — `RateLimitApi` is attached to
      the api middleware group in `bootstrap/app.php` and reads
      `config/security.php`. It was aliased and attached to nothing until
      2026-10-09, so the whole policy was inert and `/api/auth/login` had no
      limit at all; `tests/Feature/Security/AuthThrottleTest.php` holds that
      shut.

      The bands are ordered by what a request costs - onboarding 5, anonymous
      20, submission 120, default 300, read 600 - so a band is never tighter
      than the fallback its traffic would otherwise drop through to, which
      `RateLimitConfigTest` asserts. `RATE_LIMIT_SUBMISSION` is 120 to make
      section 1.1's thousand-a-minute target reachable across ten
      organizations, and it is a platform ceiling rather than an ambition: a
      limit above what ZATCA's API accepts does not buy throughput, it moves
      the refusal from a cheap local 429 to a failed submission against the
      24-hour reporting deadline. **Confirm the authority's published figure
      and keep this under it.**

      **An existing deployment needs two lines changed in its `.env`**, because
      the values there override these defaults and the old ones were
      incoherent: `RATE_LIMIT_SUBMISSION=120` and
      `ZATCA_RATE_LIMIT_PER_MINUTE=120`. They throttle the same traffic and the
      lower one silently decides, so `AppServiceProvider` refuses to boot in
      production while they disagree and logs a warning elsewhere.
- [x] **Errors are identifiable** — `LogContext` shares a correlation id, the
      organization, the user and the matched route with every log line written
      during a request, and returns the id as `X-Request-Id` so a customer
      reporting a problem can quote it. Until 2026-10-09 an unhandled exception
      reached the log as a message and a stack trace with no tenant on it,
      which on a deployment serving several taxpayers is close to
      unactionable - and no aggregator fixes that, since it can only group what
      it is given. Identifiers only, deliberately: a VAT or invoice number
      would make the log a copy of the data it describes (see `LogSanitizer`).
- [ ] **Errors are aggregated and alert someone** — still open, and it is a
      procurement decision rather than a code one. The `slack` and
      `papertrail` channels already exist in `config/logging.php` and need only
      to be named in `LOG_STACK`; a hosted tracker (Sentry, Bugsnag) would be
      a new dependency.

      **Weigh data residency before choosing.** An exception payload from this
      platform can carry invoice context, which is Saudi tax data. Shipping it
      to a tracker outside the Kingdom is a question for whoever owns your
      ZATCA and data-protection obligations, not a default. A self-hosted
      collector, or the existing channels pointed at infrastructure you
      control, avoids the question entirely.

### 5.3 Monitoring

- [ ] All alerts configured and tested
- [ ] Dashboard accessible to operations team
- [ ] On-call rotation established
- [ ] Runbooks reviewed and accessible
- [ ] Log aggregation configured

### 5.4 Compliance

- [ ] **ZATCA production credentials configured** — the hard blocker, and it
      needs a registered taxpayer: a 15-digit VAT number, the legal
      organization name, the 10-digit TIN for the certificate request's
      organization unit, and an OTP from the Fatoora portal. Run the sandbox
      round trip first (`.github/workflows/ci.yml`, job `sandbox`, no
      credentials needed), then simulation, then production.
- [ ] Certificate lineage tracking initialized
- [x] **Hash chain state initialized** — the genesis PIH is
      `FatooraConfig::GENESIS_PIH`, asserted to be declared exactly once, and
      the chain is covered by `ChainRecordTest` and `ChainForkTest`.
- [x] **Audit logging verified** — `tests/Feature/Security/SecurityAuditTest.php`.
- [x] **Data retention policies configured** — `PartitionMaintenance` creates
      partitions ahead and detaches those past seven years;
      `CleanupOfflineQueue` prunes the offline queue. Both are scheduled in
      `routes/console.php`.

---

## 6. Chaos Engineering Scenarios

### 6.1 Game Day Exercises

Run these quarterly:

| Exercise | Duration | Participants |
|----------|----------|--------------|
| Redis failure | 30 min | Engineering + Ops |
| ZATCA API outage | 1 hour | Engineering + Support |
| Certificate emergency rotation | 1 hour | Security + Engineering |
| Multi-DC failover | 2 hours | All teams |

### 6.2 Automated Chaos Tests

```yaml
# chaos-mesh or similar configuration
experiments:
  - name: redis-partition
    selector:
      app: redis
    action: partition
    duration: 5m

  - name: zatca-latency
    selector:
      app: zatca-client
    action: delay
    latency: 10s
    duration: 10m
```

---

## 7. Performance Baselines

### 7.1 Expected Performance

| Operation | P50 | P95 | P99 |
|-----------|-----|-----|-----|
| Invoice creation | 50ms | 150ms | 300ms |
| XML generation | 20ms | 50ms | 100ms |
| Signature creation | 30ms | 80ms | 150ms |
| ZATCA submission | 500ms | 2s | 5s |
| ICV allocation | 5ms | 15ms | 30ms |

### 7.2 Capacity Planning

| Metric | Current Capacity | Scale Trigger |
|--------|-----------------|---------------|
| Invoices/minute | 500 | > 400 sustained |
| Queue depth | 10,000 | > 5,000 sustained |
| Storage growth | 10GB/month | > 8GB/month |

---

## 8. Index Health Monitoring

### 8.1 Slow Burn Failure Prevention

**Problem**: No spike, no outage, just slow steady growth. Eventually DB indexes degrade, hash chain queries slow, audits time out.

### 8.2 Critical Tables to Monitor

| Table | Query Pattern | Risk |
|-------|---------------|------|
| `hash_chain_history` | Range scans by org+icv | Chain verification slows |
| `audit_logs` | Time-range queries | Compliance queries timeout |
| `invoices` | Complex filters | Reporting degrades |
| `invoice_submissions` | Status lookups | Dashboard slows |

### 8.3 Metrics to Track

```sql
-- Query latency by table (PostgreSQL)
SELECT
    schemaname,
    relname,
    seq_scan,
    idx_scan,
    n_tup_ins,
    n_tup_upd,
    n_tup_del
FROM pg_stat_user_tables
WHERE relname IN ('hash_chain_history', 'audit_logs', 'invoices', 'invoice_submissions')
ORDER BY seq_scan DESC;

-- Index usage (PostgreSQL)
SELECT
    indexrelname,
    idx_scan,
    idx_tup_read,
    idx_tup_fetch
FROM pg_stat_user_indexes
WHERE schemaname = 'public'
ORDER BY idx_scan DESC;
```

### 8.4 Alert Thresholds

| Metric | Warning | Critical | Action |
|--------|---------|----------|--------|
| P95 query latency (hash_chain_history) | > 100ms | > 500ms | Analyze indexes |
| P95 query latency (audit_logs) | > 200ms | > 1s | Consider partitioning |
| Sequential scans / hour | > 1000 | > 5000 | Add missing index |
| Table bloat | > 20% | > 50% | VACUUM ANALYZE |
| Index bloat | > 30% | > 60% | REINDEX |

### 8.5 Monitoring Queries

**MySQL - Slow Query Detection**:
```sql
SELECT
    query,
    exec_count,
    avg_latency,
    max_latency
FROM sys.statement_analysis
WHERE query LIKE '%hash_chain%' OR query LIKE '%audit_log%'
ORDER BY avg_latency DESC
LIMIT 20;
```

**PostgreSQL - Index Health**:
```sql
SELECT
    schemaname || '.' || relname AS table,
    indexrelname AS index,
    pg_size_pretty(pg_relation_size(indexrelid)) AS index_size,
    idx_scan AS scans,
    idx_tup_read AS tuples_read,
    idx_tup_fetch AS tuples_fetched
FROM pg_stat_user_indexes
JOIN pg_index USING (indexrelid)
WHERE NOT indisunique
ORDER BY idx_scan ASC
LIMIT 20;  -- Least used indexes
```

### 8.6 Automated Health Check

```php
// App\Console\Commands\IndexHealthCheck.php
class IndexHealthCheck extends Command
{
    protected $signature = 'compliance:index-health';

    public function handle()
    {
        $tables = ['hash_chain_history', 'audit_logs', 'invoices'];

        foreach ($tables as $table) {
            $stats = DB::select("EXPLAIN ANALYZE SELECT * FROM {$table} WHERE created_at > NOW() - INTERVAL '1 day' LIMIT 100");

            // Parse execution time
            $executionTime = $this->parseExecutionTime($stats);

            if ($executionTime > 100) { // ms
                Log::warning("Slow query detected on {$table}", [
                    'execution_time_ms' => $executionTime,
                    'table' => $table,
                ]);

                // Alert if critical
                if ($executionTime > 500) {
                    $this->alertOps("Critical: {$table} queries exceeding 500ms");
                }
            }
        }
    }
}
```

### 8.7 Preventive Maintenance Schedule

| Task | Frequency | Command |
|------|-----------|---------|
| ANALYZE tables | Daily | `ANALYZE hash_chain_history, audit_logs, invoices;` |
| Check index bloat | Weekly | Custom monitoring query |
| VACUUM ANALYZE | Weekly | `VACUUM ANALYZE;` |
| REINDEX (if needed) | Monthly | `REINDEX TABLE CONCURRENTLY table_name;` |
| Table partitioning review | Quarterly | Manual review |

### 8.8 Partitioning Strategy (Future)

For tables exceeding 100M rows:

```sql
-- Partition audit_logs by month
CREATE TABLE audit_logs (
    id UUID,
    created_at TIMESTAMP,
    ...
) PARTITION BY RANGE (created_at);

CREATE TABLE audit_logs_2026_01 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-01-01') TO ('2026-02-01');

CREATE TABLE audit_logs_2026_02 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-02-01') TO ('2026-03-01');
```

---

## Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-01-31 | Masaar Team | Initial release |
| 1.1 | 2026-01-31 | Masaar Team | Added index health monitoring section |

**Last Updated**: January 31, 2026
