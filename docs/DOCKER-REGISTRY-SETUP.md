# Docker Registry Setup Guide

This guide explains how to publish CompliPay Docker images to GitHub Container Registry (free).

## Quick Start

### 1. Enable GitHub Packages

1. Go to your repository on GitHub
2. Settings → Actions → General
3. Ensure "Read and write permissions" is enabled for GITHUB_TOKEN

### 2. Build & Push (Automated)

Simply create a version tag:

```bash
# Tag a new version
git tag v1.0.0
git push origin v1.0.0

# GitHub Actions will automatically:
# - Build the Docker image
# - Push to ghcr.io/YOUR_USERNAME/zatca:1.0.0
# - Push to ghcr.io/YOUR_USERNAME/zatca:latest
```

### 3. Build & Push (Manual)

```bash
# Login to GitHub Container Registry
echo $GITHUB_TOKEN | docker login ghcr.io -u YOUR_USERNAME --password-stdin

# Build the image
docker build -t ghcr.io/YOUR_USERNAME/zatca:1.0.0 .

# Push to registry
docker push ghcr.io/YOUR_USERNAME/zatca:1.0.0
```

## For TaxFly Deployment

### Give TaxFly Access

1. Go to your GitHub package: `https://github.com/users/YOUR_USERNAME/packages/container/zatca`
2. Click "Package settings"
3. Under "Manage access", add TaxFly's GitHub username or organization
4. Grant "Read" permission

### TaxFly Pull Command

```bash
# TaxFly logs in with their GitHub token
echo $TAXFLY_GITHUB_TOKEN | docker login ghcr.io -u taxfly --password-stdin

# Pull the image
docker pull ghcr.io/YOUR_USERNAME/zatca:1.0.0

# Run with docker-compose
docker-compose up -d
```

## Image Naming Convention

| Tag | Description |
|-----|-------------|
| `v1.0.0` | Specific version (recommended for production) |
| `v1.0` | Minor version (latest patch) |
| `v1` | Major version (latest minor + patch) |
| `latest` | Most recent build (use for testing only) |

## Updating docker-compose.yml for TaxFly

Update the image reference in `docker-compose.yml`:

```yaml
services:
  app:
    image: ghcr.io/YOUR_USERNAME/zatca:${IMAGE_TAG:-1.0.0}
```

## Cost

**$0** - GitHub Container Registry is free for public and private packages.

## Alternative: Docker Hub

If you prefer Docker Hub:

```bash
# Login
docker login -u YOUR_DOCKERHUB_USERNAME

# Build and push
docker build -t YOUR_DOCKERHUB_USERNAME/complipay:1.0.0 .
docker push YOUR_DOCKERHUB_USERNAME/complipay:1.0.0
```

Docker Hub offers 1 free private repository.
