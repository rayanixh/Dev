<?php $isNew = $product === null; ?>
<div class="page-head">
  <h1><?= $isNew ? 'New product' : 'Edit: ' . e($product['title']) ?></h1>
  <nav class="tabs"><a href="/admin/products">← Products</a></nav>
</div>

<div class="panel">
  <form method="post" action="/admin/product/save">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $isNew ? 0 : (int) $product['id'] ?>">

    <div class="form-grid">
      <label class="field"><span>Title</span>
        <input type="text" name="title" required maxlength="128" value="<?= e($product['title'] ?? '') ?>">
      </label>
      <label class="field"><span>Type</span>
        <select name="type">
          <option value="topup" <?= ($product['type'] ?? '') === 'topup' ? 'selected' : '' ?>>Top-up (needs a UID)</option>
          <option value="voucher" <?= ($product['type'] ?? '') === 'voucher' ? 'selected' : '' ?>>Voucher (quantity)</option>
        </select>
      </label>
      <label class="field"><span>Subtitle</span>
        <input type="text" name="subtitle" maxlength="190" value="<?= e($product['subtitle'] ?? '') ?>">
      </label>
      <label class="field"><span>Slug (cosmetic)</span>
        <input type="text" name="slug" maxlength="96" value="<?= e($product['slug'] ?? '') ?>">
      </label>
      <label class="field"><span>Image URL or path</span>
        <input type="text" name="image_path" maxlength="255" value="<?= e($product['image_path'] ?? '') ?>">
      </label>
      <label class="field"><span>UID field label</span>
        <input type="text" name="uid_label" maxlength="64" value="<?= e($product['uid_label'] ?? 'Game UID') ?>">
      </label>
      <label class="field"><span>Loyalty unlock (Taka, 0 = open to all)</span>
        <input type="number" name="min_lifetime_spend" min="0" step="100" value="<?= (int) ($product['min_lifetime_spend'] ?? 0) ?>">
      </label>
      <label class="field"><span>Sort order</span>
        <input type="number" name="sort_order" value="<?= (int) ($product['sort_order'] ?? 0) ?>">
      </label>
    </div>

    <label class="field"><span>Rules &amp; conditions (HTML allowed: p, br, strong, em, ul, ol, li)</span>
      <textarea name="rules_html" rows="6"><?= e($product['rules_html'] ?? '') ?></textarea>
    </label>

    <div class="checks">
      <label class="check"><input type="checkbox" name="is_featured" <?= (int) ($product['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>> Featured</label>
      <label class="check"><input type="checkbox" name="is_active" <?= $isNew || (int) ($product['is_active'] ?? 1) === 1 ? 'checked' : '' ?>> Active (visible in shop)</label>
    </div>

    <button class="btn btn-primary" type="submit"><?= $isNew ? 'Create product' : 'Save changes' ?></button>
  </form>
</div>

<?php if (!$isNew): ?>
  <div class="panel">
    <h2>Packages</h2>
    <table class="table">
      <thead><tr><th>Label</th><th>Price</th><th>Stock</th><th>Active</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($packages as $pk): ?>
        <tr>
          <form method="post" action="/admin/package/save">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
            <input type="hidden" name="package_id" value="<?= (int) $pk['id'] ?>">
            <td><input type="text" name="label" value="<?= e($pk['label']) ?>" required maxlength="96"></td>
            <td><input type="number" name="price_taka" value="<?= (int) $pk['price_taka'] ?>" min="1" required></td>
            <td><input type="number" name="stock" value="<?= (int) $pk['stock'] ?>" min="0" required></td>
            <td><input type="checkbox" name="is_active" <?= (int) $pk['is_active'] === 1 ? 'checked' : '' ?>></td>
            <td><button class="btn btn-sm" type="submit">Save</button></td>
          </form>
        </tr>
      <?php endforeach; ?>
      <tr>
        <form method="post" action="/admin/package/save">
          <?= csrf_field() ?>
          <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
          <input type="hidden" name="package_id" value="0">
          <td><input type="text" name="label" placeholder="New package label" required maxlength="96"></td>
          <td><input type="number" name="price_taka" placeholder="Price" min="1" required></td>
          <td><input type="number" name="stock" value="10" min="0" required></td>
          <td><input type="checkbox" name="is_active" checked></td>
          <td><button class="btn btn-sm btn-primary" type="submit">Add</button></td>
        </form>
      </tr>
      </tbody>
    </table>
    <p class="muted small">
      Stock is the number of codes/units you can sell. Zero shows "Sold out" and
      blocks checkout. Packages are sold by the unit, so a stock of 5 at
      <?= e(money(100)) ?> allows five separate orders, not five per order.
    </p>
  </div>
<?php endif; ?>
