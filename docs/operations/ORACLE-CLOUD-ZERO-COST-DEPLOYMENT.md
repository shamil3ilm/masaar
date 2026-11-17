# Zero-Cost Sovereign Cloud Deployment Guide (Oracle Cloud Infrastructure)

Audience: System administrators and operators deploying Masaar from scratch at \$0/month infrastructure cost while remaining fully compliant with Saudi Arabia data residency mandates (ZATCA Section 8.3.1 and CST Cloud Regulations).

---

## Architecture & Cost Overview

Oracle Cloud Infrastructure (OCI) offers an **Always Free Tier** with sovereign data centre facilities in the Kingdom of Saudi Arabia.

| Resource | OCI Always Free Allocation | Masaar Deployment Usage | Monthly Cost |
| :--- | :--- | :--- | :--- |
| **Compute Shape** | Ampere A1 (ARM64), 4 OCPUs, 24 GB RAM | 4 OCPUs, 24 GB RAM (single sovereign node) | **\$0.00** |
| **Boot Storage** | 200 GB total across Always Free instances | 100 GB – 200 GB NVMe boot volume | **\$0.00** |
| **Public IPv4** | 1 Reserved Ephemeral/Static Public IPv4 | 1 Dedicated Static Public IP for Traefik ingress | **\$0.00** |
| **Egress Bandwidth** | 10 TB per month outbound | ~25 GB – 100 GB outbound API traffic | **\$0.00** |
| **Object Storage** | 10 GB Standard / Archive storage | Offsite encrypted database & XML backups | **\$0.00** |
| **TLS Certificate** | Automatic Let's Encrypt via Traefik | Wildcard / SNI SSL certificates | **\$0.00** |
| **Total Cost** | — | — | **\$0.00 / month** |

> [!IMPORTANT]
> **Data Residency Mandate**: When registering your Oracle Cloud account, you must select **Saudi Arabia West (Jeddah)** (`me-jeddah-1`) as your **Home Region**. OCI Always Free resources are permanently pinned to your designated Home Region and cannot be migrated between regions later. This guarantees that all tax data, private keys, audit logs, and backups stay strictly within the Kingdom.

---

## Step 1: Account Registration & Home Region Selection

1. Go to the Oracle Cloud registration page (`oracle.com/cloud/free`).
2. Provide your business email, country (**Saudi Arabia**), and organization details.
3. When prompted for **Home Region**, select **Saudi Arabia West (Jeddah)**.
4. Complete credit card verification (used only for identity verification; your card is charged \$0.00 and will never be billed as long as you stay within the Always Free limits).
5. Once your tenancy is provisioned, sign in to the OCI Console.

---

## Step 2: Virtual Cloud Network (VCN) Setup

A Virtual Cloud Network creates an isolated network perimeter for your server with internet routing and firewall rules.

1. In the OCI Console navigation menu, open **Networking** > **Virtual Cloud Networks**.
2. Click **Start VCN Wizard** and select **Create VCN with Internet Connectivity**.
3. Configure the network parameters:
   - **VCN Name**: `masaar-vcn`
   - **Compartment**: Select your root or dedicated compartment.
   - **VCN IPv4 CIDR Block**: `10.0.0.0/16`
   - **Public Subnet IPv4 CIDR Block**: `10.0.0.0/24`
   - **Private Subnet IPv4 CIDR Block**: `10.0.1.0/24`
4. Click **Next**, review the routing tables and internet gateway, and click **Create**.
5. Once created, click on `masaar-vcn`, select **Security Lists**, and click **Default Security List for masaar-vcn**.
6. Under **Ingress Rules**, click **Add Ingress Rules** and add the following rules:

| Source CIDR | IP Protocol | Destination Port Range | Description |
| :--- | :--- | :--- | :--- |
| `0.0.0.0/0` | TCP | `22` | SSH administrative access |
| `0.0.0.0/0` | TCP | `80` | HTTP Let's Encrypt ACME challenges & redirect |
| `0.0.0.0/0` | TCP | `443` | HTTPS secure API and portal traffic |

---

## Step 3: Compute Instance Provisioning

1. In the OCI Console, navigate to **Compute** > **Instances** and click **Create Instance**.
2. Configure instance parameters:
   - **Name**: `masaar-production-01`
   - **Compartment**: Your designated compartment.
   - **Placement**: Default Availability Domain in `me-jeddah-1`.
3. Under **Image and shape**:
   - Click **Change Image**: Select **Canonical Ubuntu**, version **24.04** (Minimal or Server, AArch64).
   - Click **Change Shape**: Select **Ampere** (Arm-based processor), choose `VM.Standard.A1.Flex`.
   - Allocate the Always Free quota:
     - **OCPUs**: `4`
     - **Memory (GB)**: `24`
4. Under **Networking**:
   - **Primary Network**: Select `masaar-vcn`.
   - **Subnet**: Select `Public Subnet-masaar-vcn`.
   - **Public IPv4 Address**: Select **Automatically assign public IPv4 address**.
5. Under **Add SSH keys**:
   - Select **Generate a key pair for me** and download both private and public keys, or paste your existing public SSH key.
6. Under **Boot volume**:
   - Check **Specify a custom boot volume size**: Enter `100` (or up to `200` GB to utilize full Always Free disk allocation).
7. Click **Create**. The instance status will change to **Running** within 60 to 90 seconds.
8. Note the assigned **Public IP address**.

### Converting to a Reserved Static Public IP (Free)
1. In the instance details page, under **Resources** on the left menu, click **Attached VNICs**.
2. Click the primary VNIC name.
3. Under **Resources**, click **IPv4 Addresses**.
4. Click the three dots on the assigned ephemeral IP and select **Edit**.
5. Change **Public IP Type** to **No Public IP** and click **Update**.
6. Immediately click **Edit** again, change **Public IP Type** to **Reserved Public IP**, and choose **Create a New Reserved IP** named `masaar-static-ip`. Click **Update**.
   *(OCI grants 1 Reserved Public IPv4 permanently free of charge).*

---

## Step 4: DNS Configuration

Point your custom domain (or subdomain) to the static public IP assigned to your instance:

1. In your DNS provider (e.g. Cloudflare Free tier, Namecheap, Route53, or local registrar):
   - Add an **A Record**:
     - **Name**: `masaar.yourdomain.com` (or `@` for apex domain)
     - **Value**: `<YOUR_RESERVED_STATIC_IP>`
     - **TTL**: Auto or 300 seconds
2. If using Cloudflare, set proxy status to **DNS Only (Grey Cloud)** during initial Let's Encrypt issuance.

---

## Step 5: Host Security & Firewall Configuration

Connect to your Ubuntu server via SSH:

```bash
ssh -i /path/to/private_key.key ubuntu@<YOUR_RESERVED_STATIC_IP>
```

OCI's Ubuntu images include local `iptables` drop rules by default that block ports 80 and 443 even if the OCI Security List permits them. Open them at the OS level:

```bash
# Update repositories and install required utilities
sudo apt update && sudo apt install -y iptables-persistent chrony curl git

# Insert ingress rules for HTTP and HTTPS before the drop line
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT

# Save the firewall rules so they persist across reboots
sudo netfilter-persistent save
```

### Clock Synchronization (<5s Tolerance)
ZATCA enforces strict cryptographic timestamp verification. If the server clock drifts more than 5 seconds from the official authority time servers, submissions are rejected:

```bash
# Verify systemd-timesyncd or chrony is active
sudo systemctl enable --now chrony
chronyc tracking
timedatectl
```
Ensure the output reports `System clock synchronized: yes` and `NTP service: active`.

---

## Step 6: Docker Engine & Compose Installation (ARM64)

Install native ARM64 Docker Engine and Docker Compose v2:

```bash
# Install Docker and Compose plugin
sudo apt install -y docker.io docker-compose-v2

# Allow running docker without sudo
sudo usermod -aG docker "$USER"

# Apply group membership
newgrp docker

# Verify native aarch64 architecture
docker version
docker compose version
```

---

## Step 7: Clone Repository & Configure Environment

Clone the repository and prepare your production environment file:

```bash
# Clone the repository
git clone https://github.com/shamil3ilm/masaar.git /home/ubuntu/masaar
cd /home/ubuntu/masaar

# Copy environment template
cp docker/.env.template .env
chmod 600 .env
```

### Generating Production Cryptographic Keys
Generate random high-entropy production keys using OpenSSL and insert them into your `.env`:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
echo "JWT_SECRET=$(openssl rand -hex 32)"
echo "API_KEY_PEPPER=$(openssl rand -base64 32)"
echo "ZATCA_CREDENTIAL_KEY=base64:$(openssl rand -base64 32)"
echo "AUDIT_CHAIN_KEY=base64:$(openssl rand -base64 32)"
echo "BACKUP_ARCHIVE_PASSWORD=$(openssl rand -base64 32)"
echo "DB_PASSWORD=$(openssl rand -hex 16)"
echo "DB_ROOT_PASSWORD=$(openssl rand -hex 16)"
```

### Required `.env` Production Settings
Edit your `.env` file (`nano .env`) and set the following parameters:

```env
APP_NAME=Masaar
APP_ENV=production
APP_DEBUG=false
APP_URL=https://masaar.yourdomain.com
APP_DOMAIN=masaar.yourdomain.com
SSL_EMAIL=ops@yourdomain.com

# Database (Internal Docker Network)
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=masaar
DB_USERNAME=masaar
DB_PASSWORD=<INSERT_GENERATED_DB_PASSWORD>
DB_ROOT_PASSWORD=<INSERT_GENERATED_DB_ROOT_PASSWORD>

# Compliance & ZATCA Configuration
ZATCA_ENVIRONMENT=simulation
ZATCA_ENABLED=true
SESSION_ENCRYPT=true
PLATFORM_LICENSE_ENABLED=false

# First-time deployment auto-migration
AUTO_MIGRATE=true
```

> [!CAUTION]
> Store your `ZATCA_CREDENTIAL_KEY`, `AUDIT_CHAIN_KEY`, and `BACKUP_ARCHIVE_PASSWORD` in a secure password manager. Losing `ZATCA_CREDENTIAL_KEY` permanently locks all encrypted device certificates.

---

## Step 8: Build and Start Containers

Launch the production stack using Docker Compose:

```bash
# Define alias for production compose files
alias dc='docker compose -f docker-compose.yml -f docker-compose.prod.yml'

# Build and start all services (app replicas, db, traefik)
dc up -d --build --wait --wait-timeout 600
```

Once all containers report healthy:

```bash
# Turn off AUTO_MIGRATE now that initial tables and triggers are created
sed -i 's/AUTO_MIGRATE=true/AUTO_MIGRATE=false/' .env

# Verify container health
dc ps
```

You should see:
- `traefik`: Up (ports 80 and 443 active, SSL certificate acquired)
- `db`: Up (healthy)
- `app`: Up (2 replicas running php-fpm, nginx, and queue workers)

---

## Step 9: Platform Admin Enrolment

Create your initial platform administrator account and enrol in Two-Factor Authentication (mandatory for `/admin` access):

```bash
# Create admin user
dc exec app php artisan platform:create-admin ops@yourdomain.com

# Generate 2FA enrolment link
dc exec app php artisan platform:enrolment-link ops@yourdomain.com
```

Open the printed enrolment link in your browser, scan the QR code with an authenticator app (Google Authenticator, 1Password, or Bitwarden), and record your backup codes.

---

## Step 10: Zero-Cost Backups to OCI Object Storage

OCI includes **10 GB Always Free Object Storage** in your home region (`me-jeddah-1`), compliant with Saudi data residency rules.

### Configure S3 Compatibility in OCI:
1. In the OCI Console, click your profile icon (top right) > **User settings**.
2. Under **Resources** on the left menu, click **Customer secret keys**.
3. Click **Generate secret key**, name it `masaar-backup`, and copy the **Access Key** and **Secret Key**.
4. In the console, go to **Storage** > **Buckets**, create a bucket named `masaar-backups` in your compartment.

### Automate Daily Encrypted Backup:
Create a daily cron job that dumps the database, encrypts the archive with `BACKUP_ARCHIVE_PASSWORD`, and pushes to the OCI bucket:

```bash
sudo crontab -e
```
Add the following daily entry (runs at 02:00 AM local time):
```cron
0 2 * * * /home/ubuntu/masaar/scripts/backup.sh >> /var/log/masaar-backup.log 2>&1
```

---

## Step 11: Verification & Preflight Checks

Run the platform diagnostic commands to verify that the installation is fully healthy and ready for ZATCA simulation:

```bash
# 1. Check HTTP readiness probe
curl -fsS https://masaar.yourdomain.com/api/health/ready

# 2. Verify queue workers and scheduler
dc exec app php artisan schedule:list

# 3. Verify cryptographic hash chain integrity
dc exec app php artisan fatoora:verify-hash-chain

# 4. Run system preflight checks
dc exec app php artisan platform:simulation-preflight --system-only
```

Once all checks pass, open `https://masaar.yourdomain.com/portal/zatca` in your browser to begin the 3-step simulation onboarding wizard.
