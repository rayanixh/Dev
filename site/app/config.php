<?php
/**
 * Configuration. Copy this file's values into config.php, or set the env vars.
 * Everything has a safe default so the site runs out of the box on SQLite.
 */
declare(strict_types=1);

return [
    // 'sqlite' (zero setup) or 'mysql' (typical shared hosting)
    'driver' => getenv('DB_DRIVER') ?: 'sqlite',

    // sqlite
    'db_path' => getenv('DB_PATH') ?: __DIR__ . '/../db/app.sqlite',

    // mysql
    'mysql' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('DB_PORT') ?: 3306),
        'name'    => getenv('DB_NAME') ?: 'pixeltopup',
        'user'    => getenv('DB_USER') ?: 'root',
        'pass'    => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    // Site identity — change these. Deliberately not anyone else's brand.
    'site_name'   => getenv('SITE_NAME') ?: 'PixelTopUp',
    'site_tagline'=> 'Game top-ups and gift vouchers, delivered fast',
    'currency'    => '৳',
    'support_telegram' => 'https://t.me/example_support',

    // Public base URL, no trailing slash. Used for absolute links in sitemap.
    'base_url' => rtrim(getenv('APP_URL') ?: 'http://localhost:8080', '/'),

    // Secrets: CHANGE THESE before deploying. They sign CSRF-free webhooks.
    'app_secret'     => getenv('APP_SECRET') ?: 'dev-only-change-me-app-secret',
    'payment_secret' => getenv('PAYMENT_SECRET') ?: 'dev-only-change-me-payment-secret',

    // Business rules
    'max_orders_per_day' => 5,   // per user, rolling 24h
    'max_quantity'       => 10,  // per voucher order

    // Demo mode: shows a fake payment page so you can test without a gateway.
    // MUST be false in production.
    'demo_payments' => (getenv('DEMO_PAYMENTS') ?: '1') === '1',

    // Optional Google sign-in. Leave empty to hide the button.
    'google_client_id'     => getenv('GOOGLE_CLIENT_ID') ?: '',
    'google_client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',

    // Seeded by bin/install.php
    'admin_email'    => getenv('ADMIN_EMAIL') ?: 'admin@pixeltopup.test',
    'admin_password' => getenv('ADMIN_PASSWORD') ?: 'ChangeMe123!',
];
