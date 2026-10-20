<div class="page-head">
  <h1>Products</h1>
  <nav class="tabs">
    <a href="/admin/orders">← Orders</a>
    <a href="/admin/product/0" class="on">+ New product</a>
  </nav>
</div>

<div class="panel">
  <table class="table">
    <thead><tr><th>#</th><th>Title</th><th>Type</th><th>Packages</th><th>Premium</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php
    $byProduct = [];
    foreach ($packages as $pk) {
        $byProduct[(int) $pk['product_id']][] = $pk;
    }
    ?>
    <?php foreach ($products as $p): ?>
      <?php $pks = $byProduct[(int) $p['id']] ?? []; ?>
      <tr>
        <td><?= (int) $p['id'] ?></td>
        <td><?= e($p['title']) ?></td>
        <td><?= e($p['type']) ?></td>
        <td><?= count($pks) ?></td>
        <td><?= (int) $p['min_lifetime_spend'] > 0 ? e(money((int) $p['min_lifetime_spend'])) : '—' ?></td>
        <td><?= (int) $p['is_active'] === 1 ? 'yes' : 'no' ?></td>
        <td><a class="link small" href="/admin/product/<?= (int) $p['id'] ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
