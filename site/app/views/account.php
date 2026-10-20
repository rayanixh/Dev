<?php
$drift = (int) $user['wallet_balance'] !== (int) $derived;
?>
<div class="page-head">
  <h1>Hello, <?= e($user['name']) ?></h1>
  <span class="muted"><?= e($user['email']) ?></span>
</div>

<div class="stat-row">
  <div class="stat">
    <span class="stat-label">Wallet</span>
    <span class="stat-value"><?= e(money((int) $user['wallet_balance'])) ?></span>
  </div>
  <div class="stat">
    <span class="stat-label">Lifetime spend</span>
    <span class="stat-value"><?= e(money((int) $user['lifetime_spend'])) ?></span>
  </div>
  <div class="stat">
    <span class="stat-label">Orders</span>
    <span class="stat-value"><?= count($orders) ?></span>
  </div>
</div>

<?php if ($drift): ?>
  <div class="flash flash-error">
    Balance mismatch detected: stored <?= e(money((int) $user['wallet_balance'])) ?>,
    ledger total <?= e(money((int) $derived)) ?>. This should never happen — the ledger is the audit trail.
  </div>
<?php endif; ?>

<?php if (config('demo_payments')): ?>
  <section class="panel">
    <h2>Add funds (demo)</h2>
    <p class="muted small">
      Demo mode is on, so this adds wallet credit without a real payment.
      Set <code>DEMO_PAYMENTS=0</code> in production and route top-ups through your gateway.
    </p>
    <form method="post" action="/wallet/topup" class="inline-form">
      <?= csrf_field() ?>
      <input type="number" name="amount" min="10" max="20000" step="10" value="1000" aria-label="Top-up amount">
      <button class="btn btn-primary" type="submit">Add to wallet</button>
    </form>
  </section>
<?php endif; ?>

<section class="panel">
  <h2>Your orders</h2>
  <?php if (!$orders): ?>
    <p class="muted">No orders yet. <a href="/shop">Browse the shop</a>.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>#</th><th>Package</th><th>Total</th><th>Status</th><th>Date</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><?= (int) $o['id'] ?></td>
          <td><?= e($o['package_label']) ?></td>
          <td><?= e(money((int) $o['amount'])) ?></td>
          <td><span class="tag tag-<?= e(['pending'=>'warn','paid'=>'info','delivered'=>'ok','cancelled'=>'bad'][$o['status']] ?? 'bad') ?>"><?= e($o['status']) ?></span></td>
          <td class="muted small"><?= e($o['created_at']) ?></td>
          <td><a class="link small" href="/order/<?= (int) $o['id'] ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Wallet activity</h2>
  <?php if (!$ledger): ?>
    <p class="muted">No wallet movements yet.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>When</th><th>Reason</th><th>Amount</th></tr></thead>
      <tbody>
      <?php foreach ($ledger as $l): ?>
        <tr>
          <td class="muted small"><?= e($l['created_at']) ?></td>
          <td><?= e($l['reason']) ?><?= $l['order_id'] ? ' · order #' . (int) $l['order_id'] : '' ?></td>
          <td class="<?= (int) $l['delta'] < 0 ? 'neg' : 'pos' ?>">
            <?= (int) $l['delta'] < 0 ? '−' : '+' ?><?= e(money(abs((int) $l['delta']))) ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
