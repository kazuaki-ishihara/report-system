<?php
/** @var array $user */
/** @var string $tab */
/** @var string $stage */
/** @var array $errors */
$full = $stage === 'full' && (int) $user['must_change_password'] !== 1;
?>
<section class="card">
  <h1>アカウント設定</h1>
  <?php if ($full): ?>
  <nav class="tabs">
    <a href="<?= h(url('/account.php?tab=totp')) ?>" class="<?= $tab === 'totp' ? 'active' : '' ?>">2 段階認証</a>
    <a href="<?= h(url('/account.php?tab=codes')) ?>" class="<?= $tab === 'codes' ? 'active' : '' ?>">予備のコード</a>
    <a href="<?= h(url('/account.php?tab=devices')) ?>" class="<?= $tab === 'devices' ? 'active' : '' ?>">端末</a>
    <a href="<?= h(url('/account.php?tab=password')) ?>" class="<?= $tab === 'password' ? 'active' : '' ?>">パスワード</a>
  </nav>
  <?php endif; ?>

  <?php foreach ($errors as $e): ?>
    <div class="flash flash-error" role="alert"><?= h($e) ?></div>
  <?php endforeach; ?>

  <?php if ($tab === 'totp'): ?>
    <?php if ($settingUp): ?>
      <h2>2 段階認証の設定</h2>
      <?php if ($stage === 'setup'): ?>
        <p>はじめてのログインです。安全のため、2 段階認証を設定してください。設定が終わるまで、ほかの画面は使えません。</p>
      <?php endif; ?>
      <ol class="steps">
        <li>スマートフォンに認証アプリ（Google Authenticator など）を入れてください。</li>
        <li>下のボタンを押すと、認証アプリに登録されます。<br>
          <a class="button" href="<?= h($totpUri) ?>">認証アプリに登録する</a>
          <p class="note">うまくいかないときは、認証アプリで「セットアップキーを入力」を選び、次の文字を入れます（時間ベース）。</p>
          <p class="secret"><?= h($secretDisplay) ?></p>
        </li>
        <li>認証アプリに表示された 6 桁の数字を入れてください。</li>
      </ol>
      <form method="post" action="<?= h(url('/account.php?tab=totp')) ?>" class="form">
        <?= form_hidden(false) ?>
        <input type="hidden" name="action" value="totp_confirm">
        <label for="code">6 桁のコード</label>
        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
        <label class="check"><input type="checkbox" name="remember" value="1" checked> この端末を <?= (int) cfg('remember_device_days') ?> 日間記憶する</label>
        <button type="submit" class="primary">設定する</button>
      </form>
    <?php else: ?>
      <h2>2 段階認証</h2>
      <p>設定済み（<?= h((string) $user['totp_enabled_at']) ?>）</p>
      <form method="post" action="<?= h(url('/account.php?tab=totp')) ?>" class="form">
        <?= form_hidden(false) ?>
        <input type="hidden" name="action" value="totp_redo">
        <p class="note">機種変更などで設定し直すときは、今のパスワードを入れてください。</p>
        <label for="current_password_t">今のパスワード</label>
        <input id="current_password_t" name="current_password" type="password" autocomplete="current-password" required>
        <button type="submit">設定し直す</button>
      </form>
    <?php endif; ?>

  <?php elseif ($tab === 'codes'): ?>
    <h2>予備のコード</h2>
    <?php if (is_array($codes)): ?>
      <p>認証アプリが使えないとき（機種変更など）に、1 つにつき 1 回使えます。<strong>この画面を離れると二度と表示されません。</strong>紙に書くなどして保管してください。</p>
      <ul class="codes">
        <?php foreach ($codes as $c): ?><li><?= h($c) ?></li><?php endforeach; ?>
      </ul>
      <?php if ($afterNext !== ''): ?>
        <a class="button primary" href="<?= h(url($afterNext)) ?>">保管しました</a>
      <?php endif; ?>
    <?php else: ?>
      <p>残り <?= (int) $remaining ?> 個</p>
      <form method="post" action="<?= h(url('/account.php?tab=codes')) ?>" class="form" data-confirm="予備のコードを作り直します。今のコードは使えなくなります。よろしいですか？">
        <?= form_hidden(false) ?>
        <input type="hidden" name="action" value="codes_regen">
        <label for="current_password_c">今のパスワード</label>
        <input id="current_password_c" name="current_password" type="password" autocomplete="current-password" required>
        <button type="submit">予備のコードを作り直す</button>
      </form>
    <?php endif; ?>

  <?php elseif ($tab === 'devices'): ?>
    <h2>記憶した端末</h2>
    <?php if (!$devices): ?>
      <p>記憶した端末はありません。</p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($devices as $d): ?>
        <li>
          <span><?= h($d['label']) ?><?= ((int) $d['id'] === $currentDeviceId) ? '（この端末）' : '' ?></span>
          <span class="note">最後に使用 <?= h(substr((string) $d['last_used_at'], 0, 10)) ?> / 期限 <?= h(substr((string) $d['expires_at'], 0, 10)) ?></span>
          <form method="post" action="<?= h(url('/account.php?tab=devices')) ?>" class="inline">
            <?= form_hidden(false) ?>
            <input type="hidden" name="action" value="device_revoke">
            <input type="hidden" name="device_id" value="<?= (int) $d['id'] ?>">
            <button type="submit">解除</button>
          </form>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <form method="post" action="<?= h(url('/account.php?tab=devices')) ?>" class="form" data-confirm="すべての端末からログアウトします。この端末もログアウトします。よろしいですか？">
      <?= form_hidden(false) ?>
      <input type="hidden" name="action" value="logout_all">
      <button type="submit" class="danger">すべての端末からログアウト</button>
    </form>
    <p class="note">スマートフォンをなくしたときに使ってください。</p>

  <?php elseif ($tab === 'password'): ?>
    <h2>パスワードの変更</h2>
    <?php if ((int) $user['must_change_password'] === 1): ?>
      <p>仮のパスワードでログインしています。新しいパスワードを決めてください。</p>
    <?php endif; ?>
    <form method="post" action="<?= h(url('/account.php?tab=password')) ?>" class="form">
      <?= form_hidden(false) ?>
      <input type="hidden" name="action" value="password_change">
      <?php if ((int) $user['must_change_password'] !== 1): ?>
        <label for="current_password_p">今のパスワード</label>
        <input id="current_password_p" name="current_password" type="password" autocomplete="current-password" required>
      <?php endif; ?>
      <label for="new_password">新しいパスワード（<?= (int) cfg('password_min_length') ?> 文字以上）</label>
      <div class="password-field">
        <input id="new_password" name="new_password" type="password" autocomplete="new-password" maxlength="200" required>
        <button type="button" class="toggle-password" data-target="new_password">表示</button>
      </div>
      <p class="note">文の形（好きな言葉をつなげたもの）にすると覚えやすくなります。</p>
      <label for="new_password_confirm">新しいパスワード（確認）</label>
      <input id="new_password_confirm" name="new_password_confirm" type="password" autocomplete="new-password" maxlength="200" required>
      <button type="submit" class="primary">変更する</button>
    </form>
  <?php endif; ?>
</section>
