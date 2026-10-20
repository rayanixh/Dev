<div class="page-head">
  <h1>Payment</h1>
  <span class="muted">Order #<?= (int) $order['id'] ?></span>
</div>

<div class="panel pay-panel">
  <div class="pay-amount"><?= e(money((int) $order['amount'])) ?></div>
  <p class="muted center"><?= e($order['package_label']) ?> × <?= (int) $order['quantity'] ?></p>

  <?php if (config('demo_payments')): ?>
    <div class="flash flash-info">
      <strong>Demo gateway.</strong> No real money moves. This stands in for your
      payment provider, so you can test the whole flow end to end.
    </div>
    <form method="post" action="/pay/<?= (int) $order['id'] ?>/complete">
      <?= csrf_field() ?>
      <button class="btn btn-primary btn-block" type="submit">Simulate successful payment</button>
    </form>
    <p class="muted small center">
      In production your gateway calls <code>POST /webhook/payment</code> with an
      <code>X-Signature</code> HMAC instead of this button.
    </p>
  <?php else: ?>
    <p class="muted">Redirecting you to the payment provider…</p>
  <?php endif; ?>
</div>
