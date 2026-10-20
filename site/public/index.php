<?php
/**
 * PixelTopUp — front controller.
 *
 * Every request enters here. Routes are matched top to bottom.
 * Handlers either render a view or redirect; POST handlers re-render on error.
 */
declare(strict_types=1);

// Let PHP's built-in server hand out CSS/JS directly during development.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (is_file($file)) {
        return false;
    }
}

require __DIR__ . '/../app/bootstrap.php';

// ---------------------------------------------------------------------------
// Home
// ---------------------------------------------------------------------------
function h_home(): void
{
    global $db;
    $featured = $db->all(
        "SELECT * FROM products WHERE is_active = 1 AND is_featured = 1 ORDER BY sort_order, id"
    );
    $regular = $db->all(
        "SELECT * FROM products WHERE is_active = 1 AND is_featured = 0 ORDER BY sort_order, id"
    );
    $recent = $db->all(
        "SELECT o.id, o.package_label, o.amount, o.created_at, u.name
           FROM orders o JOIN users u ON u.id = o.user_id
          WHERE o.status IN ('paid','delivered')
          ORDER BY o.id DESC LIMIT 8"
    );
    $notice = $db->value("SELECT v FROM settings WHERE k = 'notice'");
    view('home', [
        'featured' => $featured,
        'regular'  => $regular,
        'recent'   => $recent,
        'notice'   => (string) ($notice ?? ''),
        'title'    => config('site_name'),
    ]);
}

// ---------------------------------------------------------------------------
// Catalogue
// ---------------------------------------------------------------------------
function h_shop(): void
{
    global $db;
    $q = trim((string) ($_GET['q'] ?? ''));
    $type = (string) ($_GET['type'] ?? '');

    $sql = 'SELECT * FROM products WHERE is_active = 1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (title LIKE ? OR subtitle LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    if (in_array($type, ['topup', 'voucher'], true)) {
        $sql .= ' AND type = ?';
        $params[] = $type;
    }
    $sql .= ' ORDER BY is_featured DESC, sort_order, id';

    view('shop', [
        'products' => $db->all($sql, $params),
        'q'        => $q,
        'type'     => $type,
        'title'    => 'Shop',
    ]);
}

// ---------------------------------------------------------------------------
// Product page (3-step wizard)
// ---------------------------------------------------------------------------
function h_product(int $id): void
{
    global $db;
    $product = $db->one('SELECT * FROM products WHERE id = ? AND is_active = 1', [$id]);
    if ($product === null) {
        http_response_code(404);
        view('error', ['message' => 'Product not found.']);
        return;
    }
    $packages = $db->all(
        'SELECT * FROM packages WHERE product_id = ? AND is_active = 1 ORDER BY sort_order, price_taka',
        [$id]
    );
    $user = current_user();
    $spend = $user ? lifetime_spend((int) $user['id']) : 0;
    $locked = $spend < (int) $product['min_lifetime_spend'];

    view('product', [
        'product'  => $product,
        'packages' => $packages,
        'locked'   => $locked,
        'spend'    => $spend,
        'title'    => $product['title'],
        // one key per rendered page: a double-submit reuses it and is deduplicated
        'idem_key' => bin2hex(random_bytes(16)),
    ]);
}

// ---------------------------------------------------------------------------
// Checkout — the only place money and stock move
// ---------------------------------------------------------------------------
function h_checkout(): void
{
    global $db;
    $user = require_login();

    if (!csrf_ok()) {
        flash('Your session expired. Please try again.', 'error');
        redirect('/');
    }

    $productId = (int) ($_POST['product_id'] ?? 0);
    $packageId = (int) ($_POST['package_id'] ?? 0);
    $quantity  = max(1, (int) ($_POST['quantity'] ?? 1));
    $gameUid   = trim((string) ($_POST['game_uid'] ?? ''));
    $method    = ($_POST['pay_method'] ?? '') === 'wallet' ? 'wallet' : 'instant';
    $idemKey   = (string) ($_POST['idem_key'] ?? '');
    $back      = '/product/' . $productId;

    if (!preg_match('/^[a-f0-9]{32}$/', $idemKey)) {
        flash('Invalid checkout token. Please reload the page.', 'error');
        redirect($back);
    }

    try {
        $orderId = $db->transaction(function (Database $db) use (
            $user, $productId, $packageId, $quantity, $gameUid, $method, $idemKey
        ): int {
            $row = $db->one(
                'SELECT pk.id AS package_id, pk.label, pk.price_taka, pk.stock,
                        pr.id AS product_id, pr.type, pr.title AS product_title,
                        pr.min_lifetime_spend, pr.is_active AS product_active,
                        pk.is_active AS package_active
                   FROM packages pk JOIN products pr ON pr.id = pk.product_id
                  WHERE pk.id = ? AND pk.product_id = ?',
                [$packageId, $productId]
            );
            if ($row === null) {
                throw new DomainException('That package no longer exists.');
            }
            if ((int) $row['product_active'] !== 1 || (int) $row['package_active'] !== 1) {
                throw new DomainException('That package is not available right now.');
            }

            // Quantity rules: vouchers can be bought in bulk, top-ups are per-UID.
            $qty = $row['type'] === 'voucher'
                ? min($quantity, (int) config('max_quantity'))
                : 1;

            if ($row['type'] === 'topup' && $gameUid === '') {
                throw new DomainException('Enter the game UID for this order.');
            }
            if (mb_strlen($gameUid) > 64) {
                throw new DomainException('That UID is too long.');
            }

            // Loyalty gate, re-checked server-side.
            $spend = lifetime_spend((int) $user['id']);
            if ($spend < (int) $row['min_lifetime_spend']) {
                throw new DomainException(sprintf(
                    'This offer unlocks after %s in completed orders.',
                    money((int) $row['min_lifetime_spend'])
                ));
            }

            // Per-day cap, re-checked inside the transaction.
            if (orders_last_24h((int) $user['id']) >= (int) config('max_orders_per_day')) {
                throw new DomainException(sprintf(
                    'Limit reached: %d orders per 24 hours.',
                    (int) config('max_orders_per_day')
                ));
            }

            // Price comes from the database, never from the form.
            $amount = (int) $row['price_taka'] * $qty;
            $now = gmdate('Y-m-d H:i:s');

            // Insert first: the unique idempotency key is what makes a
            // double-submit safe. A duplicate throws and rolls everything back.
            $db->run(
                'INSERT INTO orders
                   (user_id, product_id, package_id, package_label, unit_price, quantity,
                    amount, game_uid, pay_method, status, idempotency_key, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $user['id'], (int) $row['product_id'], (int) $row['package_id'],
                    $row['label'], (int) $row['price_taka'], $qty, $amount,
                    $row['type'] === 'topup' ? $gameUid : null,
                    $method, 'pending', $idemKey, $now,
                ]
            );
            $orderId = (int) $db->pdo()->lastInsertId();

            // Conditional decrement: the check and the write are one statement,
            // so two buyers cannot both pass a stock test.
            $stmt = $db->run(
                'UPDATE packages SET stock = stock - ? WHERE id = ? AND stock >= ?',
                [$qty, (int) $row['package_id'], $qty]
            );
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('Sold out — someone else got the last one.');
            }

            if ($method === 'wallet') {
                $debit = $db->run(
                    'UPDATE users SET wallet_balance = wallet_balance - ?
                      WHERE id = ? AND wallet_balance >= ?',
                    [$amount, (int) $user['id'], $amount]
                );
                if ($debit->rowCount() !== 1) {
                    throw new DomainException('Not enough wallet balance for this order.');
                }
                $db->run(
                    'INSERT INTO wallet_ledger (user_id, delta, reason, order_id, created_at)
                     VALUES (?, ?, ?, ?, ?)',
                    [(int) $user['id'], -$amount, 'order', $orderId, $now]
                );
                mark_order_paid($orderId, 'wallet');
            }

            return $orderId;
        });
    } catch (DomainException $e) {
        flash($e->getMessage(), 'error');
        redirect($back);
    } catch (PDOException $e) {
        if ($db->isUniqueViolation($e)) {
            // Same idempotency key: this is the same click arriving twice.
            $existing = $db->one('SELECT id FROM orders WHERE idempotency_key = ?', [$idemKey]);
            if ($existing !== null) {
                redirect('/order/' . (int) $existing['id']);
            }
        }
        throw $e;
    }

    flash('Order created. ' . ($method === 'wallet' ? 'Paid from your wallet.' : 'Complete payment to start delivery.'));
    redirect('/order/' . $orderId);
}

// ---------------------------------------------------------------------------
// Order detail
// ---------------------------------------------------------------------------
function h_order(int $id): void
{
    global $db;
    $user = require_login();
    $order = $db->one('SELECT * FROM orders WHERE id = ?', [$id]);
    if ($order === null || ((int) $order['user_id'] !== (int) $user['id'] && !is_admin())) {
        http_response_code(404);
        view('error', ['message' => 'Order not found.']);
        return;
    }
    $product = $db->one('SELECT * FROM products WHERE id = ?', [(int) $order['product_id']]);
    view('order', [
        'order'   => $order,
        'product' => $product,
        'title'   => 'Order #' . $order['id'],
    ]);
}

// ---------------------------------------------------------------------------
// Account
// ---------------------------------------------------------------------------
function h_account(): void
{
    global $db;
    $user = require_login();
    $orders = $db->all(
        'SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 50',
        [(int) $user['id']]
    );
    $ledger = $db->all(
        'SELECT * FROM wallet_ledger WHERE user_id = ? ORDER BY id DESC LIMIT 50',
        [(int) $user['id']]
    );
    // Derived balance must agree with the stored column; surface drift if not.
    $derived = (int) ($db->value(
        'SELECT COALESCE(SUM(delta),0) FROM wallet_ledger WHERE user_id = ?',
        [(int) $user['id']]
    ) ?? 0);

    view('account', [
        'user'    => $user,
        'orders'  => $orders,
        'ledger'  => $ledger,
        'derived' => $derived,
        'title'   => 'My account',
    ]);
}

function h_wallet_topup(): void
{
    global $db;
    $user = require_login();
    if (!csrf_ok()) {
        flash('Session expired.', 'error');
        redirect('/account');
    }
    if (!config('demo_payments')) {
        flash('Wallet top-up is handled by your payment provider.', 'error');
        redirect('/account');
    }
    $amount = (int) ($_POST['amount'] ?? 0);
    if ($amount < 10 || $amount > 20000) {
        flash('Top-up must be between ' . money(10) . ' and ' . money(20000) . '.', 'error');
        redirect('/account');
    }
    $db->transaction(function (Database $db) use ($user, $amount): void {
        $now = gmdate('Y-m-d H:i:s');
        $db->run('UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?', [$amount, (int) $user['id']]);
        $db->run(
            'INSERT INTO wallet_ledger (user_id, delta, reason, order_id, created_at) VALUES (?,?,?,?,?)',
            [(int) $user['id'], $amount, 'topup', null, $now]
        );
    });
    flash('Demo top-up of ' . money($amount) . ' added to your wallet.');
    redirect('/account');
}

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------
function h_login_get(): void
{
    view('auth', ['mode' => 'login', 'title' => 'Sign in']);
}

function h_login_post(): void
{
    global $db;
    if (!csrf_ok()) {
        flash('Session expired. Try again.', 'error');
        redirect('/login');
    }
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');
    $user  = $db->one('SELECT * FROM users WHERE email = ?', [$email]);

    if ($user === null || $user['password_hash'] === null
        || !password_verify($pass, (string) $user['password_hash'])) {
        // Same message for unknown email and wrong password.
        flash('Email or password is incorrect.', 'error');
        redirect('/login');
    }
    login_user((int) $user['id']);
    $to = $_SESSION['after_login'] ?? '/account';
    unset($_SESSION['after_login']);
    flash('Welcome back, ' . $user['name'] . '.');
    redirect(is_string($to) ? $to : '/account');
}

function h_register_get(): void
{
    view('auth', ['mode' => 'register', 'title' => 'Create account']);
}

function h_register_post(): void
{
    global $db;
    if (!csrf_ok()) {
        flash('Session expired. Try again.', 'error');
        redirect('/register');
    }
    $name  = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');

    if ($name === '' || mb_strlen($name) > 120) {
        flash('Please enter your name.', 'error');
        redirect('/register');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('That email address is not valid.', 'error');
        redirect('/register');
    }
    if (strlen($pass) < 8) {
        flash('Use at least 8 characters for your password.', 'error');
        redirect('/register');
    }
    if ($db->one('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
        flash('An account with that email already exists.', 'error');
        redirect('/login');
    }

    try {
        $db->run(
            'INSERT INTO users (email, password_hash, name, role, created_at) VALUES (?,?,?,?,?)',
            [$email, password_hash($pass, PASSWORD_DEFAULT), $name, 'customer', gmdate('Y-m-d H:i:s')]
        );
    } catch (PDOException $e) {
        if ($db->isUniqueViolation($e)) {
            flash('An account with that email already exists.', 'error');
            redirect('/login');
        }
        throw $e;
    }
    login_user((int) $db->pdo()->lastInsertId());
    flash('Account created.');
    redirect('/account');
}

function h_logout(): void
{
    if (csrf_ok()) {
        $_SESSION = [];
        session_destroy();
    }
    redirect('/');
}

// ---------------------------------------------------------------------------
// Google sign-in (optional). Set GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET.
// ---------------------------------------------------------------------------
function google_configured(): bool
{
    return config('google_client_id') !== '' && config('google_client_secret') !== '';
}

function h_google_start(): void
{
    if (!google_configured()) {
        flash('Google sign-in is not configured on this store.', 'error');
        redirect('/login');
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_state'] = $state;
    $query = http_build_query([
        'client_id'     => config('google_client_id'),
        'redirect_uri'  => config('base_url') . '/auth/google/callback',
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query);
    exit;
}

function h_google_callback(): void
{
    global $db;
    if (!google_configured()) {
        redirect('/login');
    }
    $state = (string) ($_GET['state'] ?? '');
    $code  = (string) ($_GET['code'] ?? '');
    if ($state === '' || !hash_equals((string) ($_SESSION['google_state'] ?? ''), $state) || $code === '') {
        flash('Google sign-in failed (bad state). Try again.', 'error');
        redirect('/login');
    }
    unset($_SESSION['google_state']);

    $post = http_build_query([
        'code'          => $code,
        'client_id'     => config('google_client_id'),
        'client_secret' => config('google_client_secret'),
        'redirect_uri'  => config('base_url') . '/auth/google/callback',
        'grant_type'    => 'authorization_code',
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $post,
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
    $token = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($token) || empty($token['access_token'])) {
        flash('Google sign-in failed (token exchange).', 'error');
        redirect('/login');
    }

    $ctx2 = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => 'Authorization: Bearer ' . $token['access_token'] . "\r\n",
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $infoRaw = @file_get_contents('https://openidconnect.googleapis.com/v1/userinfo', false, $ctx2);
    $info = is_string($infoRaw) ? json_decode($infoRaw, true) : null;
    if (!is_array($info) || empty($info['email'])) {
        flash('Could not read your Google profile.', 'error');
        redirect('/login');
    }

    $email = strtolower((string) $info['email']);
    $user = $db->one('SELECT * FROM users WHERE email = ?', [$email]);
    if ($user === null) {
        $db->run(
            'INSERT INTO users (email, password_hash, name, avatar_url, google_id, role, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [$email, null, (string) ($info['name'] ?? 'Google user'),
             $info['picture'] ?? null, (string) ($info['sub'] ?? ''), 'customer', gmdate('Y-m-d H:i:s')]
        );
        $user = $db->one('SELECT * FROM users WHERE email = ?', [$email]);
    }
    login_user((int) $user['id']);
    flash('Signed in with Google.');
    redirect('/account');
}

// ---------------------------------------------------------------------------
// Payments: demo page + signed webhook
// ---------------------------------------------------------------------------
function h_pay(int $id): void
{
    global $db;
    $user = require_login();
    $order = $db->one('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$id, (int) $user['id']]);
    if ($order === null) {
        http_response_code(404);
        view('error', ['message' => 'Order not found.']);
        return;
    }
    if ($order['status'] !== 'pending') {
        redirect('/order/' . $id);
    }
    view('pay', ['order' => $order, 'title' => 'Pay for order #' . $id]);
}

/** Shared by the demo button and the real webhook. Idempotent. */
function confirm_payment(int $orderId, string $source): bool
{
    global $db;
    return (bool) $db->transaction(function (Database $db) use ($orderId, $source): bool {
        $order = $db->one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($order === null || $order['status'] !== 'pending') {
            return false;
        }
        mark_order_paid($orderId, $source);
        return true;
    });
}

function h_pay_complete(int $id): void
{
    global $db;
    $user = require_login();
    if (!csrf_ok()) {
        flash('Session expired.', 'error');
        redirect('/order/' . $id);
    }
    if (!config('demo_payments')) {
        flash('Demo payments are disabled.', 'error');
        redirect('/order/' . $id);
    }
    $order = $db->one('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$id, (int) $user['id']]);
    if ($order === null) {
        http_response_code(404);
        view('error', ['message' => 'Order not found.']);
        return;
    }
    confirm_payment($id, 'demo-gateway');
    flash('Payment confirmed. Your order is queued for delivery.');
    redirect('/order/' . $id);
}

function h_webhook(): void
{
    $raw = file_get_contents('php://input') ?: '';
    $sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    $expected = hash_hmac('sha256', $raw, (string) config('payment_secret'));

    if ($sig === '' || !hash_equals($expected, $sig)) {
        json_out(['ok' => false, 'error' => 'invalid signature'], 401);
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload) || !isset($payload['order_id'], $payload['status'])) {
        json_out(['ok' => false, 'error' => 'bad payload'], 400);
    }
    if ($payload['status'] !== 'success') {
        json_out(['ok' => true, 'ignored' => true]);
    }
    $changed = confirm_payment((int) $payload['order_id'], 'webhook');
    json_out(['ok' => true, 'applied' => $changed]);
}

// ---------------------------------------------------------------------------
// Admin
// ---------------------------------------------------------------------------
function h_admin_orders(): void
{
    global $db;
    require_admin();
    $status = (string) ($_GET['status'] ?? '');
    $sql = 'SELECT o.*, u.name AS user_name, u.email
              FROM orders o JOIN users u ON u.id = o.user_id';
    $params = [];
    if (in_array($status, ['pending', 'paid', 'delivered', 'cancelled'], true)) {
        $sql .= ' WHERE o.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY o.id DESC LIMIT 100';

    $stats = $db->all("SELECT status, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM orders GROUP BY status");
    view('admin_orders', [
        'orders' => $db->all($sql, $params),
        'stats'  => $stats,
        'status' => $status,
        'title'  => 'Admin · orders',
    ]);
}

function h_admin_order_status(int $id): void
{
    global $db;
    require_admin();
    if (!csrf_ok()) {
        flash('Session expired.', 'error');
        redirect('/admin/orders');
    }
    $to = (string) ($_POST['status'] ?? '');
    if (!in_array($to, ['paid', 'delivered', 'cancelled'], true)) {
        flash('Unknown status.', 'error');
        redirect('/admin/orders');
    }

    try {
        $db->transaction(function (Database $db) use ($id, $to): void {
            $order = $db->one('SELECT * FROM orders WHERE id = ?', [$id]);
            if ($order === null) {
                throw new DomainException('Order not found.');
            }
            $now = gmdate('Y-m-d H:i:s');

            if ($to === 'cancelled') {
                if (in_array($order['status'], ['delivered', 'cancelled'], true)) {
                    throw new DomainException('Delivered or cancelled orders cannot be cancelled.');
                }
                $db->run("UPDATE orders SET status = 'cancelled' WHERE id = ?", [$id]);
                // Give the stock back.
                $db->run('UPDATE packages SET stock = stock + ? WHERE id = ?',
                    [(int) $order['quantity'], (int) $order['package_id']]);
                if ($order['status'] === 'paid') {
                    if ($order['pay_method'] === 'wallet') {
                        $db->run('UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?',
                            [(int) $order['amount'], (int) $order['user_id']]);
                        $db->run(
                            'INSERT INTO wallet_ledger (user_id, delta, reason, order_id, created_at) VALUES (?,?,?,?,?)',
                            [(int) $order['user_id'], (int) $order['amount'], 'refund', $id, $now]
                        );
                    }
                    $db->run('UPDATE users SET lifetime_spend = MAX(0, lifetime_spend - ?) WHERE id = ?',
                        [(int) $order['amount'], (int) $order['user_id']]);
                }
                return;
            }

            if ($to === 'paid') {
                mark_order_paid($id, 'admin');
                return;
            }

            // delivered
            if ($order['status'] === 'cancelled') {
                throw new DomainException('A cancelled order cannot be delivered.');
            }
            if ($order['status'] === 'pending') {
                mark_order_paid($id, 'admin');
            }
            $db->run("UPDATE orders SET status = 'delivered', delivered_at = ? WHERE id = ?", [$now, $id]);
        });
        flash('Order #' . $id . ' updated to ' . $to . '.');
    } catch (DomainException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('/admin/orders');
}

function h_admin_products(): void
{
    global $db;
    require_admin();
    view('admin_products', [
        'products' => $db->all('SELECT * FROM products ORDER BY type, sort_order, id'),
        'packages' => $db->all('SELECT * FROM packages ORDER BY product_id, sort_order, price_taka'),
        'title'    => 'Admin · products',
    ]);
}

function h_admin_product_form(?int $id): void
{
    global $db;
    require_admin();
    $product = $id ? $db->one('SELECT * FROM products WHERE id = ?', [$id]) : null;
    $packages = $id
        ? $db->all('SELECT * FROM packages WHERE product_id = ? ORDER BY sort_order, price_taka', [$id])
        : [];
    view('admin_product_form', [
        'product'  => $product,
        'packages' => $packages,
        'title'    => $id ? 'Edit product' : 'New product',
    ]);
}

function h_admin_product_save(): void
{
    global $db;
    require_admin();
    if (!csrf_ok()) {
        flash('Session expired.', 'error');
        redirect('/admin/products');
    }
    $id     = (int) ($_POST['id'] ?? 0);
    $type   = ($_POST['type'] ?? 'topup') === 'voucher' ? 'voucher' : 'topup';
    $title  = trim((string) ($_POST['title'] ?? ''));
    $sub    = trim((string) ($_POST['subtitle'] ?? ''));
    $slug   = trim((string) ($_POST['slug'] ?? ''));
    $image  = trim((string) ($_POST['image_path'] ?? ''));
    $rules  = (string) ($_POST['rules_html'] ?? '');
    $uidLbl = trim((string) ($_POST['uid_label'] ?? 'Game UID')) ?: 'Game UID';
    $minSp  = max(0, (int) ($_POST['min_lifetime_spend'] ?? 0));
    $feat   = isset($_POST['is_featured']) ? 1 : 0;
    $active = isset($_POST['is_active']) ? 1 : 0;
    $sort   = (int) ($_POST['sort_order'] ?? 0);

    if ($title === '') {
        flash('Title is required.', 'error');
        redirect('/admin/product/' . $id);
    }

    if ($id > 0) {
        $db->run(
            'UPDATE products SET type=?, slug=?, title=?, subtitle=?, image_path=?, rules_html=?,
                    uid_label=?, min_lifetime_spend=?, is_featured=?, is_active=?, sort_order=? WHERE id=?',
            [$type, $slug ?: null, $title, $sub ?: null, $image ?: null, $rules, $uidLbl, $minSp, $feat, $active, $sort, $id]
        );
        flash('Product saved.');
        redirect('/admin/product/' . $id);
    }

    $db->run(
        'INSERT INTO products (type, slug, title, subtitle, image_path, rules_html, uid_label,
                               min_lifetime_spend, is_featured, is_active, sort_order, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$type, $slug ?: null, $title, $sub ?: null, $image ?: null, $rules, $uidLbl, $minSp, $feat, $active, $sort, gmdate('Y-m-d H:i:s')]
    );
    flash('Product created. Add packages below.');
    redirect('/admin/product/' . (int) $db->pdo()->lastInsertId());
}

function h_admin_package_save(): void
{
    global $db;
    require_admin();
    if (!csrf_ok()) {
        flash('Session expired.', 'error');
        redirect('/admin/products');
    }
    $productId = (int) ($_POST['product_id'] ?? 0);
    $id        = (int) ($_POST['package_id'] ?? 0);
    $label     = trim((string) ($_POST['label'] ?? ''));
    $price     = max(0, (int) ($_POST['price_taka'] ?? 0));
    $stock     = max(0, (int) ($_POST['stock'] ?? 0));
    $active    = isset($_POST['is_active']) ? 1 : 0;
    $sort      = (int) ($_POST['sort_order'] ?? 0);

    if ($productId <= 0 || $label === '' || $price <= 0) {
        flash('Package needs a label and a price above zero.', 'error');
        redirect('/admin/product/' . $productId);
    }

    if ($id > 0) {
        $db->run(
            'UPDATE packages SET label=?, price_taka=?, stock=?, is_active=?, sort_order=?
              WHERE id=? AND product_id=?',
            [$label, $price, $stock, $active, $sort, $id, $productId]
        );
        flash('Package updated.');
    } else {
        $db->run(
            'INSERT INTO packages (product_id, label, price_taka, stock, is_active, sort_order)
             VALUES (?,?,?,?,?,?)',
            [$productId, $label, $price, $stock, $active, $sort]
        );
        flash('Package added.');
    }
    redirect('/admin/product/' . $productId);
}

// ---------------------------------------------------------------------------
// Static files served by PHP
// ---------------------------------------------------------------------------
function h_sitemap(): void
{
    global $db;
    header('Content-Type: application/xml; charset=utf-8');
    $base = (string) config('base_url');
    $rows = $db->all('SELECT id, slug, type FROM products WHERE is_active = 1 ORDER BY id');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (['/', '/shop'] as $p) {
        echo "  <url><loc>{$base}{$p}</loc></url>\n";
    }
    foreach ($rows as $r) {
        $path = ($r['type'] === 'voucher' ? '/product/' : '/product/') . (int) $r['id']
              . '/' . rawurlencode((string) ($r['slug'] ?? 'item'));
        echo '  <url><loc>' . e($base . $path) . "</loc></url>\n";
    }
    echo "</urlset>\n";
}

function h_robots(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /admin\nDisallow: /account\nDisallow: /order/\n\nSitemap: " . config('base_url') . "/sitemap.xml\n";
}

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';

$routes = [
    ['GET',  '#^/$#',                      fn() => h_home()],
    ['GET',  '#^/shop$#',                  fn() => h_shop()],
    ['GET',  '#^/product/(\d+)(?:/[^/]*)?$#', fn($m) => h_product((int) $m[1])],
    ['POST', '#^/checkout$#',              fn() => h_checkout()],
    ['GET',  '#^/order/(\d+)$#',           fn($m) => h_order((int) $m[1])],
    ['GET',  '#^/account$#',               fn() => h_account()],
    ['POST', '#^/wallet/topup$#',          fn() => h_wallet_topup()],
    ['GET',  '#^/login$#',                 fn() => h_login_get()],
    ['POST', '#^/login$#',                 fn() => h_login_post()],
    ['GET',  '#^/register$#',              fn() => h_register_get()],
    ['POST', '#^/register$#',              fn() => h_register_post()],
    ['POST', '#^/logout$#',                fn() => h_logout()],
    ['GET',  '#^/auth/google$#',           fn() => h_google_start()],
    ['GET',  '#^/auth/google/callback$#',  fn() => h_google_callback()],
    ['GET',  '#^/pay/(\d+)$#',             fn($m) => h_pay((int) $m[1])],
    ['POST', '#^/pay/(\d+)/complete$#',    fn($m) => h_pay_complete((int) $m[1])],
    ['POST', '#^/webhook/payment$#',       fn() => h_webhook()],
    ['GET',  '#^/admin$#',                 fn() => h_admin_orders()],
    ['GET',  '#^/admin/orders$#',          fn() => h_admin_orders()],
    ['POST', '#^/admin/order/(\d+)/status$#', fn($m) => h_admin_order_status((int) $m[1])],
    ['GET',  '#^/admin/products$#',        fn() => h_admin_products()],
    ['GET',  '#^/admin/product/(\d+)$#',   fn($m) => h_admin_product_form((int) $m[1])],
    ['POST', '#^/admin/product/save$#',    fn() => h_admin_product_save()],
    ['POST', '#^/admin/package/save$#',    fn() => h_admin_package_save()],
    ['GET',  '#^/sitemap\.xml$#',          fn() => h_sitemap()],
    ['GET',  '#^/robots\.txt$#',           fn() => h_robots()],
];

foreach ($routes as [$verb, $pattern, $handler]) {
    if ($verb !== $method) {
        continue;
    }
    if (preg_match($pattern, $path, $m) === 1) {
        $handler($m);
        return;
    }
}

http_response_code(404);
view('error', ['message' => 'Page not found.', 'title' => 'Not found']);
