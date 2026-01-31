# CompliPay Production Readiness Guide

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

**Checklist:**
- [ ] ICV allocation continues during failover (DB fallback)
- [ ] Circuit breaker state persists after failover
- [ ] Offline queue processing resumes correctly
- [ ] No duplicate ICVs issued during failover window

### 1.4 Database Failover Testing

**Test Cases:**
- [ ] Read replica promotion maintains hash chain integrity
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

- [ ] **Sandbox Testing**: Submit 100+ test invoices to ZATCA sandbox
- [ ] **Error Handling**: Verify all ZATCA error codes handled correctly
- [ ] **QR Code Validation**: Use ZATCA mobile app to scan generated QR codes
- [ ] **XML Schema Validation**: Run invoices through ZATCA's XML validator

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
- [ ] External penetration test (API endpoints)
- [ ] Internal security review (key storage, certificate handling)
- [ ] Dependency vulnerability scan
- [ ] OWASP Top 10 validation

---

## 5. Go-Live Checklist

### 5.1 Infrastructure

- [ ] Redis Sentinel/Cluster configured for HA
- [ ] Database replication configured
- [ ] Load balancer health checks configured
- [ ] SSL/TLS certificates valid and auto-renewing
- [ ] Backup verification completed

### 5.2 Application

- [ ] All migrations run successfully
- [ ] Feature flags set for production
- [ ] Kill switch tested and documented
- [ ] Rate limits configured appropriately
- [ ] Error tracking (Sentry/similar) configured

### 5.3 Monitoring

- [ ] All alerts configured and tested
- [ ] Dashboard accessible to operations team
- [ ] On-call rotation established
- [ ] Runbooks reviewed and accessible
- [ ] Log aggregation configured

### 5.4 Compliance

- [ ] ZATCA production credentials configured
- [ ] Certificate lineage tracking initialized
- [ ] Hash chain state initialized
- [ ] Audit logging verified
- [ ] Data retention policies configured

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

## Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-01-31 | CompliPay Team | Initial release |

**Last Updated**: January 31, 2026
