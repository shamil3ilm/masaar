# TaxFly Partnership Strategy

Strategic framework for a temporary merger (18-24 months) with clean exit to independence.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Technical Integration Architecture](#2-technical-integration-architecture)
3. [ZATCA Regulatory Compliance](#3-zatca-regulatory-compliance)
4. [Key Contract Terms](#4-key-contract-terms)
5. [Timeline & Milestones](#5-timeline--milestones)
6. [Exit Playbook](#6-exit-playbook)
7. [Contract Model](#7-contract-model)

---

## 1. Executive Summary

### Strategy
Partner with TaxFly for 18-24 months to gain market access, revenue, and credibility, then exit to operate independently while potentially maintaining TaxFly as a paying customer.

### Core Principles
1. **IP Protection** - Masaar core remains separate and owned by you
2. **Brand Visibility** - "Powered by Masaar" builds exit runway
3. **Clean Boundaries** - Technical separation enables painless exit
4. **Data Rights** - Customer relationships portable at exit
5. **Fair Exit** - Both parties benefit from the partnership and separation

---

## 2. Technical Integration Architecture

### 2.1 High-Level Architecture

```
┌────────────────────────────────────────────────────────────────────┐
│                        TaxFly Platform                              │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                    TaxFly Application                        │   │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐  │   │
│  │  │  Laravel 8  │  │  Invoice UI │  │  Business Logic     │  │   │
│  │  │  PHP 7.4    │  │  Dashboard  │  │  Customer Mgmt      │  │   │
│  │  └─────────────┘  └─────────────┘  └─────────────────────┘  │   │
│  │                                                              │   │
│  │  ┌─────────────────────────────────────────────────────────┐│   │
│  │  │              TaxFly Adapter Layer                       ││   │
│  │  │  - Maps TaxFly models to Masaar API format           ││   │
│  │  │  - Handles webhook reception                            ││   │
│  │  │  - Manages credential storage                           ││   │
│  │  │  - THIS CODE BELONGS TO TAXFLY                          ││   │
│  │  └───────────────────────┬─────────────────────────────────┘│   │
│  └──────────────────────────┼──────────────────────────────────┘   │
│                             │                                       │
│                             │ HTTPS API (Standard Masaar API)    │
│                             │                                       │
│  ┌──────────────────────────▼──────────────────────────────────┐   │
│  │              Masaar Compliance Engine                     │   │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐  │   │
│  │  │  Laravel 12 │  │  ZATCA API  │  │  XAdES-BES Signing  │  │   │
│  │  │  PHP 8.4    │  │  Integration│  │  QR Generation      │  │   │
│  │  └─────────────┘  └─────────────┘  └─────────────────────┘  │   │
│  │                                                              │   │
│  │  THIS IS YOUR IP - DEPLOYED AS SEPARATE SERVICE              │   │
│  │  Same API any other customer would use                       │   │
│  └──────────────────────────────────────────────────────────────┘   │
│                                                                     │
└────────────────────────────────────────────────────────────────────┘
```

### 2.2 Deployment Models

#### Option A: Separate Containers (Recommended)

```yaml
# docker-compose.yml (TaxFly's infrastructure)
version: '3.8'

services:
  taxfly-app:
    image: taxfly/app:latest
    environment:
      COMPLIPAY_BASE_URL: http://masaar:8000
      COMPLIPAY_API_KEY: ${COMPLIPAY_API_KEY}
    depends_on:
      - masaar
    networks:
      - taxfly-network

  masaar:
    image: masaar/engine:latest  # Your image, pulled from your registry
    environment:
      DB_CONNECTION: mysql
      DB_HOST: masaar-db
      ZATCA_ENVIRONMENT: ${ZATCA_ENVIRONMENT}
    volumes:
      - masaar-keys:/app/storage/keys  # Encrypted key storage
    networks:
      - taxfly-network
    # NO direct external access - only through TaxFly

  masaar-db:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: masaar
      MYSQL_ROOT_PASSWORD: ${COMPLIPAY_DB_PASSWORD}
    volumes:
      - masaar-data:/var/lib/mysql
    networks:
      - taxfly-network

networks:
  taxfly-network:
    driver: bridge

volumes:
  masaar-keys:
  masaar-data:
```

**Why This Model:**
- Your code runs in your container (image from your registry)
- You control updates and versioning
- Clean separation of databases
- Easy to "unplug" at exit

#### Option B: Managed API Service

```
┌─────────────────────┐         ┌─────────────────────┐
│   TaxFly Servers    │  HTTPS  │  Masaar Cloud    │
│   (Their infra)     │◄───────►│  (Your infra)       │
└─────────────────────┘         └─────────────────────┘
```

- You host Masaar on your own infrastructure
- TaxFly connects via public API
- Even cleaner separation
- You maintain full control
- Easier exit (just revoke API key or transition to paid)

### 2.3 API Contract

TaxFly uses the **standard Masaar API** - no special endpoints:

```php
<?php
// TaxFly's adapter - THEIR code, THEIR responsibility

namespace TaxFly\Services;

use GuzzleHttp\Client;

class MasaarAdapter
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => config('services.masaar.url'),
            'headers' => [
                'X-API-Key' => config('services.masaar.key'),
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Submit TaxFly invoice to Masaar for ZATCA compliance
     */
    public function submitInvoice(TaxFlyInvoice $invoice): array
    {
        // Map TaxFly format to Masaar format
        $payload = $this->mapToMasaarFormat($invoice);

        // Use standard Masaar API
        $response = $this->http->post('/api/invoices', [
            'json' => $payload
        ]);

        return json_decode($response->getBody(), true);
    }

    /**
     * Mapping logic - TaxFly's responsibility to maintain
     */
    private function mapToMasaarFormat(TaxFlyInvoice $invoice): array
    {
        return [
            'invoice_number' => $invoice->invoice_number,
            'type' => $invoice->isB2B() ? 'standard' : 'simplified',
            'issue_date' => $invoice->invoice_date->format('Y-m-d'),
            'buyer_name' => $invoice->customer_name,
            'buyer_vat_number' => $invoice->customer_vat,
            'lines' => $invoice->items->map(fn($item) => [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'tax_rate' => $item->vat_rate,
            ])->toArray(),
        ];
    }
}
```

### 2.4 What NOT To Do

| Don't | Why | Instead |
|-------|-----|---------|
| Merge codebases | Can't separate later | Keep as separate services |
| Give them repo access | They could fork | Provide Docker images only |
| Build TaxFly-specific features in Masaar | Clutters your core | They build adapters |
| Share database | Data entanglement | Separate DBs, API boundary |
| Use their auth system | Dependency | Masaar has own auth |
| Custom endpoints for TaxFly | Creates lock-in | Standard API only |

### 2.5 Branding Integration

```html
<!-- TaxFly's invoice PDF template -->
<div class="invoice-footer">
    <div class="qr-code">
        {{ $invoice->zatca_qr_code }}
    </div>
    <div class="compliance-badge">
        <img src="/images/masaar-badge.svg" alt="Powered by Masaar" />
        <span>ZATCA Phase 2 Compliant</span>
    </div>
</div>
```

```html
<!-- TaxFly's dashboard -->
<div class="zatca-status-widget">
    <h3>ZATCA Compliance</h3>
    <p>Powered by <a href="https://masaar.sa">Masaar</a></p>
    <!-- Stats from Masaar API -->
</div>
```

**Minimum Brand Visibility:**
- "Powered by Masaar" on invoice PDFs
- Masaar mention in ZATCA status screens
- Link to Masaar in documentation
- Co-marketing in press releases

---

## 3. ZATCA Regulatory Compliance

### 3.1 Overview

This section outlines ZATCA regulatory requirements that directly impact the partnership structure, hosting decisions, and contractual obligations.

> **CRITICAL**: Masaar has not yet been hosted. All infrastructure decisions should be made with these requirements in mind from the start.

### 3.2 Third-Party Provider Rules

**ZATCA explicitly allows third-party solution providers:**

> "Taxpayers have the option to get E-invoicing services from any company, as long as the Solution used by the taxpayer complies to E-invoicing requirements."

**Key implications:**
- Masaar CAN operate as TaxFly's compliance provider ✓
- ZATCA maintains a [Solution Providers Directory](https://zatca.gov.sa/en/E-Invoicing/SolutionProviders/Pages/SolutionProvidersDirectory.aspx) but listing is **optional**
- **TaxFly remains legally responsible** for compliance even when using Masaar
- Masaar should consider applying for directory listing (marketing benefit)

### 3.3 Data Residency Requirements

**MANDATORY: Invoice data must be stored within Saudi Arabia**

| Requirement | Details | Source |
|-------------|---------|--------|
| **Storage Location** | Digital invoices must be archived on servers within Saudi Arabia | ZATCA Guidelines |
| **Cloud Allowed** | Yes, but must comply with NCA (National Cybersecurity Authority) regulations | ZATCA Guidelines |
| **Accessibility** | Data must be available via direct link shareable with ZATCA on demand | ZATCA Guidelines |
| **Retention Period** | **Minimum 6 years** (11 years for certain services) | ZATCA Guidelines |

### 3.4 Hosting Architecture Options

Given that Masaar is not yet hosted, the partnership must decide on infrastructure:

#### Option A: Full Saudi Hosting (Recommended)

```
┌─────────────────────────────────────────────────────────────────┐
│                    SAUDI ARABIA DATA CENTER                     │
│         (AWS me-south-1 / Azure UAE / Local DC)                 │
│                                                                 │
│  ┌─────────────────────┐      ┌─────────────────────────────┐  │
│  │    TaxFly App       │      │     Masaar Engine        │  │
│  │    (Laravel 8)      │◄────►│     (Laravel 12)            │  │
│  └─────────────────────┘      └─────────────────────────────┘  │
│                                                                 │
│  ┌─────────────────────┐      ┌─────────────────────────────┐  │
│  │   TaxFly Database   │      │   Masaar Database        │  │
│  │   (Customer data)   │      │   (Compliance records)      │  │
│  └─────────────────────┘      └─────────────────────────────┘  │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │              Shared Archive Storage                      │   │
│  │         (6+ years invoice retention)                     │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘

✓ Fully compliant with data residency
✓ Simplest regulatory path
✓ Single jurisdiction
```

**Saudi Hosting Options:**
| Provider | Region | Notes |
|----------|--------|-------|
| AWS | me-south-1 (Bahrain) | Closest AWS region, accepted for Saudi |
| Azure | UAE North | Microsoft's Middle East presence |
| Alibaba Cloud | Saudi Arabia | Local data center |
| STC Cloud | Saudi Arabia | Local telecom provider |
| Mobily Cloud | Saudi Arabia | Local telecom provider |

#### Option B: Processing Outside, Storage Inside

```
┌─────────────────────────────────────────────────────────────────┐
│                    SAUDI ARABIA                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                  TaxFly Infrastructure                   │   │
│  │  • TaxFly Application                                    │   │
│  │  • Invoice Storage (6+ years)                            │   │
│  │  • Signed XML Archive                                    │   │
│  │  • Compliance Records                                    │   │
│  └────────────────────────────┬────────────────────────────┘   │
└───────────────────────────────┼─────────────────────────────────┘
                                │ API (transient data only)
┌───────────────────────────────▼─────────────────────────────────┐
│                    EXTERNAL (Any Region)                        │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                  Masaar Processing                    │   │
│  │  • XML Generation                                        │   │
│  │  • Digital Signing                                       │   │
│  │  • QR Code Generation                                    │   │
│  │  • ZATCA API Submission                                  │   │
│  │                                                          │   │
│  │  ⚠️  NO PERSISTENT STORAGE                               │   │
│  │  • Transient processing only                             │   │
│  │  • Data deleted within 24-48 hours                       │   │
│  │  • Only logs retained (anonymized)                       │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘

⚠️ Potentially compliant IF:
   • No invoice data persisted outside Saudi Arabia
   • All archival happens on TaxFly's Saudi infrastructure
   • Clear data flow documentation for auditors
```

#### Option C: TaxFly Hosts Everything (Their Infrastructure)

```
┌─────────────────────────────────────────────────────────────────┐
│              TAXFLY'S SAUDI INFRASTRUCTURE                      │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  TaxFly Platform + Masaar (containerized)            │   │
│  │                                                          │   │
│  │  • TaxFly manages all servers                            │   │
│  │  • Masaar deployed as Docker container                │   │
│  │  • Single compliance jurisdiction                        │   │
│  │  • TaxFly bears infrastructure responsibility            │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘

✓ Fully compliant
✓ Masaar has no infrastructure cost
✓ TaxFly controls everything
⚠️ Masaar dependent on TaxFly for hosting
⚠️ Harder to exit (need to set up own infrastructure)
```

### 3.5 Recommended Approach

**For this partnership, recommend Option C (TaxFly Hosts) initially, with planned migration to Option A:**

**Phase 1 (Month 1-12): TaxFly Hosts**
- Deploy Masaar container on TaxFly's Saudi infrastructure
- Zero infrastructure cost for Masaar
- Fast time to market
- Focus on product, not DevOps

**Phase 2 (Month 12-18): Build Independence**
- Masaar establishes own Saudi hosting
- Begin migration planning
- Maintain TaxFly as customer via API

**Phase 3 (Month 18-24): Full Independence**
- Masaar runs on own infrastructure
- TaxFly transitions to standard API customer
- Clean separation complete

### 3.6 Retention & Archival Requirements

| Data Type | Retention Period | Storage Location | Format |
|-----------|-----------------|------------------|--------|
| Signed XML invoices | 6 years minimum | Saudi Arabia | XML (UBL 2.1) |
| QR codes | 6 years minimum | Saudi Arabia | Base64/Image |
| ZATCA submission logs | 6 years minimum | Saudi Arabia | JSON |
| Audit trails | 6 years minimum | Saudi Arabia | Database |
| Cryptographic certificates | Until expiry + 6 years | Saudi Arabia | PEM (encrypted) |
| Customer PII | Per privacy policy | Saudi Arabia | Encrypted |

### 3.7 NCA Compliance Requirements

If using cloud storage, must comply with National Cybersecurity Authority regulations:

- **Data Classification**: Classify invoice data appropriately
- **Encryption**: Data encrypted at rest and in transit
- **Access Control**: Role-based access, audit logging
- **Incident Response**: Documented breach procedures
- **Vendor Assessment**: Cloud provider security certification

### 3.8 ZATCA Audit Readiness

Both parties must be prepared for ZATCA audits:

| Requirement | Responsibility |
|-------------|---------------|
| Provide unrestricted data access | TaxFly (as taxpayer's agent) |
| Generate compliance reports | Masaar |
| Explain technical implementation | Masaar |
| Provide business justification | TaxFly |
| Historical invoice retrieval | Joint |

### 3.9 Regulatory Compliance Checklist

Before launch, ensure:

- [ ] Hosting infrastructure located in Saudi Arabia (or compliant architecture)
- [ ] Data retention policy documented (6+ years)
- [ ] NCA compliance review completed
- [ ] ZATCA Solution Provider directory application submitted (optional but recommended)
- [ ] Audit access procedures documented
- [ ] Data export capability verified
- [ ] Backup and disaster recovery in Saudi jurisdiction
- [ ] Encryption at rest and in transit enabled
- [ ] Access control and audit logging implemented

---

## 4. Key Contract Terms

### 4.1 Intellectual Property

```
┌─────────────────────────────────────────────────────────────────┐
│                    IP OWNERSHIP MATRIX                          │
├─────────────────────────────────┬───────────────────────────────┤
│          COMPLIPAY OWNS         │         TAXFLY OWNS           │
├─────────────────────────────────┼───────────────────────────────┤
│ • Masaar source code         │ • TaxFly source code          │
│ • ZATCA compliance engine       │ • TaxFly UI/UX                │
│ • XAdES-BES signing logic       │ • Adapter layer code          │
│ • QR generation algorithms      │ • TaxFly-specific mappings    │
│ • Multi-tenant architecture     │ • Customer relationships      │
│ • All SDKs                      │ • TaxFly brand/trademark      │
│ • API design & documentation    │ • TaxFly customer data        │
│ • Masaar brand/trademark     │                               │
├─────────────────────────────────┴───────────────────────────────┤
│                       JOINT/SHARED                              │
├─────────────────────────────────────────────────────────────────┤
│ • Integration documentation (for this specific integration)     │
│ • Co-marketing materials created during partnership             │
│ • Case studies (both can use post-exit)                         │
└─────────────────────────────────────────────────────────────────┘
```

**Key Clause:**
> "Masaar grants TaxFly a non-exclusive, non-transferable license to use
> the Masaar API during the Partnership Term. This license does not include
> rights to the source code, algorithms, or internal implementation of Masaar.
> TaxFly shall not reverse engineer, decompile, or attempt to derive source code
> from Masaar services."

### 4.2 Branding Requirements

| Requirement | Details |
|-------------|---------|
| Invoice PDFs | "Powered by Masaar" badge on all ZATCA-compliant invoices |
| Dashboard | Masaar attribution in ZATCA status section |
| Documentation | Credit to Masaar in technical docs |
| Marketing | Joint press release at launch |
| Website | Masaar listed as technology partner |
| Support | "ZATCA compliance powered by Masaar" in support materials |

**Key Clause:**
> "TaxFly shall display 'Powered by Masaar' branding on all customer-facing
> materials related to ZATCA compliance functionality, including but not limited
> to: invoice documents, compliance dashboards, and help documentation."

### 4.3 Customer Data Rights

**During Partnership:**
```
┌─────────────────────────────────────────────────────────────────┐
│                    DATA OWNERSHIP                               │
├─────────────────────────────────┬───────────────────────────────┤
│      TAXFLY DATA (Theirs)       │    COMPLIPAY DATA (Yours)     │
├─────────────────────────────────┼───────────────────────────────┤
│ • Customer account info         │ • API usage logs              │
│ • Invoice business data         │ • Compliance audit trails     │
│ • Payment information           │ • ZATCA submission records    │
│ • Customer communications       │ • Aggregated analytics        │
│ • Support tickets               │ • Performance metrics         │
└─────────────────────────────────┴───────────────────────────────┘
```

**At Exit - Data Portability:**
- Masaar may export anonymized/aggregated statistics
- Masaar may reference customer count in marketing ("Processed X invoices")
- Masaar may contact customers who independently reach out
- TaxFly retains all customer PII and business data

**Key Clause:**
> "Upon termination, Masaar shall retain the right to use aggregated,
> anonymized statistics regarding usage volume and compliance rates for
> marketing purposes. Masaar shall not retain or use any customer PII
> beyond the termination date except as required for audit compliance
> (retained for [X] years in encrypted, access-restricted storage)."

### 4.4 Revenue Model

#### Option A: Revenue Share
```
Monthly Revenue to Masaar = (TaxFly ZATCA Revenue) × 30%

Example:
- TaxFly charges customers 50 SAR/month for ZATCA compliance
- TaxFly has 1,000 customers using ZATCA features
- Monthly ZATCA revenue: 50,000 SAR
- Masaar share: 15,000 SAR/month
```

#### Option B: Per-Transaction Fee
```
Fee per invoice submission = 0.50 SAR

Example:
- 100,000 invoices/month processed
- Masaar revenue: 50,000 SAR/month
```

#### Option C: Tiered Licensing
```
Tier 1: 0-10,000 invoices/month     = 10,000 SAR/month
Tier 2: 10,001-50,000 invoices      = 35,000 SAR/month
Tier 3: 50,001-200,000 invoices     = 100,000 SAR/month
Tier 4: 200,001+ invoices           = 200,000 SAR/month
```

**Recommendation:** Start with **Option C (Tiered)** - predictable for both parties, scales with growth.

### 4.5 Exit Terms

#### Exit Triggers
| Trigger | Notice Period | Terms |
|---------|---------------|-------|
| Scheduled exit (18-24 months) | 90 days | Standard terms |
| Masaar early exit | 180 days | Must provide transition support |
| TaxFly early exit | 90 days | Pay remaining minimum commitment |
| Material breach | 30 days cure period | Immediate if uncured |
| Acquisition of either party | 90 days | Renegotiation option |

#### Post-Exit Options for TaxFly

**Option 1: Continue as Customer**
- Transition to standard Masaar pricing
- No "Powered by" requirement (they can white-label)
- Market rate API access

**Option 2: Clean Break**
- 90-day transition period
- Masaar provides data export
- TaxFly finds alternative solution

**Option 3: Extended Partnership**
- Renegotiate for another term
- Updated revenue share/pricing

### 4.6 Non-Compete Scope

**During Partnership:**
```
┌─────────────────────────────────────────────────────────────────┐
│                    NON-COMPETE TERMS                            │
├─────────────────────────────────┬───────────────────────────────┤
│         COMPLIPAY AGREES        │        TAXFLY AGREES          │
├─────────────────────────────────┼───────────────────────────────┤
│ • Won't launch competing        │ • Won't build internal ZATCA  │
│   invoicing platform in Saudi   │   compliance engine           │
│                                 │                               │
│ • Won't directly solicit        │ • Won't reverse engineer      │
│   TaxFly's existing customers   │   Masaar technology        │
│                                 │                               │
│ • Will refer invoicing leads    │ • Won't partner with          │
│   to TaxFly during term         │   competing compliance vendor │
└─────────────────────────────────┴───────────────────────────────┘
```

**Post-Exit (CRITICAL):**
```
┌─────────────────────────────────────────────────────────────────┐
│              POST-EXIT NON-COMPETE (12 months)                  │
├─────────────────────────────────────────────────────────────────┤
│ Masaar:                                                      │
│ • MAY offer API services to any customer (including TaxFly's)   │
│ • MAY launch own invoicing UI if desired                        │
│ • MAY NOT directly solicit TaxFly customers for 12 months       │
│   (inbound inquiries OK)                                        │
├─────────────────────────────────────────────────────────────────┤
│ TaxFly:                                                         │
│ • MAY switch to another compliance vendor                       │
│ • MAY NOT build/sell standalone ZATCA compliance product        │
│   for 24 months                                                 │
│ • MAY NOT hire Masaar employees for 12 months                │
└─────────────────────────────────────────────────────────────────┘
```

**Key Clause:**
> "For a period of twenty-four (24) months following termination, TaxFly
> shall not develop, market, or sell a standalone ZATCA compliance API
> or service that competes directly with Masaar's core offering. This
> restriction does not prevent TaxFly from using third-party compliance
> services or building internal-only compliance tools."

### 4.7 Warranties and Liability

**Masaar Warrants:**
- API uptime of 99.5% (excluding scheduled maintenance)
- ZATCA Phase 2 compliance accuracy
- Security best practices (encryption, access control)
- Timely updates for ZATCA regulation changes

**Masaar Does NOT Warrant:**
- TaxFly's correct use of the API
- TaxFly's data accuracy
- Business outcomes or tax advice
- Third-party service availability (ZATCA itself)

**Liability Cap:**
> "Masaar's total liability shall not exceed the fees paid by TaxFly
> in the twelve (12) months preceding the claim."

### 4.8 Dispute Resolution

1. **Negotiation** (30 days) - Direct discussion between principals
2. **Mediation** (30 days) - Third-party mediator in Riyadh
3. **Arbitration** - Saudi Center for Commercial Arbitration (SCCA)
4. **Governing Law** - Kingdom of Saudi Arabia

---

## 5. Timeline & Milestones

### Phase 1: Negotiation & Setup (Month 1-2)

```
Week 1-2:  Term sheet negotiation
Week 3-4:  Legal review and contract drafting
Week 5-6:  Technical integration planning
Week 7-8:  Contract signing, project kickoff
```

**Deliverables:**
- [ ] Signed partnership agreement
- [ ] Technical integration plan
- [ ] API credentials provisioned
- [ ] Branding assets delivered

### Phase 2: Integration (Month 2-4)

```
Week 1-4:  TaxFly builds adapter layer
Week 5-6:  Integration testing (sandbox)
Week 7-8:  UAT with test customers
```

**Deliverables:**
- [ ] TaxFly adapter complete
- [ ] End-to-end testing passed
- [ ] Documentation complete
- [ ] Support runbook created

### Phase 3: Launch (Month 4-5)

```
Week 1:    Soft launch (10% of customers)
Week 2-3:  Monitor, fix issues
Week 4:    Full rollout
```

**Deliverables:**
- [ ] Production deployment
- [ ] Monitoring dashboards
- [ ] Joint press release
- [ ] Customer communications sent

### Phase 4: Operate (Month 5-20)

```
Ongoing:   Process invoices, support customers
Monthly:   Revenue reconciliation, performance review
Quarterly: Partnership health check, roadmap alignment
```

**Success Metrics:**
- Invoice processing volume
- API uptime/reliability
- Customer satisfaction scores
- Revenue targets

### Phase 5: Exit Preparation (Month 18-22)

```
Month 18:  Exit planning begins
Month 20:  Announce transition (if full exit)
Month 22:  Execute transition
Month 24:  Partnership ends or converts to standard customer
```

**Exit Deliverables:**
- [ ] Customer communication plan
- [ ] Data export procedures
- [ ] Post-exit support agreement
- [ ] Final revenue settlement

---

## 6. Exit Playbook

### 6.1 Preparing for Exit (Start at Month 15)

**Build Your Independence:**
- [ ] Establish Masaar brand presence (website, social, content)
- [ ] Build direct sales pipeline
- [ ] Develop case studies from TaxFly partnership
- [ ] Hire sales/marketing team
- [ ] Set up customer support infrastructure

**Technical Preparation:**
- [ ] Ensure Masaar runs independently (not dependent on TaxFly infra)
- [ ] Document all TaxFly-specific configurations
- [ ] Prepare customer migration tooling (if applicable)
- [ ] Test standalone deployment

### 6.2 Exit Scenarios

#### Scenario A: Friendly Exit, TaxFly Continues as Customer

```
Best case - partnership worked well, both want to continue differently

Actions:
1. Negotiate standard customer pricing (remove partnership discount)
2. Remove mandatory branding requirements (TaxFly can white-label)
3. Sign standard customer agreement
4. Continue service with commercial relationship
```

#### Scenario B: Clean Break

```
Partnership ends, TaxFly uses different solution

Actions:
1. Give 90-day notice
2. Provide data export
3. Assist with transition documentation
4. Settle final payments
5. Remove any TaxFly-specific code/config
```

#### Scenario C: TaxFly Acquisition

```
TaxFly gets acquired, new owner wants to renegotiate

Actions:
1. Evaluate new owner's intentions
2. Exercise renegotiation clause if unfavorable
3. Either extend with new terms or execute clean break
```

### 6.3 Post-Exit Checklist

**Immediate (Day 1-30):**
- [ ] Revoke TaxFly API credentials (if clean break)
- [ ] Archive TaxFly-specific documentation
- [ ] Settle final invoices
- [ ] Send customer farewell/transition communication

**Short-term (Month 1-3):**
- [ ] Launch independent marketing campaign
- [ ] Contact prospects built during partnership
- [ ] Publish case study (with TaxFly permission)
- [ ] Remove any TaxFly references from codebase

**Long-term (Month 3-12):**
- [ ] Monitor non-compete compliance
- [ ] Track market for TaxFly's compliance solution
- [ ] Build relationships with TaxFly competitors

---

## 7. Contract Model

### 7.1 Contract Structure Overview

This contract model follows Saudi Arabian commercial law requirements and incorporates ZATCA regulatory compliance obligations.

```
┌─────────────────────────────────────────────────────────────────┐
│                    CONTRACT STRUCTURE                           │
├─────────────────────────────────────────────────────────────────┤
│  MAIN AGREEMENT                                                 │
│  ├── Recitals (Background & Intent)                             │
│  ├── Definitions                                                │
│  ├── Term & Termination                                         │
│  ├── Services & Scope                                           │
│  ├── Fees & Payment                                             │
│  ├── Intellectual Property                                      │
│  ├── Data Protection & Residency                                │
│  ├── Confidentiality                                            │
│  ├── Warranties & Liability                                     │
│  ├── Non-Compete & Non-Solicit                                  │
│  ├── Dispute Resolution                                         │
│  └── General Provisions                                         │
│                                                                 │
│  SCHEDULES                                                      │
│  ├── Schedule A: Service Description & SLA                      │
│  ├── Schedule B: Pricing & Payment Terms                        │
│  ├── Schedule C: Data Processing Agreement                      │
│  ├── Schedule D: Branding Guidelines                            │
│  └── Schedule E: Technical Specifications                       │
└─────────────────────────────────────────────────────────────────┘
```

---

### 7.2 Full Contract Template

```
═══════════════════════════════════════════════════════════════════
                    TECHNOLOGY PARTNERSHIP AGREEMENT

                    ZATCA E-INVOICING COMPLIANCE SERVICES
═══════════════════════════════════════════════════════════════════

This Technology Partnership Agreement ("Agreement") is entered into
as of [DATE] ("Effective Date")

BETWEEN:

(1) [COMPLIPAY ENTITY NAME]
    A company registered in [JURISDICTION]
    Registration No: [NUMBER]
    Address: [ADDRESS]
    ("Masaar" or "Provider")

AND:

(2) [TAXFLY ENTITY NAME]
    A company registered in the Kingdom of Saudi Arabia
    Commercial Registration No: [NUMBER]
    VAT Registration No: [NUMBER]
    Address: [ADDRESS]
    ("TaxFly" or "Client")

Each a "Party" and collectively the "Parties"

═══════════════════════════════════════════════════════════════════
                           RECITALS
═══════════════════════════════════════════════════════════════════

WHEREAS:

A.  Masaar has developed a ZATCA Phase 2 compliant e-invoicing
    platform providing XML generation, digital signing, QR code
    generation, and ZATCA API integration services.

B.  TaxFly operates an invoicing and accounting platform serving
    businesses in the Kingdom of Saudi Arabia.

C.  TaxFly wishes to integrate Masaar's compliance services
    into its platform to provide ZATCA Phase 2 compliance to
    its customers.

D.  The Parties wish to enter into a strategic partnership for
    an initial term of twenty-four (24) months with provisions
    for transition and exit.

NOW THEREFORE, in consideration of the mutual covenants and
agreements set forth herein, the Parties agree as follows:

═══════════════════════════════════════════════════════════════════
                    ARTICLE 1: DEFINITIONS
═══════════════════════════════════════════════════════════════════

1.1  "API" means Masaar's application programming interface
     through which the Services are accessed.

1.2  "Compliance Data" means all data generated by Masaar in
     the course of providing Services, including signed XMLs,
     QR codes, hash values, and ZATCA submission records.

1.3  "Confidential Information" means any non-public information
     disclosed by one Party to the other, whether orally, in
     writing, or by inspection.

1.4  "Customer Data" means all data provided by TaxFly's end
     customers through the Services.

1.5  "Documentation" means Masaar's technical documentation,
     API specifications, and integration guides.

1.6  "Effective Date" means the date first written above.

1.7  "EGS" means E-invoicing Generation Solution as defined by
     ZATCA regulations.

1.8  "End Customer" means a business entity using TaxFly's
     platform that benefits from the Services.

1.9  "Intellectual Property Rights" means patents, copyrights,
     trademarks, trade secrets, and other proprietary rights.

1.10 "NCA" means the National Cybersecurity Authority of the
     Kingdom of Saudi Arabia.

1.11 "Services" means the ZATCA e-invoicing compliance services
     described in Schedule A.

1.12 "Service Level Agreement" or "SLA" means the performance
     standards set forth in Schedule A.

1.13 "Term" means the Initial Term and any Renewal Terms.

1.14 "ZATCA" means the Zakat, Tax and Customs Authority of the
     Kingdom of Saudi Arabia.

═══════════════════════════════════════════════════════════════════
                    ARTICLE 2: TERM AND TERMINATION
═══════════════════════════════════════════════════════════════════

2.1  INITIAL TERM
     This Agreement shall commence on the Effective Date and
     continue for twenty-four (24) months ("Initial Term"),
     unless terminated earlier in accordance with this Article.

2.2  RENEWAL
     Upon expiration of the Initial Term, this Agreement may be
     renewed for successive twelve (12) month periods ("Renewal
     Terms") upon mutual written agreement of the Parties at
     least ninety (90) days prior to expiration.

2.3  TERMINATION FOR CONVENIENCE
     (a) After the first eighteen (18) months, either Party may
         terminate this Agreement for convenience by providing
         ninety (90) days written notice.

     (b) Masaar may terminate for convenience prior to Month 18
         by providing one hundred eighty (180) days written notice
         and reasonable transition support.

2.4  TERMINATION FOR CAUSE
     Either Party may terminate this Agreement immediately upon
     written notice if the other Party:

     (a) Commits a material breach that remains uncured for thirty
         (30) days after written notice; or

     (b) Becomes insolvent, files for bankruptcy, or ceases
         operations; or

     (c) Is found to have violated applicable laws or regulations.

2.5  TERMINATION FOR REGULATORY CHANGE
     Either Party may terminate with sixty (60) days notice if
     ZATCA regulations change in a manner that materially affects
     the Services or renders compliance impractical.

2.6  EFFECT OF TERMINATION
     Upon termination or expiration:

     (a) TaxFly shall pay all fees accrued through the termination
         date within thirty (30) days;

     (b) Masaar shall provide data export as specified in
         Article 8;

     (c) Licenses granted herein shall terminate, except as
         provided in Section 5.6;

     (d) Articles 4 (IP), 6 (Confidentiality), 8 (Data), 9
         (Liability), and 11 (Dispute Resolution) shall survive.

2.7  TRANSITION ASSISTANCE
     Upon termination for any reason, Masaar shall provide
     reasonable transition assistance for a period of ninety (90)
     days at Masaar's then-current rates, including:

     (a) Continued API access during transition period;
     (b) Data export in standard formats;
     (c) Technical consultation for migration;
     (d) Documentation of all integration points.

═══════════════════════════════════════════════════════════════════
                    ARTICLE 3: SERVICES
═══════════════════════════════════════════════════════════════════

3.1  SERVICES PROVIDED
     Masaar shall provide TaxFly with the Services described
     in Schedule A, including:

     (a) ZATCA-compliant UBL 2.1 XML invoice generation;
     (b) XAdES-BES digital signature services;
     (c) TLV-encoded QR code generation;
     (d) ZATCA API integration for clearance and reporting;
     (e) Invoice hash chain management;
     (f) Webhook notifications for status updates;
     (g) API access and technical support.

3.2  SERVICE LEVELS
     Masaar shall provide Services in accordance with the SLA
     in Schedule A, including:

     (a) API availability of 99.5% monthly, excluding scheduled
         maintenance;
     (b) Response time targets as specified;
     (c) Support response times as specified.

3.3  SERVICE MODIFICATIONS
     Masaar may modify the Services to:

     (a) Comply with changes in ZATCA regulations;
     (b) Improve security or performance;
     (c) Fix defects or errors.

     Material modifications shall be communicated with thirty (30)
     days advance notice where practicable.

3.4  INTEGRATION
     (a) TaxFly shall integrate with Masaar's standard API as
         documented;
     (b) TaxFly is responsible for developing and maintaining its
         own adapter layer;
     (c) Masaar shall not develop TaxFly-specific features in
         its core platform.

3.5  SUPPORT
     Masaar shall provide technical support as follows:

     (a) Email support: Response within 24 business hours
     (b) Critical issues: Response within 4 hours
     (c) Documentation: Maintained and accessible online
     (d) Quarterly review meetings

═══════════════════════════════════════════════════════════════════
                    ARTICLE 4: INTELLECTUAL PROPERTY
═══════════════════════════════════════════════════════════════════

4.1  COMPLIPAY IP
     Masaar retains all right, title, and interest in:

     (a) The Masaar platform, including all source code,
         algorithms, and architecture;
     (b) The API design, specifications, and documentation;
     (c) All SDKs, libraries, and tools provided;
     (d) The Masaar name, trademarks, and branding;
     (e) Any improvements, modifications, or derivative works
         created during the Term.

4.2  TAXFLY IP
     TaxFly retains all right, title, and interest in:

     (a) The TaxFly platform, including all source code and
         business logic;
     (b) TaxFly's adapter layer and integration code;
     (c) TaxFly customer data and relationships;
     (d) The TaxFly name, trademarks, and branding.

4.3  LICENSE GRANT TO TAXFLY
     Subject to the terms of this Agreement, Masaar grants
     TaxFly a non-exclusive, non-transferable, revocable license
     to:

     (a) Access and use the API for the purpose of integrating
         the Services into TaxFly's platform;
     (b) Use Masaar's documentation for integration purposes;
     (c) Display "Powered by Masaar" branding as required by
         Article 7.

     This license does not include rights to the source code,
     algorithms, or internal implementation of Masaar.

4.4  LICENSE GRANT TO COMPLIPAY
     TaxFly grants Masaar a non-exclusive license to:

     (a) Use aggregated, anonymized usage statistics for marketing;
     (b) Reference TaxFly as a customer (with TaxFly's approval);
     (c) Process Customer Data as necessary to provide Services.

4.5  RESTRICTIONS
     TaxFly shall not:

     (a) Reverse engineer, decompile, or disassemble the Services;
     (b) Attempt to derive source code from the Services;
     (c) Sublicense the Services without written consent;
     (d) Remove or alter any proprietary notices;
     (e) Use the Services to develop a competing product.

4.6  SURVIVING LICENSE
     Upon termination, Masaar grants TaxFly a perpetual,
     royalty-free license to retain and use Compliance Data
     (signed XMLs, QR codes) for:

     (a) Audit and compliance purposes;
     (b) Displaying to End Customers;
     (c) Meeting ZATCA retention requirements.

═══════════════════════════════════════════════════════════════════
                    ARTICLE 5: FEES AND PAYMENT
═══════════════════════════════════════════════════════════════════

5.1  FEES
     TaxFly shall pay Masaar the fees set forth in Schedule B.

5.2  PAYMENT TERMS
     (a) Fees are payable monthly in arrears;
     (b) Invoices shall be issued within five (5) days of month end;
     (c) Payment is due within thirty (30) days of invoice date;
     (d) All fees are in Saudi Riyals (SAR) unless specified.

5.3  MINIMUM COMMITMENT
     TaxFly commits to a minimum monthly fee of [AMOUNT] SAR
     during the Term ("Minimum Commitment").

5.4  LATE PAYMENT
     Late payments shall accrue interest at the rate of 1.5% per
     month or the maximum rate permitted by law, whichever is less.

5.5  TAXES
     All fees are exclusive of applicable taxes. TaxFly is
     responsible for all taxes except those based on Masaar's
     income.

5.6  AUDIT RIGHTS
     Masaar may audit TaxFly's usage records upon thirty (30)
     days notice, no more than once per year. If an audit reveals
     underpayment exceeding 5%, TaxFly shall pay the shortfall
     plus audit costs.

═══════════════════════════════════════════════════════════════════
                    ARTICLE 6: CONFIDENTIALITY
═══════════════════════════════════════════════════════════════════

6.1  CONFIDENTIAL INFORMATION
     Each Party agrees to:

     (a) Maintain the confidentiality of the other Party's
         Confidential Information;
     (b) Not disclose Confidential Information to third parties
         without prior written consent;
     (c) Use Confidential Information only for purposes of this
         Agreement;
     (d) Protect Confidential Information with reasonable care.

6.2  EXCLUSIONS
     Confidential Information does not include information that:

     (a) Is or becomes publicly available without breach;
     (b) Was known to the receiving Party prior to disclosure;
     (c) Is independently developed without reference to
         Confidential Information;
     (d) Is received from a third party without restriction.

6.3  PERMITTED DISCLOSURES
     A Party may disclose Confidential Information if required by
     law, regulation, or court order, provided the disclosing Party:

     (a) Gives prompt notice to the other Party;
     (b) Cooperates in seeking protective measures;
     (c) Discloses only the minimum required.

6.4  DURATION
     Confidentiality obligations shall survive termination for
     three (3) years, except for trade secrets which shall be
     protected indefinitely.

═══════════════════════════════════════════════════════════════════
                    ARTICLE 7: BRANDING
═══════════════════════════════════════════════════════════════════

7.1  POWERED BY BRANDING
     During the Term, TaxFly shall display "Powered by Masaar"
     branding on:

     (a) All ZATCA-compliant invoice PDFs generated through the
         Services;
     (b) ZATCA compliance status screens in TaxFly's dashboard;
     (c) Help documentation related to ZATCA features.

7.2  BRANDING SPECIFICATIONS
     Branding shall comply with the guidelines in Schedule D,
     including:

     (a) Minimum size and placement requirements;
     (b) Color and typography standards;
     (c) Approved logo files and usage.

7.3  MARKETING
     (a) The Parties shall issue a joint press release at launch;
     (b) Masaar may list TaxFly as a customer with approval;
     (c) Case studies require mutual written consent.

7.4  POST-EXIT BRANDING
     Upon termination, if TaxFly continues as a Masaar customer:

     (a) "Powered by" branding becomes optional;
     (b) TaxFly may white-label the Services;
     (c) Standard customer branding terms apply.

═══════════════════════════════════════════════════════════════════
          ARTICLE 8: DATA PROTECTION AND RESIDENCY
═══════════════════════════════════════════════════════════════════

8.1  DATA CATEGORIES
     The Parties acknowledge the following data categories:

     ┌─────────────────────────────────────────────────────────┐
     │  CATEGORY           │  OWNER    │  CONTROLLER          │
     ├─────────────────────────────────────────────────────────┤
     │  Customer PII       │  TaxFly   │  TaxFly              │
     │  Invoice Data       │  TaxFly   │  TaxFly              │
     │  Compliance Data    │  Joint    │  Masaar           │
     │  Usage Analytics    │  Masaar│  Masaar           │
     │  API Logs           │  Masaar│  Masaar           │
     └─────────────────────────────────────────────────────────┘

8.2  DATA RESIDENCY REQUIREMENTS
     IN ACCORDANCE WITH ZATCA REGULATIONS:

     (a) All invoice data, signed XMLs, QR codes, and compliance
         records must be stored on servers physically located
         within the Kingdom of Saudi Arabia;

     (b) Cloud storage must comply with NCA regulations;

     (c) Data must be accessible to ZATCA upon lawful request;

     (d) Minimum retention period is six (6) years from invoice
         issuance.

8.3  HOSTING ARRANGEMENT
     The Parties agree to the following hosting arrangement:

     [ ] OPTION A: Masaar hosts in Saudi Arabia
         Masaar shall deploy and operate the Services on
         infrastructure located within Saudi Arabia.

     [ ] OPTION B: TaxFly hosts Masaar container
         TaxFly shall provide Saudi-based infrastructure on which
         Masaar's containerized service shall be deployed.
         Masaar shall provide the container image.

     [ ] OPTION C: Processing-only model
         Masaar processes data transiently (no persistent
         storage). TaxFly stores all data in Saudi Arabia.

     [CHECK APPLICABLE OPTION]

8.4  DATA PROCESSING
     Masaar shall:

     (a) Process Customer Data only as necessary to provide
         Services;
     (b) Implement appropriate technical and organizational
         security measures;
     (c) Not transfer Customer Data outside Saudi Arabia without
         explicit consent and legal basis;
     (d) Promptly notify TaxFly of any data breach.

8.5  DATA RETENTION
     (a) Masaar shall retain Compliance Data for six (6) years
         as required by ZATCA;
     (b) Upon termination, data shall be handled as specified in
         Section 8.7;
     (c) Data deletion requests shall be honored except where
         retention is legally required.

8.6  AUDIT ACCESS
     (a) TaxFly shall provide ZATCA unrestricted access to data
         upon lawful request;
     (b) Masaar shall cooperate in generating compliance reports;
     (c) Both Parties shall maintain audit logs for six (6) years.

8.7  DATA EXPORT AND DELETION
     Upon termination:

     (a) Masaar shall provide TaxFly with a complete export of
         all Compliance Data within thirty (30) days in standard
         formats (XML, JSON, CSV);

     (b) After export confirmation, Masaar shall delete TaxFly-
         specific data within ninety (90) days, except:
         - Data required for legal/audit compliance (retained per
           ZATCA requirements);
         - Aggregated, anonymized analytics (retained indefinitely);

     (c) Masaar shall provide written certification of deletion.

8.8  SECURITY MEASURES
     Masaar shall implement and maintain:

     (a) Encryption at rest (AES-256 or equivalent);
     (b) Encryption in transit (TLS 1.2+);
     (c) Role-based access control;
     (d) Audit logging of all access;
     (e) Regular security assessments;
     (f) Incident response procedures.

═══════════════════════════════════════════════════════════════════
                ARTICLE 9: WARRANTIES AND LIABILITY
═══════════════════════════════════════════════════════════════════

9.1  COMPLIPAY WARRANTIES
     Masaar warrants that:

     (a) The Services will substantially conform to the
         Documentation and SLA;
     (b) The Services are designed to comply with ZATCA Phase 2
         requirements as of the Effective Date;
     (c) Masaar has the right to grant the licenses herein;
     (d) The Services will not infringe third-party IP rights;
     (e) Masaar will implement reasonable security measures.

9.2  TAXFLY WARRANTIES
     TaxFly warrants that:

     (a) TaxFly has the authority to enter into this Agreement;
     (b) Customer Data provided to Masaar is accurate and
         lawfully obtained;
     (c) TaxFly will use the Services in compliance with
         applicable laws;
     (d) TaxFly will not use the Services for fraudulent purposes.

9.3  DISCLAIMER
     EXCEPT AS EXPRESSLY PROVIDED HEREIN, THE SERVICES ARE
     PROVIDED "AS IS." COMPLIPAY DISCLAIMS ALL OTHER WARRANTIES,
     EXPRESS OR IMPLIED, INCLUDING WARRANTIES OF MERCHANTABILITY,
     FITNESS FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT.

9.4  LIMITATION OF LIABILITY
     (a) NEITHER PARTY SHALL BE LIABLE FOR INDIRECT, INCIDENTAL,
         SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES;

     (b) COMPLIPAY'S TOTAL LIABILITY SHALL NOT EXCEED THE FEES
         PAID BY TAXFLY IN THE TWELVE (12) MONTHS PRECEDING THE
         CLAIM;

     (c) THE FOREGOING LIMITATIONS SHALL NOT APPLY TO:
         - Breach of confidentiality obligations;
         - Infringement of intellectual property rights;
         - Gross negligence or willful misconduct;
         - Indemnification obligations.

9.5  INDEMNIFICATION
     (a) Masaar shall indemnify TaxFly against third-party
         claims that the Services infringe intellectual property
         rights;

     (b) TaxFly shall indemnify Masaar against claims arising
         from TaxFly's misuse of the Services or breach of this
         Agreement.

═══════════════════════════════════════════════════════════════════
              ARTICLE 10: NON-COMPETE AND NON-SOLICIT
═══════════════════════════════════════════════════════════════════

10.1 DURING THE TERM

     Masaar agrees:
     (a) Not to launch a competing invoicing platform directly
         targeting TaxFly's existing customers in Saudi Arabia;
     (b) To refer invoicing platform leads to TaxFly.

     TaxFly agrees:
     (a) Not to develop, acquire, or license an internal ZATCA
         compliance engine;
     (b) Not to reverse engineer Masaar technology;
     (c) Not to partner with a competing compliance vendor.

10.2 POST-TERMINATION

     For twenty-four (24) months after termination:

     TaxFly shall not:
     (a) Develop, market, or sell a standalone ZATCA compliance
         API or service that competes directly with Masaar;
     (b) Hire or solicit Masaar employees for twelve (12)
         months.

     Masaar shall not:
     (a) Directly solicit TaxFly's existing customers for twelve
         (12) months (inbound inquiries permitted);
     (b) Use Confidential Information to target TaxFly customers.

10.3 PERMITTED ACTIVITIES
     Notwithstanding the above:

     (a) Masaar may offer Services to any customer, including
         those who are also TaxFly customers, through independent
         channels;
     (b) TaxFly may use third-party compliance services;
     (c) Either Party may develop products in non-competing areas.

═══════════════════════════════════════════════════════════════════
                ARTICLE 11: DISPUTE RESOLUTION
═══════════════════════════════════════════════════════════════════

11.1 NEGOTIATION
     The Parties shall attempt to resolve disputes through good
     faith negotiation for thirty (30) days.

11.2 MEDIATION
     If negotiation fails, disputes shall be submitted to
     mediation administered by a mutually agreed mediator in
     Riyadh for thirty (30) days.

11.3 ARBITRATION
     If mediation fails, disputes shall be finally resolved by
     arbitration administered by the Saudi Center for Commercial
     Arbitration (SCCA) under its Arbitration Rules.

     (a) The arbitration shall be conducted in Riyadh;
     (b) The language shall be Arabic and English;
     (c) The arbitral tribunal shall consist of one (1) arbitrator;
     (d) The arbitrator's decision shall be final and binding.

11.4 GOVERNING LAW
     This Agreement shall be governed by and construed in
     accordance with the laws of the Kingdom of Saudi Arabia.

11.5 EMERGENCY RELIEF
     Nothing in this Article prevents either Party from seeking
     emergency injunctive relief from a court of competent
     jurisdiction.

═══════════════════════════════════════════════════════════════════
                ARTICLE 12: GENERAL PROVISIONS
═══════════════════════════════════════════════════════════════════

12.1 ENTIRE AGREEMENT
     This Agreement, including all Schedules, constitutes the
     entire agreement between the Parties and supersedes all
     prior agreements and understandings.

12.2 AMENDMENTS
     This Agreement may only be amended by written instrument
     signed by both Parties.

12.3 ASSIGNMENT
     Neither Party may assign this Agreement without prior
     written consent, except:

     (a) To an affiliate with notice; or
     (b) In connection with a merger or acquisition (subject to
         the other Party's right to terminate per Section 2.3).

12.4 NOTICES
     All notices shall be in writing and delivered to the
     addresses set forth above, or as updated by written notice.

12.5 FORCE MAJEURE
     Neither Party shall be liable for delays caused by events
     beyond reasonable control, including natural disasters, war,
     terrorism, or government action. ZATCA system outages shall
     not be considered force majeure for Masaar's obligations.

12.6 SEVERABILITY
     If any provision is held invalid, the remaining provisions
     shall continue in full force and effect.

12.7 WAIVER
     Failure to enforce any right shall not constitute a waiver
     of that right.

12.8 COUNTERPARTS
     This Agreement may be executed in counterparts, each of
     which shall be deemed an original.

12.9 LANGUAGE
     This Agreement is executed in Arabic and English. In case
     of conflict, the Arabic version shall prevail.

═══════════════════════════════════════════════════════════════════
                         SIGNATURES
═══════════════════════════════════════════════════════════════════

IN WITNESS WHEREOF, the Parties have executed this Agreement
as of the Effective Date.


COMPLIPAY:                          TAXFLY:

_____________________________       _____________________________
Signature                           Signature

_____________________________       _____________________________
Name:                               Name:

_____________________________       _____________________________
Title:                              Title:

_____________________________       _____________________________
Date:                               Date:


═══════════════════════════════════════════════════════════════════
                    SCHEDULE A: SERVICES AND SLA
═══════════════════════════════════════════════════════════════════

A.1  SERVICES DESCRIPTION

     1. INVOICE PROCESSING
        - Accept invoice data via REST API
        - Generate UBL 2.1 compliant XML
        - Apply XAdES-BES digital signature (ECDSA secp256k1)
        - Generate TLV-encoded QR code (Phase 2, 9 tags)
        - Calculate and maintain invoice hash chain

     2. ZATCA INTEGRATION
        - Submit standard invoices for clearance (B2B)
        - Report simplified invoices (B2C)
        - Handle credit notes and debit notes
        - Process ZATCA responses and status updates

     3. NOTIFICATIONS
        - Webhook notifications for status changes
        - Events: cleared, reported, rejected, warning

     4. REPORTING
        - Compliance status dashboard data
        - Invoice processing statistics
        - Error and warning reports

A.2  SERVICE LEVEL AGREEMENT

     ┌─────────────────────────────────────────────────────────┐
     │  METRIC              │  TARGET    │  MEASUREMENT        │
     ├─────────────────────────────────────────────────────────┤
     │  API Availability    │  99.5%     │  Monthly            │
     │  API Response Time   │  < 2s      │  95th percentile    │
     │  ZATCA Submission    │  < 30s     │  95th percentile    │
     │  Webhook Delivery    │  < 5min    │  99th percentile    │
     │  Critical Support    │  < 4hr     │  Response time      │
     │  Standard Support    │  < 24hr    │  Response time      │
     └─────────────────────────────────────────────────────────┘

     Exclusions:
     - Scheduled maintenance (with 48hr notice)
     - ZATCA system outages
     - Force majeure events
     - TaxFly-side issues

A.3  SUPPORT TIERS

     CRITICAL: Service unavailable, invoices cannot be processed
     HIGH:     Significant functionality impaired
     MEDIUM:   Non-critical functionality affected
     LOW:      General questions, feature requests


═══════════════════════════════════════════════════════════════════
                SCHEDULE B: PRICING AND PAYMENT
═══════════════════════════════════════════════════════════════════

B.1  PRICING MODEL

     [SELECT ONE]

     [ ] OPTION 1: TIERED LICENSING

         Tier 1:  0 - 10,000 invoices/month      10,000 SAR/month
         Tier 2:  10,001 - 50,000 invoices       35,000 SAR/month
         Tier 3:  50,001 - 200,000 invoices     100,000 SAR/month
         Tier 4:  200,001+ invoices             200,000 SAR/month

     [ ] OPTION 2: PER-TRANSACTION

         Per invoice submission:                  0.50 SAR
         Monthly minimum:                     15,000 SAR

     [ ] OPTION 3: REVENUE SHARE

         Percentage of TaxFly's ZATCA revenue:      30%
         Monthly minimum:                     10,000 SAR

B.2  MINIMUM COMMITMENT

     Minimum monthly fee: [AMOUNT] SAR
     Payable regardless of actual usage

B.3  PAYMENT SCHEDULE

     Invoice date:      5th of each month
     Payment due:       30 days from invoice
     Currency:          Saudi Riyal (SAR)
     Payment method:    Bank transfer

B.4  ANNUAL ADJUSTMENT

     Pricing may be adjusted annually by:
     - Mutual agreement; or
     - CPI increase (capped at 5%)


═══════════════════════════════════════════════════════════════════
            SCHEDULE C: DATA PROCESSING AGREEMENT
═══════════════════════════════════════════════════════════════════

C.1  SCOPE
     This Schedule governs processing of personal data.

C.2  ROLES
     - TaxFly: Data Controller
     - Masaar: Data Processor

C.3  PROCESSING PURPOSES
     Masaar processes data solely to provide Services.

C.4  DATA TYPES PROCESSED
     - Business name and address
     - VAT registration number
     - Invoice amounts and line items
     - Contact information (as provided)

C.5  SECURITY MEASURES
     As specified in Article 8.8.

C.6  SUB-PROCESSORS
     Masaar may engage sub-processors with notice to TaxFly.
     Current sub-processors: [LIST]

C.7  DATA SUBJECT RIGHTS
     Masaar shall assist TaxFly in responding to data subject
     requests within applicable timeframes.


═══════════════════════════════════════════════════════════════════
              SCHEDULE D: BRANDING GUIDELINES
═══════════════════════════════════════════════════════════════════

D.1  "POWERED BY" BADGE

     Minimum size:     120px width
     Placement:        Invoice footer, dashboard footer
     Colors:           As provided in brand kit
     Clear space:      Minimum 10px margin

D.2  APPROVED FORMATS

     - masaar-badge-color.svg
     - masaar-badge-mono.svg
     - masaar-badge-white.svg

D.3  PROHIBITED USES

     - Modifying the logo
     - Using outdated versions
     - Placement that implies endorsement of non-ZATCA features

D.4  REVIEW

     TaxFly shall submit branding implementations for review
     prior to launch.


═══════════════════════════════════════════════════════════════════
            SCHEDULE E: TECHNICAL SPECIFICATIONS
═══════════════════════════════════════════════════════════════════

E.1  API SPECIFICATIONS

     Base URL:         [TO BE PROVIDED]
     Authentication:   API Key (X-API-Key header)
     Format:           JSON
     Documentation:    [URL]

E.2  INTEGRATION REQUIREMENTS

     - TLS 1.2+ required
     - API key rotation every 90 days
     - Webhook signature verification required
     - Rate limits: 100 requests/second

E.3  ENVIRONMENTS

     Sandbox:          For development and testing
     Simulation:       For ZATCA compliance testing
     Production:       Live ZATCA integration

E.4  WEBHOOK EVENTS

     invoice.created
     invoice.submitted
     invoice.cleared
     invoice.reported
     invoice.rejected
     invoice.warning
     certificate.expiring

═══════════════════════════════════════════════════════════════════
                    END OF AGREEMENT
═══════════════════════════════════════════════════════════════════
```

---

## Appendix A: Negotiation Checklist

### Must-Haves (Walk Away If Not Agreed)
- [ ] IP ownership stays with Masaar
- [ ] "Powered by Masaar" branding
- [ ] Right to exit after 18-24 months
- [ ] No source code transfer
- [ ] Post-exit ability to compete

### Nice-to-Haves (Push For But Flexible)
- [ ] Revenue share vs. flat fee (depends on confidence in volume)
- [ ] Exclusive partnership (for higher rev share)
- [ ] Joint product roadmap input
- [ ] Co-selling arrangements

### Avoid (Red Flags)
- [ ] Source code escrow that triggers on minor events
- [ ] Perpetual non-compete
- [ ] Unlimited liability
- [ ] Right of first refusal on Masaar sale
- [ ] TaxFly control over Masaar roadmap

---

## Appendix B: Sample Term Sheet

```
PARTNERSHIP TERM SHEET
Masaar + TaxFly
[Date]

1. STRUCTURE
   - Technology licensing and services agreement
   - Not a merger, joint venture, or equity investment

2. TERM
   - Initial term: 24 months
   - Renewal: Annual, mutual consent
   - Exit: 90 days notice after month 18

3. ECONOMICS
   - Pricing: Tiered based on volume (see Section 3.4)
   - Payment: Monthly in arrears
   - Minimum commitment: [X] SAR/month

4. IP
   - Masaar retains all IP
   - TaxFly receives API license only
   - No source code access

5. BRANDING
   - "Powered by Masaar" required
   - Joint launch announcement

6. EXCLUSIVITY
   - TaxFly: Exclusive ZATCA compliance partner in Saudi
   - Masaar: Non-exclusive (may serve other customers)

7. EXIT
   - Data export within 30 days
   - Transition support for 90 days
   - Post-exit: Standard customer terms available

8. NON-COMPETE
   - During term: As specified in Section 3.6
   - Post-exit: 12-24 months as specified

9. GOVERNANCE
   - Monthly operational review
   - Quarterly executive review
   - Annual strategic planning

10. CONFIDENTIALITY
    - Standard mutual NDA terms
    - Survives termination for 3 years
```

---

*Document Version: 2.0*
*Created: 2026-02-03*
*Last Updated: 2026-02-03*
*Status: DRAFT - For Internal Planning & Legal Review*

**Changelog:**
- v2.0: Added ZATCA regulatory compliance section, full contract model with schedules
- v1.0: Initial partnership strategy framework
