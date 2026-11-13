<?php
/** @var string $content */
/** @var string $title */
/** @var array|null $user */
$flashes = take_flashes();
?><!DOCTYPE html>
<?php $userThemeStylesheetUrl = user_theme_stylesheet_url($user ?? null); ?>
<html lang="ja"<?php $themeAttributeValue = ui_theme_attribute($user ?? null); if ($themeAttributeValue !== ''): ?> data-theme="<?= h($themeAttributeValue) ?>"<?php endif; ?><?= $userThemeStylesheetUrl !== '' ? ' data-custom-colors="1"' : '' ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> - 月次報告</title>
<link rel="stylesheet" href="<?= h(url('/css/app.css')) ?>">
<?php if ($userThemeStylesheetUrl !== ''): ?><link rel="stylesheet" href="<?= h($userThemeStylesheetUrl) ?>"><?php endif; ?>
<script src="<?= h(url('/js/app.js')) ?>" defer></script>
</head>
<body<?php if (maintenance_blocks($user ?? null) && ($user ?? null) !== null): ?> data-maintenance="1"<?php endif; ?> data-msgs="<?= h(json_encode(msg_js_map(), JSON_UNESCAPED_UNICODE)) ?>">
<header class="app-header">
  <a class="app-title" href="<?= h(url('/index.php')) ?>">月次報告</a>
  <?php if ($user !== null && ($_SESSION['stage'] ?? '') === 'full'): ?>
  <details class="app-menu">
    <summary>メニュー</summary>
    <nav>
      <?php $previousMenuGroupName = ''; foreach (menu_items($user) as $menuItem): ?>
        <?php if ($menuItem[2] !== $previousMenuGroupName): $previousMenuGroupName = $menuItem[2]; ?><span class="menu-group"><?= h($menuItem[2]) ?></span><?php endif; ?>
        <a href="<?= h(url($menuItem[0])) ?>"><?= h($menuItem[1]) ?></a>
      <?php endforeach; ?>
      <form method="post" action="<?= h(url('/logout.php')) ?>">
        <?= form_hidden(false) ?>
        <button type="submit" class="link-button">ログアウト</button>
      </form>
    </nav>
  </details>
  <?php endif; ?>
</header>
<div class="theme-decoration" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
<main class="app-main<?= ($adminSectionPath ?? null) !== null ? ' admin-main' : '' ?>">
  <?php if (($adminSectionPath ?? null) !== null && $user !== null):
      // 管理画面の道しるべ（G-21）: 「管理 › 区分 › この画面」と、管理画面どうしの切り替え
      $adminMenuItemList = array_values(array_filter(menu_items($user), function (array $menuItem): bool { return $menuItem[2] === '管理'; }));
      $adminSectionLabel = '';
      foreach ($adminMenuItemList as $adminMenuItem) {
          if ($adminMenuItem[0] === $adminSectionPath) {
              $adminSectionLabel = $adminMenuItem[1];
          }
      } ?>
    <nav class="admin-nav" aria-label="管理画面">
      <p class="breadcrumb">
        <a href="<?= h(url('/admin/index.php')) ?>">管理</a>
        <?php if ($adminSectionPath !== '/admin/index.php' && $adminSectionLabel !== ''): ?> › <a href="<?= h(url($adminSectionPath)) ?>"><?= h($adminSectionLabel) ?></a><?php endif; ?>
        <?php if ($title !== $adminSectionLabel && $adminSectionPath !== '/admin/index.php'): ?> › <span><?= h($title) ?></span><?php endif; ?>
      </p>
      <div class="admin-section-tabs">
        <?php foreach ($adminMenuItemList as $adminMenuItem): ?>
          <a href="<?= h(url($adminMenuItem[0])) ?>" class="<?= $adminMenuItem[0] === $adminSectionPath ? 'active' : '' ?>"><?= h($adminMenuItem[1]) ?></a>
        <?php endforeach; ?>
      </div>
    </nav>
  <?php endif; ?>
  <?php if (($screenHelpText ?? '') !== ''): ?>
    <details class="screen-help no-print">
      <summary>？ この画面の使い方</summary>
      <div class="screen-help-body"><?= h_lines($screenHelpText) ?> <a href="<?= h(url('/faq.php')) ?>">よくある質問</a></div>
    </details>
  <?php endif; ?>
  <?php if (($user ?? null) !== null && maintenance_state() !== null): ?>
    <div class="flash flash-error maintenance-notice" role="alert"><?= h_lines(can_manage_system($user) ? msg('メンテナンス中です（管理者・補佐の画面）。ほかの人は保存などの操作ができません。終わったら管理のトップで「メンテナンスを終える」を押してください。') : maintenance_notice_text()) ?></div>
  <?php endif; ?>
  <?php foreach ($flashes as $f): ?>
    <div class="flash flash-<?= h($f['type']) ?>" role="status"><?= h_lines($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
  <?php if ($user !== null && ($supportRequestScreenCode ?? null) !== null && support_request_can_submit($user)): ?>
    <p class="support-request-link no-print">
      <a href="<?= h(url('/requests/index.php?' . http_build_query(['screen' => $supportRequestScreenCode, 'from' => (string) ($_SERVER['REQUEST_URI'] ?? '')]) . '#new')) ?>">この画面について要望・依頼を出す</a>
    </p>
  <?php endif; ?>
</main>
</body>
</html>
