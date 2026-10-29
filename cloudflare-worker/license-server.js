/**
 * License server - Cloudflare Worker
 *
 * This worker handles phone-home license validation requests from platform deployments.
 * Deploy to Cloudflare Workers (free tier: 100,000 requests/day).
 *
 * Setup:
 * 1. Create a Cloudflare account (free)
 * 2. Go to Workers & Pages
 * 3. Create a new Worker
 * 4. Paste this code
 * 5. Create a KV namespace called "LICENSES"
 * 6. Bind the KV namespace to this worker
 * 7. Add the signing secret as a secret: LICENSE_SECRET. It must equal the
 *    deployments' PLATFORM_LICENSE_SECRET; usage reports are signed with it.
 * 8. Add the admin secret as a secret: ADMIN_SECRET, at least 32 characters
 *    (for example the output of: openssl rand -hex 32). Without it every
 *    admin call is refused.
 *
 * Admin calls (/register, /revoke, /list, /usage/stats) send the secret in the
 * X-Admin-Secret header only - never in the query string, which access logs
 * record, nor in a body. They get no CORS headers, are limited per address,
 * and repeated wrong secrets lock the address out for a while.
 *
 * /usage takes a report signed by the deployment: X-Usage-Timestamp (Unix
 * seconds) and X-Usage-Signature, hex HMAC-SHA256 under LICENSE_SECRET of
 * "<timestamp>.<raw body>", for a licence registered here and not revoked.
 *
 * KV Data Structure:
 * Key: license key (e.g., "ACME-TRIAL-YYYYMMDD-<64 hex>": the whole HMAC-SHA256)
 * Value: JSON { "partner": "ACME", "type": "TRIAL", "expires": "YYYY-MM-DD", "revoked": false, "features": {...} }
 */

const ADMIN_PATHS = ['/register', '/revoke', '/list', '/usage/stats'];

/** Shortest ADMIN_SECRET accepted; anything shorter refuses every admin call. */
const MIN_ADMIN_SECRET_LENGTH = 32;

/** Admin calls a minute from one address. */
const ADMIN_CALLS_PER_MINUTE = 60;

/** Failed authentications (admin or usage) from one address per window. */
const AUTH_FAILURES_PER_WINDOW = 10;
const AUTH_FAILURE_WINDOW_SECONDS = 600;

/** How far a signed usage report's timestamp may be from now. */
const USAGE_SIGNATURE_MAX_AGE_SECONDS = 300;

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    // Admin routes: no CORS, so no browser page can call them, and the gate
    // runs before any handler.
    if (ADMIN_PATHS.includes(url.pathname)) {
      if (request.method === 'OPTIONS') {
        return new Response(null, { status: 204 });
      }

      const refusal = await adminRefusal(request, env);
      if (refusal) {
        return refusal;
      }

      switch (url.pathname) {
        case '/register':
          return handleRegister(request, env, {});
        case '/revoke':
          return handleRevoke(request, env, {});
        case '/list':
          return handleList(request, env, {});
        default:
          return handleUsageStats(request, env, {});
      }
    }

    // CORS headers for the public routes
    const corsHeaders = {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
    };

    // Handle CORS preflight
    if (request.method === 'OPTIONS') {
      return new Response(null, { headers: corsHeaders });
    }

    // Route handling
    switch (url.pathname) {
      case '/validate':
        return handleValidate(request, env, corsHeaders);

      case '/usage':
        return handleUsageReport(request, env, corsHeaders);

      case '/health':
        return jsonResponse({ status: 'ok', timestamp: new Date().toISOString() }, corsHeaders);

      default:
        return jsonResponse({ error: 'Not found' }, corsHeaders, 404);
    }
  },
};

/**
 * Validate a license key
 */
async function handleValidate(request, env, corsHeaders) {
  try {
    const body = await request.json();
    const { license_key, domain, ip, version } = body;

    if (!license_key) {
      return jsonResponse({
        valid: false,
        message: 'No license key provided',
      }, corsHeaders, 400);
    }

    // Look up license in KV store
    const licenseData = await env.LICENSES.get(license_key, 'json');

    if (!licenseData) {
      // License not in KV - could be valid offline key, let client handle
      return jsonResponse({
        valid: false,
        message: 'License not found in registry',
        fallback_to_offline: true,
      }, corsHeaders);
    }

    // Check if revoked
    if (licenseData.revoked) {
      return jsonResponse({
        valid: false,
        message: 'License has been revoked',
      }, corsHeaders);
    }

    // Check expiration
    const expiresAt = new Date(licenseData.expires);
    const now = new Date();

    if (now > expiresAt) {
      const daysSinceExpiry = Math.floor((now - expiresAt) / (1000 * 60 * 60 * 24));
      return jsonResponse({
        valid: false,
        message: `License expired ${daysSinceExpiry} days ago`,
      }, corsHeaders);
    }

    const daysRemaining = Math.floor((expiresAt - now) / (1000 * 60 * 60 * 24));

    // Log the validation (for analytics)
    await logValidation(env, license_key, domain, ip, version, true);

    return jsonResponse({
      valid: true,
      message: 'License valid',
      partner: licenseData.partner,
      type: licenseData.type,
      expires_at: licenseData.expires,
      days_remaining: daysRemaining,
      features: licenseData.features || {},
    }, corsHeaders);

  } catch (error) {
    console.error('Validation error:', error);
    return jsonResponse({
      valid: false,
      message: 'Validation error',
      fallback_to_offline: true,
    }, corsHeaders, 500);
  }
}

/**
 * Register a new license in KV store
 * Requires admin secret
 */
async function handleRegister(request, env, corsHeaders) {
  try {
    const body = await request.json();
    const { license_key, partner, type, expires, features } = body;

    if (!license_key || !partner || !type || !expires) {
      return jsonResponse({ error: 'Missing required fields' }, corsHeaders, 400);
    }

    const licenseData = {
      partner,
      type,
      expires,
      features: features || getDefaultFeatures(type),
      revoked: false,
      created_at: new Date().toISOString(),
    };

    await env.LICENSES.put(license_key, JSON.stringify(licenseData));

    return jsonResponse({
      success: true,
      message: 'License registered',
      license_key,
      data: licenseData,
    }, corsHeaders);

  } catch (error) {
    console.error('Register error:', error);
    return jsonResponse({ error: 'Registration failed' }, corsHeaders, 500);
  }
}

/**
 * Revoke a license
 * Requires admin secret
 */
async function handleRevoke(request, env, corsHeaders) {
  try {
    const body = await request.json();
    const { license_key, reason } = body;

    if (!license_key) {
      return jsonResponse({ error: 'Missing license_key' }, corsHeaders, 400);
    }

    const licenseData = await env.LICENSES.get(license_key, 'json');

    if (!licenseData) {
      return jsonResponse({ error: 'License not found' }, corsHeaders, 404);
    }

    licenseData.revoked = true;
    licenseData.revoked_at = new Date().toISOString();
    licenseData.revoke_reason = reason || 'No reason provided';

    await env.LICENSES.put(license_key, JSON.stringify(licenseData));

    return jsonResponse({
      success: true,
      message: 'License revoked',
      license_key,
    }, corsHeaders);

  } catch (error) {
    console.error('Revoke error:', error);
    return jsonResponse({ error: 'Revocation failed' }, corsHeaders, 500);
  }
}

/**
 * List all licenses (admin only)
 */
async function handleList(request, env, corsHeaders) {
  try {
    const list = await env.LICENSES.list();
    const licenses = [];

    for (const key of list.keys) {
      const data = await env.LICENSES.get(key.name, 'json');
      licenses.push({
        key: key.name,
        ...data,
      });
    }

    return jsonResponse({
      count: licenses.length,
      licenses,
    }, corsHeaders);

  } catch (error) {
    console.error('List error:', error);
    return jsonResponse({ error: 'List failed' }, corsHeaders, 500);
  }
}

/**
 * Log validation for analytics
 */
async function logValidation(env, licenseKey, domain, ip, version, success) {
  // You could store validation logs in another KV namespace or send to analytics
  // For now, just console log (visible in Cloudflare dashboard)
  console.log(JSON.stringify({
    event: 'license_validation',
    license_key: licenseKey.substring(0, 20) + '...',
    domain,
    ip,
    version,
    success,
    timestamp: new Date().toISOString(),
  }));
}

/**
 * Get default features for license type
 */
function getDefaultFeatures(type) {
  switch (type) {
    case 'TRIAL':
      return {
        max_invoices_per_month: 500,
        max_organizations: 5,
        support: 'email',
        api_rate_limit: 100,
      };
    case 'PROD':
      return {
        max_invoices_per_month: -1,
        max_organizations: -1,
        support: 'priority',
        api_rate_limit: 10000,
      };
    case 'DEV':
      return {
        max_invoices_per_month: 100,
        max_organizations: 2,
        support: 'community',
        api_rate_limit: 50,
      };
    default:
      return {};
  }
}

/**
 * Handle usage report from partner deployments
 * Partners report their usage metrics periodically
 */
async function handleUsageReport(request, env, corsHeaders) {
  try {
    // Fail closed: without the signing secret no report can be verified.
    if (typeof env.LICENSE_SECRET !== 'string' || env.LICENSE_SECRET === '') {
      console.error('Usage report refused: LICENSE_SECRET is not set');
      return jsonResponse({ error: 'Usage reporting not configured' }, corsHeaders, 503);
    }

    const ip = clientAddress(request);
    if (await overLimit(env, failureBucket(ip), AUTH_FAILURES_PER_WINDOW, AUTH_FAILURE_WINDOW_SECONDS)) {
      return jsonResponse({ error: 'Too many failed attempts' }, corsHeaders, 429);
    }

    const raw = await request.text();
    if (!(await usageSignatureValid(request, env, raw))) {
      await countHit(env, failureBucket(ip), AUTH_FAILURE_WINDOW_SECONDS);
      console.warn(JSON.stringify({ event: 'usage_report_unsigned', ip }));
      return jsonResponse({ error: 'Unauthorized' }, corsHeaders, 401);
    }

    const { license_key, metrics } = JSON.parse(raw);

    if (!license_key || !metrics) {
      return jsonResponse({ error: 'Missing license_key or metrics' }, corsHeaders, 400);
    }

    // Only a licence registered here and not revoked reports usage, and the
    // partner is the registry's, not whatever the key's prefix claims.
    const licenseData = await env.LICENSES.get(license_key, 'json');
    if (!licenseData || licenseData.revoked) {
      return jsonResponse({ error: 'Unknown or revoked licence' }, corsHeaders, 403);
    }

    const partner = licenseData.partner;
    const today = new Date().toISOString().split('T')[0];
    const usageKey = `usage:${partner}:${today}`;

    // Get existing usage for today or create new
    let dailyUsage = await env.LICENSES.get(usageKey, 'json') || {
      partner,
      date: today,
      invoices_created: 0,
      invoices_submitted: 0,
      invoices_cleared: 0,
      invoices_reported: 0,
      organizations_count: 0,
      api_calls: 0,
      reports: [],
    };

    // Accumulate metrics
    dailyUsage.invoices_created += metrics.invoices_created || 0;
    dailyUsage.invoices_submitted += metrics.invoices_submitted || 0;
    dailyUsage.invoices_cleared += metrics.invoices_cleared || 0;
    dailyUsage.invoices_reported += metrics.invoices_reported || 0;
    dailyUsage.organizations_count = metrics.organizations_count || dailyUsage.organizations_count;
    dailyUsage.api_calls += metrics.api_calls || 0;
    dailyUsage.last_report = new Date().toISOString();
    dailyUsage.reports.push({
      timestamp: new Date().toISOString(),
      metrics,
    });

    // Keep only last 24 reports per day
    if (dailyUsage.reports.length > 24) {
      dailyUsage.reports = dailyUsage.reports.slice(-24);
    }

    // Store with 90-day expiration
    await env.LICENSES.put(usageKey, JSON.stringify(dailyUsage), {
      expirationTtl: 90 * 24 * 60 * 60, // 90 days
    });

    // Also update monthly aggregate
    const month = today.substring(0, 7); // YYYY-MM
    const monthlyKey = `usage:${partner}:${month}`;
    let monthlyUsage = await env.LICENSES.get(monthlyKey, 'json') || {
      partner,
      month,
      total_invoices_created: 0,
      total_invoices_submitted: 0,
      total_invoices_cleared: 0,
      total_invoices_reported: 0,
      peak_organizations: 0,
      total_api_calls: 0,
      days_active: 0,
      first_report: new Date().toISOString(),
    };

    monthlyUsage.total_invoices_created += metrics.invoices_created || 0;
    monthlyUsage.total_invoices_submitted += metrics.invoices_submitted || 0;
    monthlyUsage.total_invoices_cleared += metrics.invoices_cleared || 0;
    monthlyUsage.total_invoices_reported += metrics.invoices_reported || 0;
    monthlyUsage.peak_organizations = Math.max(
      monthlyUsage.peak_organizations,
      metrics.organizations_count || 0
    );
    monthlyUsage.total_api_calls += metrics.api_calls || 0;
    monthlyUsage.last_report = new Date().toISOString();

    await env.LICENSES.put(monthlyKey, JSON.stringify(monthlyUsage), {
      expirationTtl: 365 * 24 * 60 * 60, // 1 year
    });

    console.log(JSON.stringify({
      event: 'usage_report',
      partner,
      metrics,
      timestamp: new Date().toISOString(),
    }));

    return jsonResponse({
      success: true,
      message: 'Usage recorded',
    }, corsHeaders);

  } catch (error) {
    console.error('Usage report error:', error);
    return jsonResponse({ error: 'Failed to record usage' }, corsHeaders, 500);
  }
}

/**
 * Get usage statistics (admin only)
 */
async function handleUsageStats(request, env, corsHeaders) {
  try {
    const url = new URL(request.url);
    const partner = url.searchParams.get('partner');
    const period = url.searchParams.get('period') || 'month'; // 'day', 'month'

    if (!partner) {
      // Return all partners' current month stats
      const list = await env.LICENSES.list({ prefix: 'usage:' });
      const stats = [];
      const currentMonth = new Date().toISOString().substring(0, 7);

      for (const key of list.keys) {
        if (key.name.includes(`:${currentMonth}`)) {
          const data = await env.LICENSES.get(key.name, 'json');
          if (data) {
            stats.push(data);
          }
        }
      }

      return jsonResponse({
        period: currentMonth,
        partners: stats,
      }, corsHeaders);
    }

    // Get specific partner stats
    const today = new Date().toISOString().split('T')[0];
    const currentMonth = today.substring(0, 7);

    const dailyKey = `usage:${partner}:${today}`;
    const monthlyKey = `usage:${partner}:${currentMonth}`;

    const dailyUsage = await env.LICENSES.get(dailyKey, 'json');
    const monthlyUsage = await env.LICENSES.get(monthlyKey, 'json');

    return jsonResponse({
      partner,
      today: dailyUsage || { message: 'No data for today' },
      month: monthlyUsage || { message: 'No data for this month' },
    }, corsHeaders);

  } catch (error) {
    console.error('Usage stats error:', error);
    return jsonResponse({ error: 'Failed to retrieve stats' }, corsHeaders, 500);
  }
}

/**
 * Why an admin call is refused, or null to let it through.
 *
 * Fails closed when ADMIN_SECRET is unset or short (503, logged), reads the
 * secret from the X-Admin-Secret header only, compares it in constant time,
 * and limits both admin calls and failed attempts per address.
 */
async function adminRefusal(request, env) {
  if (typeof env.ADMIN_SECRET !== 'string' || env.ADMIN_SECRET.length < MIN_ADMIN_SECRET_LENGTH) {
    console.error(`Admin call refused: ADMIN_SECRET is not set or shorter than ${MIN_ADMIN_SECRET_LENGTH} characters`);
    return jsonResponse({ error: 'Admin API not configured' }, {}, 503);
  }

  const ip = clientAddress(request);

  if (await overLimit(env, failureBucket(ip), AUTH_FAILURES_PER_WINDOW, AUTH_FAILURE_WINDOW_SECONDS)) {
    return jsonResponse({ error: 'Too many failed attempts' }, {}, 429);
  }

  if (!(await secretsMatch(request.headers.get('X-Admin-Secret'), env.ADMIN_SECRET))) {
    await countHit(env, failureBucket(ip), AUTH_FAILURE_WINDOW_SECONDS);
    console.warn(JSON.stringify({ event: 'admin_auth_failed', ip }));
    return jsonResponse({ error: 'Unauthorized' }, {}, 401);
  }

  const calls = `ratelimit:admin:${ip}`;
  if (await overLimit(env, calls, ADMIN_CALLS_PER_MINUTE, 60)) {
    return jsonResponse({ error: 'Too many requests' }, { 'Retry-After': '60' }, 429);
  }
  await countHit(env, calls, 60);

  return null;
}

/**
 * Whether a usage report carries a fresh signature over its exact body:
 * hex HMAC-SHA256 under LICENSE_SECRET of "<X-Usage-Timestamp>.<body>".
 */
async function usageSignatureValid(request, env, raw) {
  const timestamp = request.headers.get('X-Usage-Timestamp') || '';
  const signature = (request.headers.get('X-Usage-Signature') || '').toLowerCase();

  if (!/^\d+$/.test(timestamp) || Math.abs(Date.now() / 1000 - Number(timestamp)) > USAGE_SIGNATURE_MAX_AGE_SECONDS) {
    return false;
  }

  const key = await crypto.subtle.importKey(
    'raw', new TextEncoder().encode(env.LICENSE_SECRET), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'],
  );
  const mac = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(`${timestamp}.${raw}`));
  const expected = [...new Uint8Array(mac)].map((b) => b.toString(16).padStart(2, '0')).join('');

  return secretsMatch(signature, expected);
}

/**
 * Constant-time equality of two strings: SHA-256 digests compared byte by
 * byte, so neither the length nor a matching prefix shows in the timing.
 */
async function secretsMatch(given, expected) {
  if (typeof given !== 'string' || given === '' || typeof expected !== 'string' || expected === '') {
    return false;
  }

  const encoder = new TextEncoder();
  const [a, b] = await Promise.all([
    crypto.subtle.digest('SHA-256', encoder.encode(given)),
    crypto.subtle.digest('SHA-256', encoder.encode(expected)),
  ]);
  const x = new Uint8Array(a);
  const y = new Uint8Array(b);
  let diff = 0;
  for (let i = 0; i < x.length; i++) {
    diff |= x[i] ^ y[i];
  }

  return diff === 0;
}

function clientAddress(request) {
  return request.headers.get('CF-Connecting-IP') || 'unknown';
}

function failureBucket(ip) {
  return `ratelimit:auth-fail:${ip}`;
}

/** The fixed-window counter key for now. */
function windowKey(bucket, windowSeconds) {
  return `${bucket}:${Math.floor(Date.now() / 1000 / windowSeconds)}`;
}

/** Whether the bucket has reached its limit in the current window. */
async function overLimit(env, bucket, limit, windowSeconds) {
  const count = Number(await env.LICENSES.get(windowKey(bucket, windowSeconds))) || 0;

  return count >= limit;
}

/** Count one hit in the current window (KV is eventually consistent, so approximate). */
async function countHit(env, bucket, windowSeconds) {
  const key = windowKey(bucket, windowSeconds);
  const count = Number(await env.LICENSES.get(key)) || 0;
  await env.LICENSES.put(key, String(count + 1), { expirationTtl: Math.max(60, windowSeconds * 2) });
}

/**
 * JSON response helper
 */
function jsonResponse(data, corsHeaders, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: {
      'Content-Type': 'application/json',
      ...corsHeaders,
    },
  });
}
