/**
 * Masaar License Server - Cloudflare Worker
 *
 * This worker handles phone-home license validation requests from Masaar deployments.
 * Deploy to Cloudflare Workers (free tier: 100,000 requests/day).
 *
 * Setup:
 * 1. Create a Cloudflare account (free)
 * 2. Go to Workers & Pages
 * 3. Create a new Worker
 * 4. Paste this code
 * 5. Create a KV namespace called "LICENSES"
 * 6. Bind the KV namespace to this worker
 * 7. Add your signing secret as an environment variable: LICENSE_SECRET
 *
 * KV Data Structure:
 * Key: license key (e.g., "TAXFLY-TRIAL-20260303-a1b2c3d4")
 * Value: JSON { "partner": "TAXFLY", "type": "TRIAL", "expires": "2026-03-03", "revoked": false, "features": {...} }
 */

export default {
  async fetch(request, env) {
    // CORS headers for API access
    const corsHeaders = {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
    };

    // Handle CORS preflight
    if (request.method === 'OPTIONS') {
      return new Response(null, { headers: corsHeaders });
    }

    const url = new URL(request.url);

    // Route handling
    switch (url.pathname) {
      case '/validate':
        return handleValidate(request, env, corsHeaders);

      case '/register':
        return handleRegister(request, env, corsHeaders);

      case '/revoke':
        return handleRevoke(request, env, corsHeaders);

      case '/list':
        return handleList(request, env, corsHeaders);

      case '/usage':
        return handleUsageReport(request, env, corsHeaders);

      case '/usage/stats':
        return handleUsageStats(request, env, corsHeaders);

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
    const { admin_secret, license_key, partner, type, expires, features } = body;

    // Verify admin secret
    if (admin_secret !== env.ADMIN_SECRET) {
      return jsonResponse({ error: 'Unauthorized' }, corsHeaders, 401);
    }

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
    const { admin_secret, license_key, reason } = body;

    if (admin_secret !== env.ADMIN_SECRET) {
      return jsonResponse({ error: 'Unauthorized' }, corsHeaders, 401);
    }

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
    const url = new URL(request.url);
    const adminSecret = url.searchParams.get('admin_secret');

    if (adminSecret !== env.ADMIN_SECRET) {
      return jsonResponse({ error: 'Unauthorized' }, corsHeaders, 401);
    }

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
    const body = await request.json();
    const { license_key, metrics } = body;

    if (!license_key || !metrics) {
      return jsonResponse({ error: 'Missing license_key or metrics' }, corsHeaders, 400);
    }

    // Extract partner from license key
    const partner = license_key.split('-')[0];
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
    const adminSecret = url.searchParams.get('admin_secret');
    const partner = url.searchParams.get('partner');
    const period = url.searchParams.get('period') || 'month'; // 'day', 'month'

    if (adminSecret !== env.ADMIN_SECRET) {
      return jsonResponse({ error: 'Unauthorized' }, corsHeaders, 401);
    }

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
