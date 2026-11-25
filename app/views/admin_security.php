<?php
/** @var array $user */
/** @var string[] $errors */
/** @var array{label: string, password: string}|null $halfPassword 仮のパスワードの前半・後半（一度だけ） */
/** @var array|null $leadRecovery 承認を待つ開発担当の復旧の申請 */
/** @var bool $canRequestLeadRecovery */
/** @var array $protectedUserList */
/** @var bool $isAuthority 管理者・補佐（開発担当）。false は総入れ替えの代わりの補佐 */
/** @var string|null $temp */
/** @var array|null $tempUser */
/** @var array $freezeList */
/** @var array{isNeeded: bool, operatorIdList: int[]} $massResetState */
/** @var array|null $massResetRequest 承認を待つ申請 */
/** @var bool $canApproveMassReset */
?>
<section class="card">
  <h1>アカウントの凍結</h1>
  <?php foreach ($errors as $error): ?><div class="flash flash-error" role="alert"><?= h_lines($error) ?></div><?php endforeach; ?>
  <?php if ($temp !== null && $tempUser !== null): ?>
    <div class="flash flash-info" role="status">
      <p><?= h_phrases(security_display_name($tempUser) . ' さんを再初期化しました。仮のパスワード（一度だけ表示します）:') ?></p>
      <p class="secret"><?= h($temp) ?></p>
      <p class="note"><?= h_phrases('電話など、本人と確かめられる方法で伝えてください。本人は、次のログインで 2 段階認証の初期設定からやり直します。') ?></p>
    </div>
  <?php endif; ?>
  <?php if (!$isAuthority): ?>
  <p class="note"><?= h_phrases('補佐の画面では、承認を求められている操作だけを出します。') ?></p>
  <?php else: ?>
  <p class="note"><?= h_phrases('本人が「心当たりがない」と知らせて凍結したアカウントです。本人に確かめてから、再初期化して凍結を解いてください。ログインの情報だけを作り直し、報告・課・役割は変わりません。') ?></p>
  <?php if (!$freezeList): ?><p>凍結中のアカウントはありません。</p><?php endif; ?>
  <?php endif; ?>
  <?php foreach ($freezeList as $freeze): ?>
    <section class="card">
      <h2><?= h($freeze['display_name'] !== '' ? $freeze['display_name'] : $freeze['login_id']) ?>（<?= h((string) $freeze['login_id']) ?>）</h2>
      <ul class="list">
        <li><span>凍結</span><span><?= h(display_datetime((string) $freeze['created_at'])) ?></span></li>
        <li><span>心当たりがない操作</span><span><?= h(['codes_view' => '予備のコードの確認', 'recovery_issue' => '復旧コードの発行', 'recovery_used' => '復旧コードの使用', 'reinit' => 'アカウントの再初期化'][$freeze['kind']] ?? '') ?>（<?= h(display_datetime((string) $freeze['notice_at'])) ?>）</span></li>
        <li><span>操作した人</span><span><?= h((string) $freeze['actor_name']) ?>（<?= h((string) $freeze['actor_role']) ?>）</span></li>
        <li><span>操作の理由</span><span><?= h((string) $freeze['reason']) ?></span></li>
      </ul>
      <?php if (security_can_reinit($user, (int) $freeze['user_id'])): ?>
        <form method="post" action="<?= h(url('/admin/security.php')) ?>" class="form" data-confirm="<?= h(msg('再初期化して凍結を解きます。本人に確かめましたか？')) ?>">
          <?= form_hidden() ?>
          <input type="hidden" name="action" value="freeze_reinit">
          <input type="hidden" name="user_id" value="<?= (int) $freeze['user_id'] ?>">
          <label for="reason_<?= (int) $freeze['id'] ?>">理由（本人に知らせます）</label>
          <input id="reason_<?= (int) $freeze['id'] ?>" name="reason" type="text" maxlength="<?= SECURITY_REASON_MAX_LENGTH ?>" required>
          <label for="operator_password_<?= (int) $freeze['id'] ?>">あなたのパスワード</label>
          <input id="operator_password_<?= (int) $freeze['id'] ?>" name="operator_password" type="password" autocomplete="current-password" required>
          <button type="submit" class="primary">再初期化して凍結を解く</button>
        </form>
      <?php else: ?>
        <p class="note"><?= h_phrases(security_is_protected((array) db_one('SELECT * FROM users WHERE id = ?', [(int) $freeze['user_id']])) ? '補佐（開発担当）は、下の「補佐（開発担当）の復旧」で、管理者と別の補佐の 2 人で再初期化します。' : 'この凍結のもとになった操作をした人は、再初期化できません。ほかの管理者か開発担当が行ってください。') ?></p>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</section>
<?php if ($halfPassword !== null): ?>
<section class="card">
  <div class="flash flash-info" role="status">
    <p><?= h($halfPassword['label']) ?>（<strong>一度だけ表示します</strong>）</p>
    <p class="secret"><?= h($halfPassword['password']) ?></p>
    <p class="note"><?= h_phrases('本人に、電話など本人と確かめられる方法で直接伝えてください。もう 1 人には伝えないでください。本人は、前半のあとに後半を続けて入れ、2 段階認証の初期設定からやり直します。') ?></p>
  </div>
</section>
<?php endif; ?>
<?php if ($leadRecovery !== null || ($canRequestLeadRecovery && $protectedUserList)): ?>
<section class="card" id="lead-recovery">
  <h2>補佐（開発担当）の復旧</h2>
  <p class="note"><?= h_phrases('補佐（開発担当）だった人を含めて、管理者だけではパスワードや 2 段階認証を初期化できません。管理者が申請し、別の補佐が承認すると再初期化します。仮のパスワードの前半は申請した人に、後半は承認した人にだけ出ます。') ?></p>
  <?php if ($leadRecovery !== null): ?>
    <p><?= h_phrases('申請: ' . $leadRecovery['requester_name'] . ' さん（' . $leadRecovery['requester_role'] . '）→ ' . ($leadRecovery['target_display_name'] !== '' ? $leadRecovery['target_display_name'] : $leadRecovery['target_login_id']) . ' さん。' . display_datetime((string) $leadRecovery['created_at']) . '。理由: ' . $leadRecovery['reason']) ?></p>
    <?php if (security_can_approve_lead_recovery($user, $leadRecovery)): ?>
    <form method="post" action="<?= h(url('/admin/security.php')) ?>" class="form" data-confirm="<?= h(msg('承認すると、本人のログインの情報を再初期化します。本人に確かめましたか？')) ?>">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="lead_recovery_approve">
      <input type="hidden" name="request_id" value="<?= (int) $leadRecovery['id'] ?>">
      <label for="lead_reason">承認の理由</label>
      <input id="lead_reason" name="reason" type="text" maxlength="<?= SECURITY_REASON_MAX_LENGTH ?>" required>
      <label for="lead_operator_password">あなたのパスワード</label>
      <input id="lead_operator_password" name="operator_password" type="password" autocomplete="current-password" required>
      <button type="submit" class="primary">承認して再初期化する</button>
    </form>
    <?php else: ?>
    <p class="note"><?= h_phrases('別の補佐の承認を待っています。') ?></p>
    <?php endif; ?>
    <form method="post" action="<?= h(url('/admin/security.php')) ?>" class="inline">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="lead_recovery_cancel">
      <input type="hidden" name="request_id" value="<?= (int) $leadRecovery['id'] ?>">
      <button type="submit">復旧の申請を取り消す</button>
    </form>
  <?php elseif ($canRequestLeadRecovery): ?>
    <form method="post" action="<?= h(url('/admin/security.php')) ?>" class="form" data-confirm="<?= h(msg('復旧を申請します。別の補佐が承認するまで、再初期化しません。よろしいですか？')) ?>">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="lead_recovery_request">
      <label for="lead_user_id">復旧する人</label>
      <select id="lead_user_id" name="user_id">
        <?php foreach ($protectedUserList as $protectedUser): ?><option value="<?= (int) $protectedUser['id'] ?>"><?= h(security_display_name($protectedUser)) ?></option><?php endforeach; ?>
      </select>
      <label for="lead_reason">理由（本人に知らせます）</label>
      <input id="lead_reason" name="reason" type="text" maxlength="<?= SECURITY_REASON_MAX_LENGTH ?>" required>
      <label for="lead_operator_password">あなたのパスワード</label>
      <input id="lead_operator_password" name="operator_password" type="password" autocomplete="current-password" required>
      <button type="submit">復旧を申請する</button>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($massResetState['isNeeded'] && security_is_mass_reset_member($user)): ?>
<section class="card" id="mass-reset">
  <h2>全員の総入れ替え</h2>
  <div class="flash flash-error" role="alert"><?= h_phrases('同じ人の 2 回目の凍結、または 30 日に 2 人以上の凍結がありました。まず本人の端末と、操作した人の記録を確かめ、疑いが広いときに実行してください。') ?></div>
  <p class="note"><?= h_phrases('全員の端末・ログイン・予備のコード・復旧コードを無効にします。次のログインで、今のパスワードと認証アプリで確かめたうえで、パスワードの変更と 2 段階認証の設定し直しを求めます。今のパスワードは、全員使えなくなります。') ?></p>
  <?php if ($massResetRequest !== null): ?>
    <p><?= h_phrases('申請: ' . $massResetRequest['requester_name'] . ' さん（' . $massResetRequest['requester_role'] . '）、' . display_datetime((string) $massResetRequest['created_at']) . '。理由: ' . $massResetRequest['reason']) ?></p>
    <?php if ($canApproveMassReset): ?>
    <form method="post" action="<?= h(url('/admin/security.php')) ?>" class="form" data-confirm="<?= h(msg('全員のログインの情報を総入れ替えにします。全員がログアウトし、元に戻せません。申請した人と連絡を取り合いましたか？')) ?>">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="mass_approve">
      <input type="hidden" name="request_id" value="<?= (int) $massResetRequest['id'] ?>">
      <label for="mass_reason">承認の理由（記録に残します）</label>
      <input id="mass_reason" name="reason" type="text" maxlength="<?= SECURITY_REASON_MAX_LENGTH ?>" required>
      <label for="mass_operator_password">あなたのパスワード</label>
      <input id="mass_operator_password" name="operator_password" type="password" autocomplete="current-password" required>
      <button type="submit">承認して全員を総入れ替えにする</button>
    </form>
    <?php else: ?>
    <p class="note"><?= h_phrases('もう 1 人（システム管理者と補佐（開発担当。いないときは別の補佐）のうち、申請した人でないほう）の承認を待っています。') ?></p>
    <?php endif; ?>
    <form method="post" action="<?= h(url('/admin/security.php')) ?>" class="inline">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="mass_cancel">
      <input type="hidden" name="request_id" value="<?= (int) $massResetRequest['id'] ?>">
      <button type="submit">申請を取り消す</button>
    </form>
  <?php else: ?>
    <p class="note"><?= h_phrases('システム管理者と補佐（開発担当）が連絡を取り合い、1 人が申請し、もう 1 人が承認すると実施します。1 人では実施できません。') ?></p>
    <form method="post" action="<?= h(url('/admin/security.php')) ?>" class="form" data-confirm="<?= h(msg('総入れ替えを申請します。もう 1 人が承認するまで実施しません。よろしいですか？')) ?>">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="mass_request">
      <label for="mass_reason">理由（実施すると全員に知らせます）</label>
      <input id="mass_reason" name="reason" type="text" maxlength="<?= SECURITY_REASON_MAX_LENGTH ?>" required>
      <label for="mass_operator_password">あなたのパスワード</label>
      <input id="mass_operator_password" name="operator_password" type="password" autocomplete="current-password" required>
      <button type="submit">総入れ替えを申請する</button>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>
