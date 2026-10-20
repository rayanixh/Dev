<?php $isLogin = $mode === 'login'; ?>
<div class="auth-wrap">
  <div class="panel auth-panel">
    <h1><?= $isLogin ? 'Sign in' : 'Create your account' ?></h1>
    <p class="muted small"><?= $isLogin ? 'Welcome back.' : 'It takes about ten seconds.' ?></p>

    <?php if (config('google_client_id') !== ''): ?>
      <a class="btn btn-google btn-block" href="/auth/google">Continue with Google</a>
      <div class="or"><span>or</span></div>
    <?php endif; ?>

    <form method="post" action="<?= $isLogin ? '/login' : '/register' ?>">
      <?= csrf_field() ?>
      <?php if (!$isLogin): ?>
        <label class="field">
          <span>Your name</span>
          <input type="text" name="name" required maxlength="120" autocomplete="name">
        </label>
      <?php endif; ?>
      <label class="field">
        <span>Email</span>
        <input type="email" name="email" required autocomplete="email" inputmode="email">
      </label>
      <label class="field">
        <span>Password</span>
        <input type="password" name="password" required
               minlength="<?= $isLogin ? 1 : 8 ?>"
               autocomplete="<?= $isLogin ? 'current-password' : 'new-password' ?>">
        <?php if (!$isLogin): ?><small class="muted">At least 8 characters.</small><?php endif; ?>
      </label>
      <button class="btn btn-primary btn-block" type="submit"><?= $isLogin ? 'Sign in' : 'Create account' ?></button>
    </form>

    <p class="muted small center">
      <?php if ($isLogin): ?>
        New here? <a href="/register">Create an account</a>
      <?php else: ?>
        Already registered? <a href="/login">Sign in</a>
      <?php endif; ?>
    </p>
  </div>
</div>
