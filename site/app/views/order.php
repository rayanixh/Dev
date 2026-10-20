<?php
$statusMeta = [
    'pending'   => ['Pending payment', 'warn'],
    'paid'      => ['Paid · queued', 'info'],
    'delivered' => ['Delivered', 'ok'],
    'cancelled' => ['Cancelled', 'bad'],
][$order['status']] ?? ['Unknown', 'bad'];
?>
<nav class="crumbs"><a href="/account">Account</a> <span>/</span> <span>Order #<?= (int) $order['id'] ?></span></nav>

<div class="page-head">
  <h1>Order #<?= (int) $order['id'] ?></h1>
  <span class="tag tag-<?= e($statusMeta[1]) ?>"><?= e($statusMeta[0]) ?></span>
</div>

<div class="panel">
  <dl class="kv">
    <dt>Product</dt><dd><?= e($product['title'] ?? 'Product removed') ?></dd>
    <dt>Package</dt><dd><?= e($order['package_label']) ?></dd>
    <dt>Unit price</dt><dd><?= e(money((int) $order['unit_price'])) ?></dd>
    <dt>Quantity</dt><dd><?= (int) $order['quantity'] ?></dd>
    <dt>Total</dt><dd><strong><?= e(money((int) $order['amount'])) ?></strong></dd>
    <?php if (!empty($order['game_uid'])): ?>
      <dt><?= e($product['uid_label'] ?? 'Game UID') ?></dt><dd><code><?= e($order['game_uid']) ?></code></dd>
    <?php endif; ?>
    <dt>Payment</dt><dd><?= $order['pay_method'] === 'wallet' ? 'Wallet' : 'Instant payment' ?></dd>
    <dt>Created</dt><dd><?= e($order['created_at']) ?> UTC</dd>
    <?php if (!empty($order['paid_at'])): ?><dt>Paid</dt><dd><?= e($order['paid_at']) ?> UTC</dd><?php endif; ?>
    <?php if (!empty($order['delivered_at'])): ?><dt>Delivered</dt><dd><?= e($order['delivered_at']) ?> UTC</dd><?php endif; ?>
  </dl>

  <?php if ($order['status'] === 'pending'): ?>
    <div class="actions">
      <?php if ($order['pay_method'] === 'instant'): ?>
        <a class="btn btn-primary" href="/pay/<?= (int) $order['id'] ?>">Continue to payment</a>
      <?php else: ?>
        <p class="muted">This order is waiting for confirmation. Refresh in a moment.</p>
      <?php endif; ?>
    </div>
  <?php elseif ($order['status'] === 'paid'): ?>
    <p class="muted">Payment received. Delivery is in progress — you will see the status change to Delivered here.</p>
  <?php elseif ($order['status'] === 'delivered'): ?>
    <p class="muted">Delivered. If anything looks wrong, contact support with order #<?= (int) $order['id'] ?>.</p>
  <?php else: ?>
    <p class="muted">This order was cancelled. Any wallet payment was refunded automatically.</p>
  <?php endif; ?>
</div>

<p><a class="link" href="/shop">← Keep shopping</a></p>
