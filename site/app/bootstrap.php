<?php
/** Bootstrap: config, session, helpers, auth. Included by everything. */
declare(strict_types=1);

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Dhaka');

$CONFIG = require __DIR__ . '/config.php';
require __DIR__ . '/Database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('pixel_sess');
    session_start();
}

$db = new Database($CONFIG);

// ---------------------------------------------------------------- helpers ----

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function config(string $key, mixed $default = null): mixed
{
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
}

function money(int $taka): string
{
    return config('currency') . number_format($taka);
}

function url(string $path = '/'): string
{
    return $path;
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 302);
    exit;
}

function view(string $name, array $data = []): void
{
    global $CONFIG;
    extract($data, EXTR_SKIP);
    $config = $CONFIG;
    require __DIR__ . '/views/layout_top.php';
    require __DIR__ . '/views/' . $name . '.php';
    require __DIR__ . '/views/layout_bottom.php';
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------- csrf/auth -----

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $sent = $_POST['_csrf'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

function current_user(): ?array
{
    static $cached = null;
    static $loaded = false;
    if ($loaded) {
        return $cached;
    }
    $loaded = true;

    $id = $_SESSION['uid'] ?? null;
    if (!$id) {
        return null;
    }
    global $db;
    $cached = $db->one('SELECT * FROM users WHERE id = ?', [(int) $id]);
    return $cached;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
        flash('Please sign in to continue.', 'error');
        redirect('/login');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        view('error', ['message' => 'Administrators only.']);
        exit;
    }
    return $u;
}

function login_user(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
}

// ---------------------------------------------------------------- flash ------

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ------------------------------------------------------------ order logic ----

/** Total completed spend for a user, used for the loyalty gate. */
function lifetime_spend(int $userId): int
{
    global $db;
    return (int) ($db->value(
        "SELECT COALESCE(SUM(amount),0) FROM orders WHERE user_id = ? AND status IN ('paid','delivered')",
        [$userId]
    ) ?? 0);
}

/** Rolling 24h order count, used to enforce the per-day cap. */
function orders_last_24h(int $userId): int
{
    global $db;
    $since = gmdate('Y-m-d H:i:s', time() - 86400);
    return (int) ($db->value(
        'SELECT COUNT(*) FROM orders WHERE user_id = ? AND created_at >= ?',
        [$userId, $since]
    ) ?? 0);
}

/**
 * Move an order to paid and (optionally) delivered.
 * Callers must already be inside a transaction when money moves.
 */
function mark_order_paid(int $orderId, string $source): void
{
    global $db;
    $order = $db->one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if ($order === null || $order['status'] !== 'pending') {
        return;
    }
    $db->run(
        "UPDATE orders SET status = 'paid', paid_at = ? WHERE id = ? AND status = 'pending'",
        [gmdate('Y-m-d H:i:s'), $orderId]
    );
    $db->run(
        "UPDATE users SET lifetime_spend = lifetime_spend + ? WHERE id = ?",
        [(int) $order['amount'], (int) $order['user_id']]
    );
    error_log(sprintf('order %d paid via %s', $orderId, $source));
}
