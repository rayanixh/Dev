<div class="page-head">
  <h1>Orders</h1>
  <nav class="tabs">
    <a class="<?= $status === '' ? 'on' : '' ?>" href="/admin/orders">All</a>
    <?php foreach (['pending', 'paid', 'delivered', 'cancelled'] as $s): ?>
      <a class="<?= $status === $s ? 'on' : '' ?>" href="/admin/orders?status=<?= $s ?>"><?= ucfirst($s) ?></a>
    <?php endforeach; ?>
    <a href="/admin/products">Products →</a>
  </nav>
</div>

<div class="stat-row">
  <?php foreach ($stats as $s): ?>
    <div class="stat">
      <span class="stat-label"><?= e($s['status']) ?></span>
      <span class="stat-value"><?= (int) $s['n'] ?></span>
      <span class="muted small"><?= e(money((int) $s['total'])) ?></span>
    </div>
  <?php endforeach; ?>
</div>

<div class="panel">
  <?php if (!$orders): ?>
    <p class="muted">No orders in this view.</p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr><th>#</th><th>Customer</th><th>Package</th><th>UID</th><th>Total</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><?= (int) $o['id'] ?></td>
          <td>
            <div><?= e($o['user_name']) ?></div>
            <div class="muted small"><?= e($o['email']) ?></div>
          </td>
          <td><?= e($o['package_label']) ?> × <?= (int) $o['quantity'] ?></td>
          <td><code class="small"><?= e($o['game_uid'] ?? '—') ?></code></td>
          <td><?= e(money((int) $o['amount'])) ?></td>
          <td>
            <span class="tag tag-<?= e(['pending'=>'warn','paid'=>'info','delivered'=>'ok','cancelled'=>'bad'][$o['status']] ?? 'bad') ?>">
              <?= e($o['status']) ?>
            </span>
          </td>
          <td>
            <form method="post" action="/admin/order/<?= (int) $o['id'] ?>/status" class="inline">
              <?= csrf_field() ?>
              <select name="status" aria-label="Set status for order <?= (int) $o['id'] ?>">
                <option value="paid" <?= $o['status'] === 'paid' ? 'selected' : '' ?>>paid</option>
                <option value="delivered" <?= $o['status'] === 'delivered' ? 'selected' : '' ?>>delivered</option>
                <option value="cancelled" <?= $o['status'] === 'cancelled' ? 'selected' : '' ?>>cancelled</option>
              </select>
              <button class="btn btn-sm" type="submit">Set</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<p class="muted small">
  Cancelling refunds wallet payments and returns stock. Marking an order delivered
  is what the customer sees as complete.
</p>
