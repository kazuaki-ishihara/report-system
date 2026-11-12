<?php
/** @var array|null $target */
/** @var string|null $temp */
/** @var array $errors */
/** @var array $departments */
/** @var array $roleTitles */
/** @var array $post */
$v = function (string $k, $default = '') use ($target, $post) {
    if (isset($post[$k]) && is_string($post[$k])) {
        return $post[$k];
    }
    return $target !== null && isset($target[$k]) ? (string) $target[$k] : $default;
};
$selfUrl = $target === null ? '/admin/user_edit.php' : '/admin/user_edit.php?id=' . (int) $target['id'];
?>
<section class="card">
  <h1><?= $target === null ? 'ユーザーの追加' : 'ユーザーの編集' ?></h1>
  <p><a href="<?= h(url('/admin/org.php?tab=users')) ?>">← ユーザーの一覧</a></p>

  <?php if ($temp !== null): ?>
    <div class="flash flash-info">
      仮のパスワード: <strong class="secret"><?= h($temp) ?></strong><br>
      この画面を閉じると二度と表示されません。本人に伝えてください。初回のログインで 2 段階認証の設定とパスワードの変更を求めます。
    </div>
  <?php endif; ?>
  <?php foreach ($errors as $e): ?>
    <div class="flash flash-error" role="alert"><?= h($e) ?></div>
  <?php endforeach; ?>

  <form method="post" action="<?= h(url($selfUrl)) ?>" class="form">
    <?= form_hidden() ?>
    <input type="hidden" name="action" value="save">
    <label for="login_id">ログイン ID</label>
    <?php if ($target === null): ?>
      <input id="login_id" name="login_id" type="text" maxlength="32" autocapitalize="none" value="<?= h($v('login_id')) ?>" required>
      <p class="note">英小文字・数字・. _ - の 3〜32 文字。メールアドレスは使えません。あとから変更できません。</p>
    <?php else: ?>
      <p><strong><?= h((string) $target['login_id']) ?></strong></p>
    <?php endif; ?>
    <label for="display_name">表示名</label>
    <input id="display_name" name="display_name" type="text" maxlength="20" value="<?= h($v('display_name')) ?>" required>
    <label for="role_title">役職（表示のみ。権限には使いません）</label>
    <select id="role_title" name="role_title">
      <?php foreach ($roleTitles as $r): ?><option <?= $v('role_title', '一般') === $r ? 'selected' : '' ?>><?= h($r) ?></option><?php endforeach; ?>
    </select>
    <label for="department_id">所属の課</label>
    <select id="department_id" name="department_id">
      <option value="">（なし）</option>
      <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>" <?= $v('department_id') === (string) $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option><?php endforeach; ?>
    </select>
    <label class="check"><input type="checkbox" name="is_admin" value="1" <?= $v('is_admin', '0') === '1' ? 'checked' : '' ?>
      data-confirm-on="管理者はすべての課の報告と管理画面を使えるようになります"> 管理者</label>
    <?php if ($target !== null): ?>
      <label class="check"><input type="checkbox" name="is_active" value="1" <?= $v('is_active', '1') === '1' ? 'checked' : '' ?>> 有効（外すとログインできなくなります。報告は残ります）</label>
    <?php endif; ?>
    <button type="submit" class="primary"><?= $target === null ? '追加して仮のパスワードを発行' : '保存' ?></button>
  </form>

  <?php if ($target !== null): ?>
    <h2>状態</h2>
    <ul class="list">
      <li><span>2 段階認証</span><span><?= $target['totp_enabled_at'] !== null ? '設定済み（' . h((string) $target['totp_enabled_at']) . '）' : '未設定' ?></span></li>
      <li><span>ロック</span><span><?= ($target['locked_until'] !== null && $target['locked_until'] > now()) ? 'ロック中（' . h((string) $target['locked_until']) . ' まで）' : 'なし' ?></span></li>
      <li><span>最終ログイン</span><span><?= h((string) ($lastLogin ?? '—')) ?></span></li>
      <li><span>パスワードの変更</span><span><?= (int) $target['must_change_password'] === 1 ? '仮のパスワード（未変更）' : h((string) ($target['password_changed_at'] ?? '—')) ?></span></li>
    </ul>
    <h2>操作</h2>
    <div class="actions">
      <?php foreach ([
          ['temp_password', '仮のパスワードを発行', '仮のパスワードを発行します。今のパスワードは使えなくなり、ほかの端末はログアウトします。よろしいですか？'],
          ['reset_2fa', '2 段階認証を初期化', '2 段階認証を初期化します。次のログインで設定し直します。よろしいですか？'],
          ['unlock', 'ロックを解除', ''],
          ['logout_all', 'すべての端末からログアウト', 'この人をすべての端末からログアウトさせます。よろしいですか？'],
      ] as $a): ?>
        <form method="post" action="<?= h(url($selfUrl)) ?>" class="inline" <?= $a[2] !== '' ? 'data-confirm="' . h($a[2]) . '"' : '' ?>>
          <?= form_hidden() ?>
          <input type="hidden" name="action" value="<?= h($a[0]) ?>">
          <button type="submit"><?= h($a[1]) ?></button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
