<div class="empty tall">
  <h1><?= http_response_code() === 404 ? 'Not found' : 'Something went wrong' ?></h1>
  <p class="muted"><?= e($message ?? 'The page could not be loaded.') ?></p>
  <a class="btn btn-primary" href="/">Back to home</a>
</div>
