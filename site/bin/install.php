<?php
/**
 * Create the schema and seed demo data.
 *
 *   php bin/install.php            # creates tables + seeds if empty
 *   php bin/install.php --fresh    # wipes the SQLite file first (dev only)
 *   php bin/install.php --no-seed  # schema only
 */
declare(strict_types=1);

// Refuse to run over the web: bin/ sits outside public/, but a misconfigured
// vhost could still expose it. 'wasm' is the PHP-WASM SAPI used by the preview.
if (!in_array(PHP_SAPI, ['cli', 'cli-server', 'wasm', 'embed'], true)) {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

$CONFIG = require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';

$args   = $argv ?? [];
$fresh  = in_array('--fresh', $args, true);
$noSeed = in_array('--no-seed', $args, true);

if ($fresh && $CONFIG['driver'] === 'sqlite' && is_file($CONFIG['db_path'])) {
    unlink($CONFIG['db_path']);
    echo "removed {$CONFIG['db_path']}\n";
}

// For MySQL, create the database if the user is allowed to.
if ($CONFIG['driver'] === 'mysql') {
    $c = $CONFIG['mysql'];
    try {
        $root = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], $c['port'], $c['charset']),
            $c['user'],
            $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $root->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            str_replace('`', '', $c['name'])
        ));
        echo "database {$c['name']} ready\n";
    } catch (Throwable $e) {
        echo "note: could not create the database automatically ({$e->getMessage()})\n";
    }
}

$db = new Database($CONFIG);
$schemaFile = __DIR__ . '/../db/schema.' . ($CONFIG['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
$sql = file_get_contents($schemaFile);
if ($sql === false) {
    exit("cannot read $schemaFile\n");
}

// Strip comments first: a leading comment would otherwise swallow the first
// statement when splitting on semicolons.
$sql = preg_replace('/^\s*--.*$/m', '', (string) $sql);
foreach (preg_split('/;\s*(?:\r?\n|$)/', (string) $sql) as $statement) {
    $statement = trim($statement);
    if ($statement === '') {
        continue;
    }
    $db->pdo()->exec($statement);
}
echo "schema applied from " . basename($schemaFile) . "\n";

if ($noSeed) {
    exit("skipping seed data\n");
}

$now = gmdate('Y-m-d H:i:s');

// --- settings ---------------------------------------------------------------
$notice = "Orders are delivered automatically, 24/7. If a UID is wrong the top-up "
        . "goes to someone else, so please double-check it before paying.";
$exists = $db->one('SELECT k FROM settings WHERE k = ?', ['notice']);
if ($exists === null) {
    $db->run('INSERT INTO settings (k, v) VALUES (?, ?)', ['notice', $notice]);
}

// --- admin ------------------------------------------------------------------
$adminEmail = strtolower((string) $CONFIG['admin_email']);
if ($db->one('SELECT id FROM users WHERE email = ?', [$adminEmail]) === null) {
    $db->run(
        'INSERT INTO users (email, password_hash, name, role, created_at) VALUES (?,?,?,?,?)',
        [$adminEmail, password_hash((string) $CONFIG['admin_password'], PASSWORD_DEFAULT), 'Store Admin', 'admin', $now]
    );
    echo "admin account: {$adminEmail} / {$CONFIG['admin_password']}\n";
}

// --- demo customer ----------------------------------------------------------
$demoEmail = 'demo@example.test';
if ($db->one('SELECT id FROM users WHERE email = ?', [$demoEmail]) === null) {
    $db->run(
        'INSERT INTO users (email, password_hash, name, role, wallet_balance, created_at) VALUES (?,?,?,?,?,?)',
        [$demoEmail, password_hash('DemoPass123!', PASSWORD_DEFAULT), 'Demo Customer', 'customer', 2000, $now]
    );
    $db->run(
        'INSERT INTO wallet_ledger (user_id, delta, reason, order_id, created_at) VALUES (?,?,?,?,?)',
        [(int) $db->pdo()->lastInsertId(), 2000, 'topup', null, $now]
    );
    echo "demo customer: {$demoEmail} / DemoPass123!\n";
}

// --- catalogue --------------------------------------------------------------
// Fictional products. Replace these with your own.
$catalogue = [
    [
        'type' => 'topup', 'slug' => 'nova-gems', 'title' => 'Nova Gems',
        'subtitle' => 'Instant gem delivery to your player ID',
        'uid_label' => 'Player ID', 'min_lifetime_spend' => 0,
        'is_featured' => 1, 'sort_order' => 1,
        'rules_html' => '<p>Gems are delivered to the player ID you enter. Orders placed with an '
            . 'incorrect ID cannot be reversed. Delivery is usually under two minutes.</p>',
        'packages' => [
            ['100 Gems', 120, 100], ['500 Gems', 550, 60], ['1,000 Gems', 1050, 40], ['5,000 Gems', 4900, 0],
        ],
    ],
    [
        'type' => 'topup', 'slug' => 'season-pass', 'title' => 'Season Pass',
        'subtitle' => 'Battle pass and weekly bundles',
        'uid_label' => 'Player ID', 'min_lifetime_spend' => 0,
        'is_featured' => 1, 'sort_order' => 2,
        'rules_html' => '<p>Passes stack. One purchase per order; order again for a second pass.</p>',
        'packages' => [
            ['Weekly Pass', 154, 80], ['Monthly Pass', 770, 45],
            ['Weekly Pass ×2', 308, 40], ['Monthly Pass ×2', 1540, 20],
        ],
    ],
    [
        'type' => 'voucher', 'slug' => 'streaming-card', 'title' => 'Streaming Gift Card',
        'subtitle' => 'Code delivered to your account after payment',
        'uid_label' => 'Account', 'min_lifetime_spend' => 0,
        'is_featured' => 0, 'sort_order' => 3,
        'rules_html' => '<p>Codes are single-use. Redeem within 12 months. Keep the code private '
            . '— anyone who sees it can spend it.</p>',
        'packages' => [
            ['1 month', 350, 30], ['3 months', 950, 15], ['12 months', 3400, 5],
        ],
    ],
    [
        'type' => 'voucher', 'slug' => 'wallet-code', 'title' => 'Wallet Top-Up Code',
        'subtitle' => 'Sold out most weeks — restocked on Fridays',
        'uid_label' => 'Account', 'min_lifetime_spend' => 0,
        'is_featured' => 0, 'sort_order' => 4,
        'rules_html' => '<p>Each code adds its face value to the wallet it is redeemed on.</p>',
        'packages' => [
            ['500 code', 500, 0], ['1,000 code', 1000, 0],
        ],
    ],
    [
        'type' => 'topup', 'slug' => 'vip-bundle', 'title' => 'VIP Bundle',
        'subtitle' => 'Loyalty offer — unlocks after ৳20,000 in completed orders',
        'uid_label' => 'Player ID', 'min_lifetime_spend' => 20000,
        'is_featured' => 0, 'sort_order' => 5,
        'rules_html' => '<p>Unlocked permanently once your completed orders reach ৳20,000. '
            . 'The unlock is checked server-side on every order.</p>',
        'packages' => [
            ['VIP monthly', 1590, 10], ['VIP quarterly', 4290, 6],
        ],
    ],
];

$created = 0;
foreach ($catalogue as $item) {
    if ($db->one('SELECT id FROM products WHERE title = ?', [$item['title']]) !== null) {
        continue;
    }
    $db->run(
        'INSERT INTO products (type, slug, title, subtitle, image_path, rules_html, uid_label,
                               min_lifetime_spend, is_featured, is_active, sort_order, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $item['type'], $item['slug'], $item['title'], $item['subtitle'], null,
            $item['rules_html'], $item['uid_label'], $item['min_lifetime_spend'],
            $item['is_featured'], 1, $item['sort_order'], $now,
        ]
    );
    $productId = (int) $db->pdo()->lastInsertId();

    foreach ($item['packages'] as $i => [$label, $price, $stock]) {
        $db->run(
            'INSERT INTO packages (product_id, label, price_taka, stock, is_active, sort_order)
             VALUES (?,?,?,?,?,?)',
            [$productId, $label, $price, $stock, 1, $i + 1]
        );
    }
    $created++;
}

echo "seeded {$created} product(s)\n";

$counts = [
    'products' => $db->value('SELECT COUNT(*) FROM products'),
    'packages' => $db->value('SELECT COUNT(*) FROM packages'),
    'users'    => $db->value('SELECT COUNT(*) FROM users'),
];
echo "totals: " . json_encode($counts) . "\n";
echo "\nStart the dev server:\n  php -S 0.0.0.0:8080 -t public public/index.php\n";
