<?php

return [
    'assessment_percentages' => [1 => 14, 2 => 21, 3 => 21, 4 => 21, 5 => 23],
    'initial_roster_base64' => env('ASSOCIATION_INITIAL_ROSTER_BASE64'),
    'name' => '1262 Bryn Mawr Association',
    'address' => '1262 W. Bryn Mawr Ave, Chicago, IL 60660',
    'timezone' => 'America/Chicago',
    'documents_url' => 'https://drive.google.com/drive/folders/1XMLYnDQRZmxbejG50KfWQVxFJvpuuNjc',
    'billing_enabled' => env('BILLING_ENABLED', false),
    'billing_start_month' => env('BILLING_START_MONTH'),
    'billing_issue_day' => (int) env('BILLING_ISSUE_DAY', 1),
    'billing_due_days' => (int) env('BILLING_DUE_DAYS', 14),
    'invoice_emails_enabled' => env('INVOICE_EMAILS_ENABLED', false),
    'payment_instructions' => env('PAYMENT_INSTRUCTIONS', "Pay via Zelle: 1262brynmawr@gmail.com\nPay by check to: 1262 W Bryn Mawr #3, Chicago IL 60660"),
];
