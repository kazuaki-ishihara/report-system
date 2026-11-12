<?php
/** @var string $content */
/** @var string $title */
/** @var array|null $user */
$flashes = take_flashes();
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> - 月次報告</title>
<link rel="stylesheet" href="<?= h(url('/css/app.css')) ?>">
<script src="<?= h(url('/js/app.js')) ?>" defer></script>
</head>
<body>
<header class="app-header">
  <a class="app-title" href="<?= h(url('/index.php')) ?>">月次報告</a>
  <?php if ($user !== null && ($_SESSION['stage'] ?? '') === 'full'): ?>
  <details class="app-menu">
    <summary>メニュー</summary>
    <nav>
      <?php foreach (menu_items($user) as $m): ?>
        <a href="<?= h(url($m[0])) ?>"><?= h($m[1]) ?></a>
      <?php endforeach; ?>
      <form method="post" action="<?= h(url('/logout.php')) ?>">
        <?= form_hidden(false) ?>
        <button type="submit" class="link-button">ログアウト</button>
      </form>
    </nav>
  </details>
  <?php endif; ?>
</header>
<main class="app-main">
  <?php foreach ($flashes as $f): ?>
    <div class="flash flash-<?= h($f['type']) ?>" role="status"><?= h($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
