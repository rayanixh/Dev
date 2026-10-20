<div class="page-head">
  <h1>Shop</h1>
  <form class="search" method="get" action="/shop" role="search">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search products…" aria-label="Search products">
    <select name="type" aria-label="Filter by type">
      <option value="">All types</option>
      <option value="topup" <?= $type === 'topup' ? 'selected' : '' ?>>Top-up</option>
      <option value="voucher" <?= $type === 'voucher' ? 'selected' : '' ?>>Voucher</option>
    </select>
    <button class="btn btn-primary" type="submit">Search</button>
  </form>
</div>

<?php if (!$products): ?>
  <div class="empty">
    <h2>Nothing matched</h2>
    <p class="muted">Try a different word, or <a href="/shop">clear the filters</a>.</p>
  </div>
<?php else: ?>
  <div class="grid">
    <?php foreach ($products as $p): ?>
      <a class="card" href="/product/<?= (int) $p['id'] ?>/<?= e($p['slug'] ?? 'item') ?>">
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
            <span class="tag tag-gold">Premium · <?= e(money((int) $p['min_lifetime_spend'])) ?>+</span>
          <?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
