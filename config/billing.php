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

    'currency' => 'SAR',

    // Every currency the platform can invoice its customers in. Which are
    // on, and the default, are Admin › Finance › Settings (FinanceSettings):
    // at launch the first is on and the rest are ready, and off.
    'currencies' => ['SAR', 'AED'],

    // Every currency the platform's own suppliers may bill it in
    // (Admin › Finance › Expenses): those it invoices in, and those foreign
    // suppliers - hosting, subscriptions - usually charge. The daily rate
    // table keeps a rate for each of them.
    'expense_currencies' => ['SAR', 'AED', 'USD', 'EUR', 'GBP'],

    'vat_rate' => 15,

    // The card gateway: empty for none yet (card payments are not offered),
    // or simulator - a provider played inside the application for local
    // and staging, which production refuses to boot with. A provider is
    // added as a PaymentGateway named by purpose, never by the provider.
    'gateway' => env('PAYMENT_GATEWAY') ?: null,

    'simulator' => [
        // The share of each card payment the simulator keeps as its fee.
        'fee_percent' => (float) env('PAYMENT_SIMULATOR_FEE_PERCENT', 2.5),
    ],

    // Days before a plan ends that its organization's admins are reminded.
    'reminder_days' => [14, 3],

];
