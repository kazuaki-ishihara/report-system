<?php
/** @var int $step */
/** @var array $errors */
/** @var string $loginId */
/** @var string $next */
?>
<section class="card narrow">
  <h1>ログイン</h1>
  <?php if (maintenance_login_text() !== ''): ?><div class="flash flash-warning" role="status" id="maintenance-login-notice"><?= h_lines(maintenance_login_text()) ?></div><?php endif; ?>
  <?php foreach ($errors as $e): ?>
    <div class="flash flash-error" role="alert"><?= h_lines($e) ?></div>
  <?php endforeach; ?>

  <?php if ($step === 1): ?>
  <form method="post" action="<?= h(url('/login.php')) ?>" class="form">
    <?= form_hidden(false) ?>
    <input type="hidden" name="action" value="password">
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <label for="login_id">ログイン ID</label>
    <input id="login_id" name="login_id" type="text" value="<?= h($loginId) ?>" autocomplete="username" inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false" maxlength="32" required>
    <label for="password">パスワード</label>
    <div class="password-field">
      <input id="password" name="password" type="password" autocomplete="current-password" maxlength="200" required>
      <button type="button" class="toggle-password" data-target="password">表示</button>
    </div>
    <button type="submit" class="primary">ログイン</button>
  </form>
  <p class="sub-link"><a href="<?= h(url('/password_reset.php')) ?>">パスワードを忘れた場合</a></p>

  <?php else: ?>
  <form method="post" action="<?= h(url('/login.php')) ?>" class="form">
    <?= form_hidden(false) ?>
    <input type="hidden" name="action" value="code">
    <p><?= h_phrases('スマートフォンの認証アプリ（Google Authenticator など）に表示される 6 桁の数字を入れてください。') ?></p>
    <label for="code">認証アプリの 6 桁のコード</label>
    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" autocapitalize="none" autocorrect="off" spellcheck="false" maxlength="16" required autofocus>
    <button type="button" class="small use-backup-code" data-target="code">予備のコードを入れる（英字のキーボードにする）</button>
    <label class="check"><input type="checkbox" name="remember" value="1" checked> この端末を <?= (int) cfg('remember_device_days') ?> 日間記憶する</label>
    <button type="submit" class="primary">確認</button>
  </form>
  <p class="note"><?= h_phrases('認証アプリが使えないときは、予備のコード（xxxx-xxxxxx）を上の欄に入れてください。') ?></p>
  <p class="sub-link"><a href="<?= h(url('/login.php')) ?>">最初からやり直す</a></p>
  <?php endif; ?>
</section>
