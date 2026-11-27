<?php
/** @var array|null $target */
/** @var int $backupCodeRemaining 予備のコードの数（何度でも使える） */
/** @var string|null $temp */
/** @var string[]|null $shownBackupCodes 見せる予備のコード（確認・発行の直後だけ） */
/** @var string|null $shownRecoveryCode */
/** @var bool $isIssuedBackupCodes 発行した予備のコードか */
/** @var array $errors */
/** @var array $departments */
/** @var array $roleTitles */
/** @var array $post */
/** @var array<int, array{id: int|string, name: string, department_id: int|string}> $teamOptionList 有効なライン（課ごと） */
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
      仮のパスワード: <strong class="secret" id="temp-password"><?= h($temp) ?></strong>
      <button type="button" class="small copy-text" data-copy-from="temp-password">コピー</button> <span class="copy-result" aria-live="polite"></span><br>
      この画面を閉じると二度と表示されません。本人に伝えてください。初回のログインで 2 段階認証の設定とパスワードの変更を求めます。<br>
      2 段階認証の設定がまだの人には、はじめてのログインの前にスマートフォンに認証アプリ（Google Authenticator など）を入れ、はじめてのログインはスマートフォンで行うよう伝えてください。
    </div>
  <?php endif; ?>
  <?php foreach ($errors as $e): ?>
    <div class="flash flash-error" role="alert"><?= h_lines($e) ?></div>
  <?php endforeach; ?>

  <form method="post" action="<?= h(url($selfUrl)) ?>" class="form">
    <?= form_hidden() ?>
    <input type="hidden" name="action" value="save">
    <label for="login_id">ログイン ID</label>
    <?php if ($target === null): ?>
      <input id="login_id" name="login_id" type="text" maxlength="32" inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($v('login_id')) ?>" required>
      <p class="note"><?= h_phrases('英小文字・数字・. _ - の 3〜32 文字。メールアドレスは使えません。あとから変更できません。') ?></p>
    <?php else: ?>
      <p><strong><?= h((string) $target['login_id']) ?></strong></p>
    <?php endif; ?>
    <label for="display_name">表示名</label>
    <input id="display_name" name="display_name" type="text" maxlength="20" value="<?= h($v('display_name')) ?>" required>
    <label for="role_title">役職（表示のみ。権限には使いません）</label>
    <select id="role_title" name="role_title">
      <?php foreach ($roleTitles as $r): ?><option <?= $v('role_title', '一般') === $r ? 'selected' : '' ?>><?= h($r) ?></option><?php endforeach; ?>
    </select>
    <label for="specialist_title">専門職（ラインを管理しない技術職）</label>
    <select id="specialist_title" name="specialist_title">
      <option value="">（なし）</option>
      <?php foreach (SPECIALIST_TITLE_LIST as $specialistTitleOption): ?><option <?= $v('specialist_title', '') === $specialistTitleOption ? 'selected' : '' ?>><?= h($specialistTitleOption) ?></option><?php endforeach; ?>
    </select>
    <p class="note"><?= h_phrases('選ぶと、役職の代わりに専門職の名前を表示します。課長・確認者・項目管理者にはなれず（今の役割は外れます）、ほかの人の報告を見られません。専門職の人の報告を見るのは課長だけです（担当部長の報告は、課長も見られません）。') ?></p>
    <label for="department_id">所属の課</label>
    <select id="department_id" name="department_id">
      <option value="">（なし）</option>
      <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>" <?= $v('department_id') === (string) $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option><?php endforeach; ?>
    </select>
    <p class="note"><?= h_phrases('ライン（「ラインごと」で管理する課）は、その課の課長が「課の管理」で決めます。課を移すとラインは外れます。') ?></p>
    <?php if (!$isOperatorDeputy): ?>
    <label class="check"><input type="checkbox" name="is_admin" value="1" <?= $v('is_admin', '0') === '1' ? 'checked' : '' ?>
      data-confirm-on="<?= h(msg('管理者は管理画面（項目・案件・組織・ユーザー・記録）を使えるようになります。報告の中身は、確認者の課の分だけ見られます')) ?>"> 管理者</label>
    <label class="check"><input type="checkbox" name="is_admin_deputy" value="1" <?= $v('is_admin_deputy', '0') === '1' ? 'checked' : '' ?>
      <?= ($v('is_admin_deputy', '0') !== '1' && $adminDeputyCount >= ADMIN_DEPUTY_MAX_COUNT) ? 'disabled' : '' ?>
      data-confirm-on="<?= h(msg('システム管理者補佐は、管理者が対応できないときに、管理の仕事（課・ユーザー・組織の改訂・項目・案件・注意の言葉・メッセージ）を代行できます。報告の中身・人ごとの提出状況・削除データの中身・閲覧と出力の記録は見られません。補佐の操作は記録に残ります')) ?>">
      システム管理者補佐（<?= (int) $adminDeputyCount ?>/<?= ADMIN_DEPUTY_MAX_COUNT ?> 名<?= ($v('is_admin_deputy', '0') !== '1' && $adminDeputyCount >= ADMIN_DEPUTY_MAX_COUNT) ? '。上限のため付けられません' : '' ?>）</label>
    <label class="check"><input type="checkbox" name="is_admin_deputy_lead" value="1" <?= $v('is_admin_deputy_lead', '0') === '1' ? 'checked' : '' ?>
      data-confirm-on="<?= h(msg('補佐（開発担当）は、管理者と同じ権限を持ちます（心の不調・退職に関わる質問は見られません）。このシステムの設計・製造・保守の担当者に 1 名だけ付けてください')) ?>">
      補佐（開発担当。補佐のうち 1 名だけ。管理者と同じ権限。心の不調・退職に関わる質問は見られない。補佐の印も付けてください）</label>
    <?php endif; ?>
    <label class="check"><input type="checkbox" name="is_executive" value="1" <?= $v('is_executive', '0') === '1' ? 'checked' : '' ?>> 部長以上（全課共通の臨時の調査の結果を見られる。集計だけで、個人の回答と名前は見られない）</label>
    <label class="check"><input type="checkbox" name="is_hr_manager" value="1" <?= $v('is_hr_manager', '0') === '1' ? 'checked' : '' ?>> 人財管理者（人財管理部。全課共通の臨時の調査を作り、結果を見られる。集計だけで、個人の回答と名前は見られない）</label>
    <?php if ($target !== null): ?>
      <label class="check"><input type="checkbox" name="is_active" value="1" <?= $v('is_active', '1') === '1' ? 'checked' : '' ?>> 有効（外すとログインできなくなります。報告は残ります）</label>
    <?php endif; ?>
    <button type="submit" class="primary"><?= $target === null ? '追加して仮のパスワードを発行' : '保存' ?></button>
  </form>

  <?php if ($target !== null): ?>
    <h2>状態</h2>
    <ul class="list">
      <li><span>2 段階認証</span><span><?= $target['totp_enabled_at'] !== null ? '設定済み（' . h(display_datetime($target['totp_enabled_at'])) . '）' : '未設定' ?></span></li>
      <li><span>予備のコード</span><span><?= $target['totp_enabled_at'] === null ? '—' : (int) $backupCodeRemaining . ' 個' ?></span></li>
      <li><span>ロック</span><span><?= ($target['locked_until'] !== null && $target['locked_until'] > now()) ? 'ロック中（' . h(display_datetime((string) $target['locked_until'])) . ' まで）' : 'なし' ?></span></li>
      <li><span>最終ログイン</span><span><?= h($lastLogin === null ? '—' : display_datetime((string) $lastLogin)) ?></span></li>
      <li><span>パスワードの変更</span><span><?= (int) $target['must_change_password'] === 1 ? '仮のパスワード（未変更）' : h($target['password_changed_at'] === null ? '—' : display_datetime((string) $target['password_changed_at'])) ?></span></li>
    </ul>
    <h2>操作</h2>
    <div class="actions">
      <?php foreach ([
          ['temp_password', '仮のパスワードを発行', msg('仮のパスワードを発行します。今のパスワードは使えなくなり、ほかの端末はログアウトします。よろしいですか？')],
          ['reset_2fa', '2 段階認証を初期化', msg('2 段階認証を初期化します。次のログインで設定し直します。よろしいですか？')],
          ['reset_backup_codes', '予備のコードを初期化', msg('予備のコードを初期化します。今のコードはすべて使えなくなります（認証アプリはそのまま使えます）。よろしいですか？')],
          ['unlock', 'ロックを解除', ''],
          ['logout_all', 'すべての端末からログアウト', msg('この人をすべての端末からログアウトさせます。よろしいですか？')],
      ] as $a): ?>
        <?php if (($a[0] === 'reset_backup_codes' && $target['totp_enabled_at'] === null) || (security_is_protected($target) && in_array($a[0], ['temp_password', 'reset_2fa'], true))) { continue; } ?>
        <form method="post" action="<?= h(url($selfUrl)) ?>" class="inline" <?= $a[2] !== '' ? 'data-confirm="' . h($a[2]) . '"' : '' ?>>
          <?= form_hidden() ?>
          <input type="hidden" name="action" value="<?= h($a[0]) ?>">
          <button type="submit"><?= h($a[1]) ?></button>
        </form>
      <?php endforeach; ?>
    </div>
    <?php if (has_admin_authority($user) && (int) $target['id'] !== (int) $user['id'] && $target['totp_enabled_at'] !== null && !security_is_frozen($target) && !security_is_protected($target)): ?>
    <h2>予備のコードの確認・復旧コード</h2>
    <p class="note"><?= h_phrases('本人に、あなたの名前と役割・理由を知らせます（本人のホームに 30 日出ます。消せません）。記録にも残ります。') ?></p>
    <?php if ($shownBackupCodes !== null): ?>
      <p><?= $isIssuedBackupCodes ? '発行した予備のコード（<strong>この画面を離れると消えます</strong>。本人に渡してください。ログインのときは 6 桁のコードの欄に入れ、何度でも使えます）' : '予備のコード（<strong>この画面を離れると消えます</strong>）' ?></p>
      <ul class="codes"><?php foreach ($shownBackupCodes as $shownCode): ?><li><?= h($shownCode) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($shownRecoveryCode !== null): ?>
      <p>復旧コード（<strong>一度だけ表示します</strong>。<?= RECOVERY_CODE_MINUTES ?> 分・1 回限り。ログインの「コード」の欄に入れてもらいます）</p>
      <p class="secret"><?= h($shownRecoveryCode) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= h(url($selfUrl)) ?>" class="form">
      <?= form_hidden() ?>
      <label for="security_reason">理由（本人に知らせます）</label>
      <input id="security_reason" name="reason" type="text" maxlength="<?= SECURITY_REASON_MAX_LENGTH ?>" required>
      <label for="operator_password">あなたのパスワード</label>
      <input id="operator_password" name="operator_password" type="password" autocomplete="current-password" required>
      <button type="submit" name="action" value="recovery_issue">復旧コードを発行</button>
      <button type="submit" name="action" value="view_backup_codes">予備のコードを確認</button>
    </form>
    <?php endif; ?>
    <?php if (is_admin_deputy_lead($user) && (int) $target['id'] !== (int) $user['id'] && (int) $target['is_active'] === 1 && $target['totp_enabled_at'] === null && !security_is_frozen($target) && !security_is_protected($target)): ?>
    <h2 id="codes-issue">予備のコードの発行（補佐（開発担当））</h2>
    <p class="note"><?= h_phrases('2 段階認証が未設定の人に、予備のコードを発行し、この画面に一度だけ出します。本人に、あなたの名前と役割・理由を知らせます（本人のホームに 30 日出ます）。記録にも残ります。') ?></p>
    <form method="post" action="<?= h(url($selfUrl)) ?>" class="form" data-confirm="<?= h(msg('この人の予備のコードを発行します。本人に知らせます。よろしいですか？')) ?>">
      <?= form_hidden() ?>
      <label for="codes_issue_reason">理由（本人に知らせます）</label>
      <input id="codes_issue_reason" name="reason" type="text" maxlength="<?= SECURITY_REASON_MAX_LENGTH ?>" required>
      <label for="codes_issue_password">あなたのパスワード</label>
      <input id="codes_issue_password" name="operator_password" type="password" autocomplete="current-password" required>
      <button type="submit" name="action" value="codes_issue">予備のコードを発行</button>
    </form>
    <?php endif; ?>
    <?php if (security_is_protected($target) && is_admin($user)): ?>
      <p class="note"><?= h_phrases('補佐（開発担当）のパスワードと 2 段階認証は、管理者だけでは初期化できません。') ?><a href="<?= h(url('/admin/security.php#lead-recovery')) ?>">復旧を申請する</a></p>
    <?php endif; ?>
    <?php if (security_is_frozen($target)): ?>
      <div class="flash flash-error"><?= h_phrases('凍結中です（本人が「心当たりがない」と知らせました）。') ?><?php if (has_admin_authority($user)): ?> <a href="<?= h(url('/admin/security.php')) ?>">アカウントの凍結</a><?php endif; ?></div>
    <?php endif; ?>
  <?php endif; ?>
</section>
