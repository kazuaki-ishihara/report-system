<?php
/** @var array $errors */
/** @var string $loginId */
?>
<section class="card narrow">
  <h1>パスワードの再設定</h1>
  <?php foreach ($errors as $e): ?>
    <div class="flash flash-error" role="alert"><?= h($e) ?></div>
  <?php endforeach; ?>
  <form method="post" action="<?= h(url('/password_reset.php')) ?>" class="form">
    <?= form_hidden(false) ?>
    <label for="login_id">ログイン ID</label>
    <input id="login_id" name="login_id" type="text" value="<?= h($loginId) ?>" autocomplete="username" autocapitalize="none" maxlength="32" required>
    <label for="code">認証アプリの 6 桁のコード（または予備のコード）</label>
    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required>
    <label for="new_password">新しいパスワード（<?= (int) cfg('password_min_length') ?> 文字以上）</label>
    <div class="password-field">
      <input id="new_password" name="new_password" type="password" autocomplete="new-password" maxlength="200" required>
      <button type="button" class="toggle-password" data-target="new_password">表示</button>
    </div>
    <div class="password-tools">
      <button type="button" class="small generate-password" data-target="new_password" data-confirm-target="new_password_confirm">自動で作る</button>
      <button type="button" class="small copy-text" data-copy-from="new_password">コピー</button>
      <span class="note copy-result" aria-live="polite"></span>
    </div>
    <label for="new_password_confirm">新しいパスワード（確認）</label>
    <input id="new_password_confirm" name="new_password_confirm" type="password" autocomplete="new-password" maxlength="200" required>
    <button type="submit" class="primary">再設定する</button>
  </form>
  <p class="note">認証アプリも予備のコードも使えないときは、管理者に連絡してください。</p>
  <p class="sub-link"><a href="<?= h(url('/login.php')) ?>">ログインへ戻る</a></p>
</section>
