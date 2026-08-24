/**
 * Masaar Health Check Load Test
 *
 * Tests the /api/health endpoint under load
 * This is a baseline test - if this fails, nothing else will work
 *
 * Run: k6 run tests/Load/health-check.k6.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { BASE_URL, THRESHOLDS } from './k6-config.js';

// Custom metrics
const healthCheckDuration = new Trend('health_check_duration');
const healthCheckFailRate = new Rate('health_check_fail_rate');

export const options = {
    stages: [
        { duration: '10s', target: 50 },
        { duration: '30s', target: 100 },
        { duration: '1m', target: 200 },
        { duration: '30s', target: 0 },
    ],
    thresholds: {
        http_req_duration: ['p(95)<100', 'p(99)<200'], // Health should be fast
        http_req_failed: ['rate<0.001'], // 0.1% error rate max
        health_check_fail_rate: ['rate<0.001'],
    },
};

export default function () {
    const startTime = Date.now();

    const response = http.get(`${BASE_URL}/api/health`);

    const duration = Date.now() - startTime;
    healthCheckDuration.add(duration);

    const passed = check(response, {
        'status is 200': (r) => r.status === 200,
        'response has status ok': (r) => {
            try {
                const body = JSON.parse(r.body);
                return body.status === 'ok';
            } catch (e) {
                return false;
            }
        },
        'response time < 100ms': (r) => r.timings.duration < 100,
    });

    healthCheckFailRate.add(!passed);

    sleep(0.1); // 100ms between requests per VU
}

export function handleSummary(data) {
    return {
        'stdout': textSummary(data, { indent: ' ', enableColors: true }),
        'tests/Load/results/health-check-summary.json': JSON.stringify(data),
    };
}

function textSummary(data, options) {
    const duration = data.metrics.http_req_duration;
    const requests = data.metrics.http_reqs;
    const failed = data.metrics.http_req_failed;

    return `
╔══════════════════════════════════════════════════════════════╗
║           MASAAR HEALTH CHECK LOAD TEST RESULTS           ║
╠══════════════════════════════════════════════════════════════╣
║  Total Requests:     ${requests.values.count.toString().padStart(10)}                          ║
║  Request Rate:       ${requests.values.rate.toFixed(2).padStart(10)} req/s                    ║
║  Failed Requests:    ${(failed.values.rate * 100).toFixed(2).padStart(10)}%                        ║
╠══════════════════════════════════════════════════════════════╣
║  Response Times:                                             ║
║    Average:          ${duration.values.avg.toFixed(2).padStart(10)} ms                       ║
║    Median (p50):     ${duration.values['p(50)'].toFixed(2).padStart(10)} ms                       ║
║    p95:              ${duration.values['p(95)'].toFixed(2).padStart(10)} ms                       ║
║    p99:              ${duration.values['p(99)'].toFixed(2).padStart(10)} ms                       ║
║    Max:              ${duration.values.max.toFixed(2).padStart(10)} ms                       ║
╠══════════════════════════════════════════════════════════════╣
║  Status: ${failed.values.rate < 0.001 ? '✅ PASSED' : '❌ FAILED'}                                            ║
╚══════════════════════════════════════════════════════════════╝
`;
}
