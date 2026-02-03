/**
 * CompliPay Stress Test
 *
 * Pushes the system to its limits to find breaking points
 * Run this to determine maximum capacity
 *
 * Run: k6 run -e API_KEY=your_api_key tests/Load/stress-test.k6.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import { BASE_URL, API_KEY, generateInvoice, getHeaders } from './k6-config.js';

// Metrics
const requestDuration = new Trend('request_duration');
const errorRate = new Rate('error_rate');
const successfulRequests = new Counter('successful_requests');
const failedRequests = new Counter('failed_requests');

export const options = {
    stages: [
        // Ramp up aggressively
        { duration: '1m', target: 50 },
        { duration: '2m', target: 100 },
        { duration: '2m', target: 200 },
        { duration: '2m', target: 300 },
        { duration: '2m', target: 400 },
        { duration: '2m', target: 500 },  // Target: 500 concurrent users
        { duration: '5m', target: 500 },  // Hold at peak
        { duration: '2m', target: 0 },    // Ramp down
    ],
    thresholds: {
        http_req_duration: ['p(95)<2000'],  // 2 seconds under stress
        error_rate: ['rate<0.1'],           // 10% error rate acceptable under stress
    },
};

export function setup() {
    return { apiKey: API_KEY };
}

export default function (data) {
    const headers = getHeaders(null, data.apiKey);
    const vuId = __VU;
    const iterationId = __ITER;

    // Mix of operations to simulate real traffic
    const operation = Math.random();

    if (operation < 0.4) {
        // 40% - Health checks (lightweight)
        const response = http.get(`${BASE_URL}/api/health`);
        const success = check(response, { 'health ok': (r) => r.status === 200 });
        trackResult(success, response.timings.duration);

    } else if (operation < 0.7) {
        // 30% - List invoices
        const response = http.get(`${BASE_URL}/v1/invoices?per_page=5`, { headers });
        const success = check(response, { 'list ok': (r) => r.status === 200 });
        trackResult(success, response.timings.duration);

    } else if (operation < 0.9) {
        // 20% - Create invoice
        const invoice = generateInvoice(`STRESS-${vuId}-${iterationId}`);
        const response = http.post(
            `${BASE_URL}/v1/invoices`,
            JSON.stringify(invoice),
            { headers }
        );
        const success = check(response, { 'create ok': (r) => r.status === 201 });
        trackResult(success, response.timings.duration);

    } else {
        // 10% - Dashboard (heavier query)
        const response = http.get(`${BASE_URL}/v1/dashboard`, { headers });
        const success = check(response, { 'dashboard ok': (r) => r.status === 200 });
        trackResult(success, response.timings.duration);
    }

    sleep(0.1);
}

function trackResult(success, duration) {
    requestDuration.add(duration);
    if (success) {
        successfulRequests.add(1);
        errorRate.add(false);
    } else {
        failedRequests.add(1);
        errorRate.add(true);
    }
}

export function handleSummary(data) {
    const duration = data.metrics.http_req_duration?.values || {};
    const reqs = data.metrics.http_reqs?.values || {};
    const errors = data.metrics.error_rate?.values || {};

    const maxVUs = Math.max(...(data.metrics.vus?.values?.value ? [data.metrics.vus.values.value] : [0]));

    return {
        stdout: `
╔══════════════════════════════════════════════════════════════╗
║             COMPLIPAY STRESS TEST RESULTS                    ║
╠══════════════════════════════════════════════════════════════╣
║  Peak Virtual Users: ${maxVUs.toString().padStart(10)}                          ║
║  Total Requests:     ${(reqs.count || 0).toString().padStart(10)}                          ║
║  Request Rate:       ${(reqs.rate || 0).toFixed(1).padStart(10)} req/s                    ║
║  Error Rate:         ${((errors.rate || 0) * 100).toFixed(2).padStart(10)}%                        ║
╠══════════════════════════════════════════════════════════════╣
║  Response Times:                                             ║
║    Average:          ${(duration.avg || 0).toFixed(0).padStart(10)} ms                       ║
║    p50 (Median):     ${(duration['p(50)'] || 0).toFixed(0).padStart(10)} ms                       ║
║    p95:              ${(duration['p(95)'] || 0).toFixed(0).padStart(10)} ms                       ║
║    p99:              ${(duration['p(99)'] || 0).toFixed(0).padStart(10)} ms                       ║
║    Max:              ${(duration.max || 0).toFixed(0).padStart(10)} ms                       ║
╠══════════════════════════════════════════════════════════════╣
║  Breaking Point Analysis:                                    ║
║    If p99 > 2000ms or error_rate > 10%, system is stressed   ║
╠══════════════════════════════════════════════════════════════╣
║  Status: ${(errors.rate || 0) < 0.1 && (duration['p(99)'] || 0) < 2000 ? '✅ PASSED' : '⚠️  STRESS DETECTED'}                                        ║
╚══════════════════════════════════════════════════════════════╝
`,
        'tests/Load/results/stress-test-summary.json': JSON.stringify(data),
    };
}
