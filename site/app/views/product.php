<?php $user = current_user(); ?>
<nav class="crumbs" aria-label="Breadcrumb">
  <a href="/">Home</a> <span>/</span> <a href="/shop">Shop</a> <span>/</span> <span><?= e($product['title']) ?></span>
</nav>

<div class="product-head">
  <div class="product-media">
    <?php if (!empty($product['image_path'])): ?>
      <img src="<?= e($product['image_path']) ?>" alt="">
    <?php else: ?>
      <span class="card-glyph big"><?= e(mb_substr((string) $product['title'], 0, 2)) ?></span>
    <?php endif; ?>
  </div>
  <div class="product-copy">
    <h1><?= e($product['title']) ?></h1>
    <?php if (!empty($product['subtitle'])): ?>
      <p class="muted"><?= e($product['subtitle']) ?></p>
    <?php endif; ?>
    <span class="badge"><?= e($product['type'] === 'voucher' ? 'Gift voucher' : 'Game top-up') ?></span>
    <?php if ((int) $product['min_lifetime_spend'] > 0): ?>
      <span class="tag tag-gold">Unlocks at <?= e(money((int) $product['min_lifetime_spend'])) ?> lifetime spend</span>
    <?php endif; ?>
  </div>
</div>

<?php if ($locked): ?>
  <div class="panel panel-locked">
    <h2>This offer is locked</h2>
    <p class="muted">
      It unlocks permanently once your completed orders reach
      <strong><?= e(money((int) $product['min_lifetime_spend'])) ?></strong>.
      You currently have <strong><?= e(money($spend)) ?></strong>.
    </p>
    <?php if (!$user): ?>
      <a class="btn btn-primary" href="/login">Sign in to check your progress</a>
    <?php else: ?>
      <div class="progress"><span style="width: <?= (int) min(100, $product['min_lifetime_spend'] > 0 ? ($spend / (int) $product['min_lifetime_spend']) * 100 : 0) ?>%"></span></div>
    <?php endif; ?>
  </div>
<?php elseif (!$packages): ?>
  <div class="panel"><p class="muted">No packages are available for this product right now.</p></div>
<?php else: ?>
<form method="post" action="/checkout" id="order-form" class="wizard-form" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
  <input type="hidden" name="idem_key" value="<?= e($idem_key) ?>">
  <input type="hidden" name="package_id" id="field-package" value="">
  <input type="hidden" name="quantity" id="field-qty" value="1">

  <div class="wizard">
    <!-- Step 1 -->
    <section class="step" data-step="1">
      <div class="step-head"><span class="step-num">1</span><h2>Select recharge</h2></div>
      <div class="pkg-list">
        <?php foreach ($packages as $pk): ?>
          <?php $soldout = (int) $pk['stock'] <= 0; ?>
          <label class="pkg <?= $soldout ? 'pkg-out' : '' ?>">
            <input type="radio" name="pkg_choice" value="<?= (int) $pk['id'] ?>"
                   data-price="<?= (int) $pk['price_taka'] ?>" data-label="<?= e($pk['label']) ?>"
                   <?= $soldout ? 'disabled' : '' ?> required>
            <span class="pkg-body">
              <span class="pkg-label"><?= e($pk['label']) ?></span>
              <span class="pkg-price"><?= $soldout ? 'Sold out' : e(money((int) $pk['price_taka'])) ?></span>
            </span>
            <span class="pkg-stock"><?= $soldout ? '—' : (int) $pk['stock'] . ' left' ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Step 2 -->
    <section class="step" data-step="2">
      <div class="step-head"><span class="step-num">2</span><h2><?= $product['type'] === 'voucher' ? 'Quantity' : 'Account info' ?></h2></div>

      <?php if ($product['type'] === 'voucher'): ?>
        <label class="field">
          <span>How many?</span>
          <input type="number" id="qty-input" min="1" max="<?= (int) config('max_quantity') ?>" value="1" inputmode="numeric">
          <small class="muted">Maximum <?= (int) config('max_quantity') ?> per order. Codes are delivered one per unit.</small>
        </label>
      <?php else: ?>
        <label class="field">
          <span><?= e($product['uid_label']) ?></span>
          <input type="text" name="game_uid" id="uid-input" maxlength="64"
                 placeholder="e.g. 123456789" autocomplete="off"
                 value="" required>
          <small class="muted">Find this in your game profile. A wrong UID sends the top-up to someone else.</small>
        </label>
      <?php endif; ?>
    </section>

    <!-- Step 3 -->
    <section class="step" data-step="3">
      <div class="step-head"><span class="step-num">3</span><h2>Payment</h2></div>

      <label class="pay">
        <input type="radio" name="pay_method" value="wallet" <?= !$user || (int) $user['wallet_balance'] < 1 ? 'disabled' : '' ?>>
        <span class="pay-body">
          <strong>Wallet</strong>
          <small class="muted">
            <?= $user ? 'Balance ' . e(money((int) $user['wallet_balance'])) : 'Sign in to use your wallet' ?>
          </small>
        </span>
      </label>

      <label class="pay">
        <input type="radio" name="pay_method" value="instant" checked>
        <span class="pay-body">
          <strong>Pay now</strong>
          <small class="muted">Card, mobile wallet or bank via our payment partner</small>
        </span>
      </label>
    </section>
  </div>

  <aside class="summary" aria-live="polite">
    <div class="summary-row"><span>Package</span><strong id="sum-label">—</strong></div>
    <div class="summary-row"><span>Quantity</span><strong id="sum-qty">1</strong></div>
    <div class="summary-row total"><span>Total</span><strong id="sum-total"><?= e(money(0)) ?></strong></div>
    <?php if (!$user): ?>
      <a class="btn btn-primary btn-block" href="/login">Sign in to purchase</a>
      <p class="muted small center">You need an account to place an order.</p>
    <?php else: ?>
      <button class="btn btn-primary btn-block" type="submit" id="buy-btn" disabled>Place order</button>
      <p class="muted small center" id="buy-hint">Choose a package to continue.</p>
    <?php endif; ?>
  </aside>
</form>
<?php endif; ?>

<?php if (!empty($product['rules_html'])): ?>
  <section class="rules">
    <h2>Rules &amp; conditions</h2>
    <div class="prose"><?= strip_tags((string) $product['rules_html'], '<p><br><strong><em><ul><ol><li><h3><h4>') ?></div>
  </section>
<?php endif; ?>
