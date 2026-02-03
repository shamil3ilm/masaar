/**
 * CompliPay ZATCA Submission Load Test
 *
 * Tests the ZATCA submission flow under load
 * This is the most critical test - simulates real production usage
 *
 * Run: k6 run -e API_KEY=your_api_key tests/Load/zatca-submission.k6.js
 */

import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import { BASE_URL, API_KEY, generateInvoice, getHeaders } from './k6-config.js';

// Custom metrics
const zatcaSubmitDuration = new Trend('zatca_submit_duration');
const zatcaGenerateDuration = new Trend('zatca_generate_duration');
const zatcaValidateDuration = new Trend('zatca_validate_duration');
const successfulSubmissions = new Counter('successful_submissions');
const failedSubmissions = new Counter('failed_submissions');
const submissionErrorRate = new Rate('submission_error_rate');

export const options = {
    // Conservative load for ZATCA - don't overwhelm the API
    stages: [
        { duration: '30s', target: 5 },    // Start slow
        { duration: '1m', target: 10 },    // Ramp to 10
        { duration: '2m', target: 20 },    // Ramp to 20
        { duration: '3m', target: 20 },    // Sustain 20 concurrent
        { duration: '1m', target: 0 },     // Ramp down
    ],
    thresholds: {
        http_req_failed: ['rate<0.05'],  // 5% error rate acceptable for ZATCA
        zatca_submit_duration: ['p(95)<5000'],  // 5 seconds for submission
        submission_error_rate: ['rate<0.1'],
    },
};

export function setup() {
    if (!API_KEY) {
        console.error('❌ API_KEY is required for ZATCA tests');
        console.error('   Run with: k6 run -e API_KEY=your_key ...');
    }
    return { apiKey: API_KEY };
}

export default function (data) {
    const headers = getHeaders(null, data.apiKey);
    const vuId = __VU;
    const iterationId = __ITER;

    let invoiceId = null;

    group('ZATCA Submission Flow', function () {

        // Step 1: Create Invoice
        group('1. Create Invoice', function () {
            const invoice = generateInvoice(`ZATCA-${vuId}-${iterationId}`);

            const response = http.post(
                `${BASE_URL}/v1/invoices`,
                JSON.stringify(invoice),
                { headers }
            );

            const success = check(response, {
                'invoice created': (r) => r.status === 201,
            });

            if (success) {
                try {
                    const body = JSON.parse(response.body);
                    invoiceId = body.data.id;
                } catch (e) {
                    console.error('Failed to parse invoice response');
                }
            }
        });

        if (!invoiceId) {
            failedSubmissions.add(1);
            submissionErrorRate.add(true);
            return;
        }

        sleep(0.5);

        // Step 2: Generate Compliance Data (Hash, QR)
        group('2. Generate Compliance', function () {
            const startTime = Date.now();

            const response = http.post(
                `${BASE_URL}/v1/compliance/zatca/generate/${invoiceId}`,
                null,
                { headers }
            );

            zatcaGenerateDuration.add(Date.now() - startTime);

            check(response, {
                'compliance generated': (r) => r.status === 200,
                'has hash': (r) => {
                    try {
                        const body = JSON.parse(r.body);
                        return body.data && body.data.hash;
                    } catch (e) {
                        return false;
                    }
                },
                'has qr_code': (r) => {
                    try {
                        const body = JSON.parse(r.body);
                        return body.data && body.data.qr_code;
                    } catch (e) {
                        return false;
                    }
                },
            });
        });

        sleep(0.3);

        // Step 3: Validate (Optional - can skip in load tests)
        group('3. Validate Invoice', function () {
            const startTime = Date.now();

            const response = http.post(
                `${BASE_URL}/v1/compliance/zatca/validate/${invoiceId}`,
                null,
                { headers }
            );

            zatcaValidateDuration.add(Date.now() - startTime);

            check(response, {
                'validation completed': (r) => r.status === 200 || r.status === 422,
            });
        });

        sleep(0.3);

        // Step 4: Submit to ZATCA
        group('4. Submit to ZATCA', function () {
            const startTime = Date.now();

            const response = http.post(
                `${BASE_URL}/v1/compliance/zatca/submit/${invoiceId}`,
                null,
                { headers, timeout: '30s' }
            );

            const duration = Date.now() - startTime;
            zatcaSubmitDuration.add(duration);

            const success = check(response, {
                'submission accepted': (r) => r.status === 200 || r.status === 202,
                'has zatca response': (r) => {
                    try {
                        const body = JSON.parse(r.body);
                        return body.data && (body.data.clearance_status || body.data.reporting_status);
                    } catch (e) {
                        return false;
                    }
                },
            });

            if (success) {
                successfulSubmissions.add(1);
                submissionErrorRate.add(false);
            } else {
                failedSubmissions.add(1);
                submissionErrorRate.add(true);

                // Log failure details
                console.error(`Submission failed: ${response.status} - ${response.body}`);
            }
        });
    });

    // Longer sleep between full flows to respect rate limits
    sleep(2);
}

export function handleSummary(data) {
    const submit = data.metrics.zatca_submit_duration || { values: {} };
    const generate = data.metrics.zatca_generate_duration || { values: {} };
    const successCount = data.metrics.successful_submissions?.values?.count || 0;
    const failCount = data.metrics.failed_submissions?.values?.count || 0;

    return {
        stdout: `
╔══════════════════════════════════════════════════════════════╗
║         COMPLIPAY ZATCA SUBMISSION LOAD TEST RESULTS         ║
╠══════════════════════════════════════════════════════════════╣
║  Submissions:                                                ║
║    Successful:       ${successCount.toString().padStart(10)}                          ║
║    Failed:           ${failCount.toString().padStart(10)}                          ║
║    Success Rate:     ${((successCount / (successCount + failCount)) * 100).toFixed(1).padStart(10)}%                        ║
╠══════════════════════════════════════════════════════════════╣
║  ZATCA Submit Duration:                                      ║
║    Average:          ${(submit.values.avg || 0).toFixed(0).padStart(10)} ms                       ║
║    p95:              ${(submit.values['p(95)'] || 0).toFixed(0).padStart(10)} ms                       ║
║    p99:              ${(submit.values['p(99)'] || 0).toFixed(0).padStart(10)} ms                       ║
╠══════════════════════════════════════════════════════════════╣
║  Generate Duration:                                          ║
║    Average:          ${(generate.values.avg || 0).toFixed(0).padStart(10)} ms                       ║
║    p95:              ${(generate.values['p(95)'] || 0).toFixed(0).padStart(10)} ms                       ║
╠══════════════════════════════════════════════════════════════╣
║  Status: ${failCount === 0 ? '✅ ALL PASSED' : '⚠️  SOME FAILED'}                                          ║
╚══════════════════════════════════════════════════════════════╝
`,
        'tests/Load/results/zatca-submission-summary.json': JSON.stringify(data),
    };
}
