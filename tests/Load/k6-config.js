/**
 * Masaar k6 Load Testing Configuration
 *
 * Shared configuration for all load tests
 */

// Base URL - change this for different environments
export const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';

// Test credentials
export const TEST_USER = {
    email: __ENV.TEST_EMAIL || 'loadtest@example.com',
    password: __ENV.TEST_PASSWORD || 'LoadTest123!',
    name: 'Load Test User'
};

// API Key (for server-to-server tests)
export const API_KEY = __ENV.API_KEY || '';

// Test thresholds
export const THRESHOLDS = {
    // 95% of requests should complete within 500ms
    http_req_duration: ['p(95)<500', 'p(99)<1000'],
    // Less than 1% error rate
    http_req_failed: ['rate<0.01'],
    // At least 100 requests per second
    http_reqs: ['rate>100'],
};

// Load stages for standard tests
export const STANDARD_STAGES = [
    { duration: '30s', target: 10 },   // Ramp up to 10 users
    { duration: '1m', target: 50 },    // Ramp up to 50 users
    { duration: '2m', target: 50 },    // Stay at 50 users
    { duration: '30s', target: 100 },  // Spike to 100 users
    { duration: '1m', target: 100 },   // Stay at 100 users
    { duration: '30s', target: 0 },    // Ramp down
];

// Load stages for stress tests
export const STRESS_STAGES = [
    { duration: '1m', target: 50 },
    { duration: '2m', target: 100 },
    { duration: '2m', target: 200 },
    { duration: '2m', target: 300 },
    { duration: '5m', target: 300 },   // Sustained high load
    { duration: '2m', target: 0 },
];

// Load stages for soak tests (long duration)
export const SOAK_STAGES = [
    { duration: '2m', target: 50 },
    { duration: '30m', target: 50 },   // 30 minutes sustained
    { duration: '2m', target: 0 },
];

// Helper: Generate random invoice data
export function generateInvoice(index) {
    return {
        invoice_number: `LOAD-${Date.now()}-${index}`,
        type: 'standard',
        issue_date: new Date().toISOString().split('T')[0],
        buyer_name: `Load Test Buyer ${index}`,
        buyer_vat_number: '300000000000003',
        lines: [
            {
                description: `Test Product ${index}`,
                quantity: Math.floor(Math.random() * 10) + 1,
                unit_price: (Math.random() * 1000 + 10).toFixed(2),
                tax_category: 'S',
                tax_rate: 15
            }
        ]
    };
}

// Helper: Standard headers
export function getHeaders(token = null, apiKey = null) {
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    };

    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
    }

    if (apiKey) {
        headers['X-API-Key'] = apiKey;
    }

    return headers;
}
