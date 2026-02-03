/**
 * CompliPay Invoice API Load Test
 *
 * Tests the full invoice CRUD operations under load
 *
 * Run: k6 run -e API_KEY=your_api_key tests/Load/invoice-api.k6.js
 */

import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import { BASE_URL, API_KEY, STANDARD_STAGES, generateInvoice, getHeaders } from './k6-config.js';

// Custom metrics
const invoiceCreateDuration = new Trend('invoice_create_duration');
const invoiceListDuration = new Trend('invoice_list_duration');
const invoiceGetDuration = new Trend('invoice_get_duration');
const invoicesCreated = new Counter('invoices_created');
const invoiceErrors = new Rate('invoice_error_rate');

export const options = {
    stages: STANDARD_STAGES,
    thresholds: {
        http_req_duration: ['p(95)<500', 'p(99)<1000'],
        http_req_failed: ['rate<0.01'],
        invoice_create_duration: ['p(95)<800'],
        invoice_error_rate: ['rate<0.05'],
    },
};

// Store created invoice IDs for later operations
const createdInvoices = [];

export function setup() {
    // Verify API key is provided
    if (!API_KEY) {
        console.warn('⚠️  No API_KEY provided. Run with: k6 run -e API_KEY=your_key ...');
        console.warn('⚠️  Tests will likely fail without authentication.');
    }

    return { apiKey: API_KEY };
}

export default function (data) {
    const headers = getHeaders(null, data.apiKey);
    const vuId = __VU;
    const iterationId = __ITER;

    group('Invoice Operations', function () {
        // CREATE Invoice
        group('Create Invoice', function () {
            const invoice = generateInvoice(`${vuId}-${iterationId}`);
            const startTime = Date.now();

            const createResponse = http.post(
                `${BASE_URL}/v1/invoices`,
                JSON.stringify(invoice),
                { headers }
            );

            invoiceCreateDuration.add(Date.now() - startTime);

            const createSuccess = check(createResponse, {
                'create status is 201': (r) => r.status === 201,
                'create returns invoice id': (r) => {
                    try {
                        const body = JSON.parse(r.body);
                        if (body.data && body.data.id) {
                            createdInvoices.push(body.data.id);
                            return true;
                        }
                        return false;
                    } catch (e) {
                        return false;
                    }
                },
            });

            if (createSuccess) {
                invoicesCreated.add(1);
            }
            invoiceErrors.add(!createSuccess);
        });

        sleep(0.5);

        // LIST Invoices
        group('List Invoices', function () {
            const startTime = Date.now();

            const listResponse = http.get(
                `${BASE_URL}/v1/invoices?per_page=10`,
                { headers }
            );

            invoiceListDuration.add(Date.now() - startTime);

            const listSuccess = check(listResponse, {
                'list status is 200': (r) => r.status === 200,
                'list returns array': (r) => {
                    try {
                        const body = JSON.parse(r.body);
                        return Array.isArray(body.data);
                    } catch (e) {
                        return false;
                    }
                },
            });

            invoiceErrors.add(!listSuccess);
        });

        sleep(0.3);

        // GET Single Invoice (if we have any)
        if (createdInvoices.length > 0) {
            group('Get Invoice', function () {
                const invoiceId = createdInvoices[Math.floor(Math.random() * createdInvoices.length)];
                const startTime = Date.now();

                const getResponse = http.get(
                    `${BASE_URL}/v1/invoices/${invoiceId}`,
                    { headers }
                );

                invoiceGetDuration.add(Date.now() - startTime);

                const getSuccess = check(getResponse, {
                    'get status is 200': (r) => r.status === 200,
                    'get returns invoice': (r) => {
                        try {
                            const body = JSON.parse(r.body);
                            return body.data && body.data.id === invoiceId;
                        } catch (e) {
                            return false;
                        }
                    },
                });

                invoiceErrors.add(!getSuccess);
            });
        }
    });

    sleep(1);
}

export function teardown(data) {
    console.log(`\n📊 Total invoices created during test: ${createdInvoices.length}`);
}
