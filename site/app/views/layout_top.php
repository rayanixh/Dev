<?php $user = current_user(); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? 'Store') . ' · ' . config('site_name')) ?></title>
<meta name="description" content="<?= e(config('site_tagline')) ?>">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>

<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="/">
      <span class="brand-mark"><?= e(mb_substr((string) config('site_name'), 0, 1)) ?></span>
      <span class="brand-text"><?= e(config('site_name')) ?></span>
    </a>

    <nav class="nav" aria-label="Main">
      <a href="/">Home</a>
      <a href="/shop">Shop</a>
      <?php if ($user): ?>
        <a href="/account">Account</a>
        <?php if ($user['role'] === 'admin'): ?><a href="/admin/orders">Admin</a><?php endif; ?>
      <?php endif; ?>
    </nav>

    <div class="topbar-actions">
      <?php if ($user): ?>
        <span class="wallet-chip" title="Wallet balance">
          <?= e(config('currency')) ?><?= number_format((int) $user['wallet_balance']) ?>
        </span>
        <form method="post" action="/logout" class="inline">
          <?= csrf_field() ?>
          <button class="btn btn-ghost btn-sm" type="submit">Sign out</button>
        </form>
      <?php else: ?>
        <a class="btn btn-ghost btn-sm" href="/login">Sign in</a>
        <a class="btn btn-primary btn-sm" href="/register">Create account</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<?php foreach (take_flashes() as $f): ?>
  <div class="wrap"><div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div></div>
<?php endforeach; ?>

<main id="main" class="wrap">
