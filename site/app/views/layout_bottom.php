</main>

<footer class="footer">
  <div class="wrap footer-inner">
    <div>
      <strong><?= e(config('site_name')) ?></strong>
      <p class="muted"><?= e(config('site_tagline')) ?></p>
    </div>
    <div class="footer-links">
      <a href="/shop">Shop</a>
      <a href="/account">Account</a>
      <a href="/sitemap.xml">Sitemap</a>
      <a href="<?= e(config('support_telegram')) ?>" rel="noopener noreferrer" target="_blank">Support</a>
    </div>
  </div>
</footer>

<script src="/assets/app.js" defer></script>
</body>
</html>
