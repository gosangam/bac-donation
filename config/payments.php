<?php

return [
    'org' => [
        'name' => env('ORG_NAME', 'Braj Animal Care'),
        'email' => env('ORG_EMAIL', 'brajanimalcare@gmail.com'),
        'phone' => env('ORG_PHONE', '+91 89237 37924'),
        'website' => env('ORG_WEBSITE', 'https://www.brajanimalcare.com'),
        'address' => env('ORG_ADDRESS', 'Bhakti Dhama, behind Iskcon Temple Gali, Raman Reiti, Vrindavan, Mathura, Uttar Pradesh 281121'),
        'logo_url' => env('ORG_LOGO_URL', 'https://www.shriradharaman.com/api/media/file/bac-logo.jpeg'),
        'brand_color' => env('ORG_BRAND_COLOR', '#f31824'),
        'pan' => env('ORG_PAN', ''),
        'registration_80g' => env('ORG_80G', ''),
    ],

    'default_currency' => env('DEFAULT_CURRENCY', 'INR'),

    // Guard rails on the free-amount field so a typo cannot create a ₹0 or
    // ₹10,00,000 "donation" that then has to be refunded.
    'one_off' => [
        'min' => (int) env('ONE_OFF_MIN', 100),        // minor units
        'max' => (int) env('ONE_OFF_MAX', 100000000),
        // Minor units. `presets` is the domestic ladder; per-currency keys are
        // looked up as presets_<CODE> and fall back to it when absent.
        'presets' => [50000, 100000, 250000, 500000],          // Rs 500 - Rs 5,000
        'presets_USD' => [1000, 2500, 5000, 10000],            // $10 - $100
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'total_count' => (int) env('RAZORPAY_TOTAL_COUNT', 120),
    ],

    'stripe' => [
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
    ],

    'paypal' => [
        'mode' => env('PAYPAL_MODE', 'sandbox'),   // sandbox | live
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    ],

    'receipts' => [
        // Turn OFF if the n8n workflows are emailing receipts, or donors get the
        // same receipt twice from two systems. Exactly one should own this.
        'email' => env('RECEIPT_EMAIL_ENABLED', true),
        'bcc' => array_filter(explode(',', (string) env('RECEIPT_EMAIL_BCC', ''))),
    ],

    // Gotenberg renders receipt PDFs. Same service the n8n workflows use.
    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://localhost:3000'),
    ],
];
