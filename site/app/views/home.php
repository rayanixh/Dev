<?php if (!empty($notice)): ?>
  <div class="notice" role="status"><?= nl2br(e($notice)) ?></div>
<?php endif; ?>

<section class="hero">
  <div class="hero-copy">
    <h1>Top up your game.<br>Skip the wait.</h1>
    <p class="muted">Pick a package, enter your UID, pay from your wallet or instantly.
       Orders are queued the moment payment clears.</p>
    <div class="hero-actions">
      <a class="btn btn-primary" href="/shop">Browse packages</a>
      <?php if (!current_user()): ?>
        <a class="btn btn-ghost" href="/register">Create an account</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="hero-art" aria-hidden="true">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
  </div>
</section>

<?php
$sections = [
    ['title' => 'Featured', 'items' => $featured, 'accent' => true],
    ['title' => 'All products', 'items' => $regular, 'accent' => false],
];
?>
<?php foreach ($sections as $section): ?>
  <?php if ($section['items']): ?>
    <section class="section">
      <div class="section-head">
        <h2><?= e($section['title']) ?></h2>
        <a class="link" href="/shop">View all</a>
      </div>
      <div class="grid">
        <?php foreach ($section['items'] as $p): ?>
          <a class="card <?= $section['accent'] ? 'card-featured' : '' ?>" href="/product/<?= (int) $p['id'] ?>/<?= e($p['slug'] ?? 'item') ?>">
            <div class="card-media">
              <?php if (!empty($p['image_path'])): ?>
                <img src="<?= e($p['image_path']) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="card-glyph"><?= e(mb_substr((string) $p['title'], 0, 2)) ?></span>
              <?php endif; ?>
              <span class="badge"><?= e($p['type'] === 'voucher' ? 'Voucher' : 'Top-up') ?></span>
            </div>
            <div class="card-body">
              <h3><?= e($p['title']) ?></h3>
              <?php if (!empty($p['subtitle'])): ?>
                <p class="muted small"><?= e($p['subtitle']) ?></p>
              <?php endif; ?>
              <?php if ((int) $p['min_lifetime_spend'] > 0): ?>
                <span class="tag tag-gold">Premium</span>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
<?php endforeach; ?>

<section class="section">
  <div class="section-head">
    <h2>Recent deliveries</h2>
    <span class="live-dot" aria-hidden="true"></span>
  </div>
  <?php if (!$recent): ?>
    <p class="muted">No delivered orders yet. Yours could be the first.</p>
  <?php else: ?>
    <div class="feed">
      <?php foreach ($recent as $r): ?>
        <?php
          // Show a shortened name only. Publishing full names and purchase
          // amounts is a privacy problem, so this feed is deliberately coarse.
          $nm = (string) $r['name'];
          $parts = preg_split('/\s+/', trim($nm)) ?: [];
          $short = $parts[0] ?? 'A customer';
          if (mb_strlen($short) > 3) {
              $short = mb_substr($short, 0, 3) . str_repeat('•', 3);
          }
        ?>
        <div class="feed-row">
          <span class="feed-avatar" aria-hidden="true"><?= e(mb_substr($short, 0, 1)) ?></span>
          <span class="feed-name"><?= e($short) ?></span>
          <span class="feed-item"><?= e($r['package_label']) ?></span>
          <span class="feed-amount"><?= e(money((int) $r['amount'])) ?></span>
          <span class="tag tag-ok">Delivered</span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="section how">
  <h2>How ordering works</h2>
  <ol class="steps-plain">
    <li><strong>Choose a package.</strong> Prices on the page come from our database — what you see is what you pay.</li>
    <li><strong>Enter your details.</strong> Your game UID for top-ups, or a quantity for vouchers.</li>
    <li><strong>Pay your way.</strong> Wallet balance or instant payment. Delivery starts once payment clears.</li>
  </ol>
</section>
