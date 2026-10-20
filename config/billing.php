<?php

/**
 * Charging customers for their plan.
 *
 * Payments are recorded by hand - a bank transfer arrives, an admin records
 * it - and each one renews the customer's licence and is invoiced through
 * the platform itself, from the platform operator's own organization.
 */
return [

    // The organization the platform's own invoices are issued from: the
    // operator's company, set up, approved and onboarded with ZATCA like any
    // other. Empty: payments are recorded and renew licences, and no invoice
    // is issued for them until it is set.
    'organization_id' => env('BILLING_ORGANIZATION_ID'),

    // Monthly price of each plan, excluding VAT, in SAR. What the payment
    // form starts from; the amount actually received is what is recorded.
    'prices' => [
        'starter' => (int) env('BILLING_PRICE_STARTER', 500),
        'professional' => (int) env('BILLING_PRICE_PROFESSIONAL', 2000),
        'enterprise' => (int) env('BILLING_PRICE_ENTERPRISE', 5000),
        'unlimited' => (int) env('BILLING_PRICE_UNLIMITED', 0),
    ],

    'currency' => 'SAR',

    // Every currency the platform can invoice its customers in. Which are
    // on, and the default, are Admin › Finance › Settings (FinanceSettings):
    // at launch the first is on and the rest are ready, and off.
    'currencies' => ['SAR', 'AED'],

    'vat_rate' => 15,

    // Days before a plan ends that its organization's admins are reminded.
    'reminder_days' => [14, 3],

];
