<?php

return [
    'name' => '1262 Bryn Mawr Association',
    'address' => '1262 W. Bryn Mawr Ave, Chicago, IL 60660',
    'timezone' => 'America/Chicago',
    'documents_url' => 'https://drive.google.com/drive/folders/1XMLYnDQRZmxbejG50KfWQVxFJvpuuNjc',
    'billing_enabled' => env('BILLING_ENABLED', false),
    'billing_start_month' => env('BILLING_START_MONTH'),
    'billing_issue_day' => (int) env('BILLING_ISSUE_DAY', 1),
    'billing_due_days' => (int) env('BILLING_DUE_DAYS', 30),
    'invoice_emails_enabled' => env('INVOICE_EMAILS_ENABLED', false),
    'payment_instructions' => env('PAYMENT_INSTRUCTIONS', 'Contact Roy for payment instructions.'),
];
