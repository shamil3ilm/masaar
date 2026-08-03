# Masaar Load Testing

Load tests using [k6](https://k6.io) - a modern, developer-friendly load testing tool.

## Installation

### Windows (Chocolatey)
```bash
choco install k6
```

### Windows (Installer)
Download from: https://dl.k6.io/msi/k6-latest-amd64.msi

### macOS
```bash
brew install k6
```

### Linux
```bash
sudo apt-key adv --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update
sudo apt-get install k6
```

## Test Files

| File | Purpose | VUs | Duration |
|------|---------|-----|----------|
| `health-check.k6.js` | Baseline health endpoint test | 200 | ~2 min |
| `invoice-api.k6.js` | Invoice CRUD operations | 100 | ~5 min |
| `zatca-submission.k6.js` | Full ZATCA submission flow | 20 | ~8 min |
| `stress-test.k6.js` | Find breaking points | 500 | ~18 min |

## Running Tests

### 1. Health Check (Run First)
```bash
k6 run tests/Load/health-check.k6.js
```

### 2. Invoice API
```bash
# Set your API key
k6 run -e API_KEY=your_api_key_here tests/Load/invoice-api.k6.js
```

### 3. ZATCA Submission
```bash
# Use sandbox environment
k6 run -e API_KEY=your_api_key -e BASE_URL=http://localhost:8000 tests/Load/zatca-submission.k6.js
```

### 4. Stress Test
```bash
k6 run -e API_KEY=your_api_key tests/Load/stress-test.k6.js
```

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `BASE_URL` | API base URL | `http://localhost:8000` |
| `API_KEY` | API key for authentication | (required) |
| `TEST_EMAIL` | Test user email | `loadtest@example.com` |
| `TEST_PASSWORD` | Test user password | `LoadTest123!` |

## Test Against Different Environments

```bash
# Local development
k6 run -e BASE_URL=http://localhost:8000 -e API_KEY=key tests/Load/invoice-api.k6.js

# TaxFly Staging
k6 run -e BASE_URL=https://staging.masaar.taxfly.sa -e API_KEY=key tests/Load/invoice-api.k6.js

# Production (careful!)
k6 run -e BASE_URL=https://api.masaar.taxfly.sa -e API_KEY=key tests/Load/invoice-api.k6.js
```

## Expected Results

### Health Check
- **p95 < 100ms** (should be very fast)
- **Error rate < 0.1%**

### Invoice API
- **p95 < 500ms**
- **Error rate < 1%**
- **Throughput > 100 req/s**

### ZATCA Submission
- **p95 < 5000ms** (ZATCA API can be slow)
- **Error rate < 5%**
- **Success rate > 95%**

### Stress Test
- Identifies maximum capacity
- Acceptable degradation at 500 VUs
- **p99 < 2000ms** under stress

## Output Files

Results are saved to `tests/Load/results/`:
- `health-check-summary.json`
- `invoice-api-summary.json`
- `zatca-submission-summary.json`
- `stress-test-summary.json`

## CI/CD Integration

Add to GitHub Actions:

```yaml
- name: Run Load Tests
  run: |
    k6 run --out json=results.json tests/Load/health-check.k6.js
    k6 run -e API_KEY=${{ secrets.API_KEY }} tests/Load/invoice-api.k6.js
```

## Troubleshooting

### "connection refused"
- Ensure the server is running
- Check BASE_URL is correct

### "401 Unauthorized"
- Verify API_KEY is set correctly
- Check API key has required scopes

### High error rates
- Check server logs for errors
- Reduce VU count and retry
- Verify database connections
