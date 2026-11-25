<?php
/** @var array $user */
/** @var string $period */
/** @var string $prevPeriod */
/** @var array|null $mine */
/** @var array|null $prev */
/** @var string|null $closeDate */
/** @var bool $closeSoon */
/** @var array $openComments */
/** @var array $surveys */
/** @var array $reviewSections */
$editUrl = url('/reports/edit.php?period=' . rawurlencode($period));
?>
<?php /** @var array $securityNoticeList 本人への知らせ（予備のコードの確認など。30 日。消せない） */ ?>
<?php foreach ($securityNoticeList as $securityNotice): ?>
  <div class="flash flash-error security-notice" role="alert">
    <p><?= h_phrases(security_notice_text($securityNotice)) ?></p>
    <?php if (in_array($securityNotice['kind'], SECURITY_REPORTABLE_KINDS, true) && $securityNotice['reported_at'] === null): ?>
    <form method="post" action="<?= h(url('/account.php')) ?>" class="inline" data-confirm="<?= h(msg('システム管理者と補佐（開発担当）に知らせ、あなたのアカウントを一時凍結します。再初期化が済むまでログインできません。よろしいですか？')) ?>">
      <?= form_hidden(false) ?>
      <input type="hidden" name="action" value="security_report">
      <input type="hidden" name="notice_id" value="<?= (int) $securityNotice['id'] ?>">
      <button type="submit">心当たりがない（知らせて凍結する）</button>
    </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php /** @var int $openFreezeCount 再初期化を待っている凍結（管理者・補佐（開発担当）だけ） */ ?>
<?php if ($openFreezeCount > 0): ?>
  <div class="flash flash-error" role="alert"><a href="<?= h(url('/admin/security.php')) ?>"><?= h_phrases(msg('心当たりがないと知らせて凍結したアカウントがあります（{count} 件）。本人に確かめて、再初期化してください', ['count' => $openFreezeCount])) ?></a></div>
<?php endif; ?>
<?php /** @var bool $isMassResetNeeded 全員の総入れ替えを考える状態（管理者・補佐（開発担当）だけ） */ ?>
<?php if ($isMassResetNeeded): ?>
  <div class="flash flash-error" role="alert"><a href="<?= h(url('/admin/security.php#mass-reset')) ?>"><?= h_phrases($hasMassResetRequest ? msg('全員の総入れ替えの申請があります。申請した人と連絡を取り合い、承認するかを決めてください') : msg('凍結が続いています。システム管理者と補佐（開発担当）で連絡を取り合い、全員の総入れ替えが要るかを確かめてください')) ?></a></div>
<?php endif; ?>
<?php /** @var bool $hasLeadRecoveryRequest 開発担当の復旧の申請（管理者・補佐） */ ?>
<?php if ($hasLeadRecoveryRequest): ?>
  <div class="flash flash-error" role="alert"><a href="<?= h(url('/admin/security.php#lead-recovery')) ?>"><?= h_phrases(msg('補佐（開発担当）の復旧の申請があります。本人と連絡を取り、心当たりがなければ取り消してください')) ?></a></div>
<?php endif; ?>
<?php /** @var bool $isDeputyMissing 開発担当でない補佐が 1 人もいない（管理者・補佐だけ） */ ?>
<?php if ($isDeputyMissing): ?>
  <div class="flash flash-error" role="alert"><a href="<?= h(url('/admin/org.php?tab=users')) ?>"><?= h_phrases(msg('開発担当のほかに、システム管理者補佐がいません。システム管理者と、開発担当でない補佐を、1 名ずつ以上置いてください')) ?></a></div>
<?php endif; ?>
<?php /** @var bool $hasUnreadableBackupCode 古い形の予備のコード（管理者が確認できない） */ ?>
<?php if ($hasUnreadableBackupCode): ?>
  <div class="flash flash-info"><a href="<?= h(url('/account.php?tab=codes')) ?>"><?= h_phrases(msg('予備のコードを作り直してください（なくしたときに、システム管理者が確認できる形になります）')) ?></a></div>
<?php endif; ?>
<?php /** @var array<int, array{id: int|string, name: string}> $departmentsWithoutFinal 課長がいない課（管理者だけ） */ ?>
<?php if ($departmentsWithoutFinal): ?>
  <div class="flash flash-error" role="alert">
    <?= h_phrases(msg('課長がいない課があります（確認を完了にできません）: {departments}', ['departments' => implode('、', array_column($departmentsWithoutFinal, 'name'))])) ?>
    <a href="<?= h(url('/admin/org.php?tab=departments')) ?>">組織・ユーザーで設定する</a>
  </div>
<?php endif; ?>
<?php /** @var bool $hasNoBackupCode 2 段階認証を設定していて、使える予備のコードが 1 つもない */ ?>
<?php if ($hasNoBackupCode): ?>
  <div class="flash flash-error" role="alert"><a href="<?= h(url('/account.php?tab=codes')) ?>"><?= h_phrases(msg('予備のコードがありません。認証アプリが使えなくなったときに備えて、アカウント設定の「予備のコード」で作り直してください')) ?></a></div>
<?php endif; ?>
<?php /** @var int $supportRequestAnsweredCount 管理者側から返信があった自分の要望・依頼の数 */ ?>
<?php /** @var array{open: int, waiting_reply: int}|null $supportRequestStaffCounts 管理者・補佐に知らせる要望・依頼の数 */ ?>
<?php if ($supportRequestAnsweredCount > 0): ?>
  <div class="flash flash-info"><a href="<?= h(url('/requests/index.php?status=all')) ?>"><?= h_phrases(msg('要望・依頼にシステム管理者から回答があります（{count} 件）', ['count' => $supportRequestAnsweredCount])) ?></a></div>
<?php endif; ?>
<?php if ($supportRequestStaffCounts !== null && ($supportRequestStaffCounts['open'] + $supportRequestStaffCounts['waiting_reply']) > 0): ?>
  <div class="flash flash-info"><a href="<?= h(url('/requests/index.php')) ?>"><?= h_phrases(msg('要望・依頼: 未受付 {open} 件、返信待ち {waiting} 件', ['open' => $supportRequestStaffCounts['open'], 'waiting' => $supportRequestStaffCounts['waiting_reply']])) ?></a></div>
<?php endif; ?>
<?php /** @var array{urgent: int, concern: int, retirement: int, credential: int}|null $faqDistressCounts まだ対応していない、知らせる質問の数（管理者はすべて、補佐は ID・パスワードだけ） */ ?>
<?php if ($faqDistressCounts !== null && $faqDistressCounts['urgent'] + $faqDistressCounts['concern'] + $faqDistressCounts['retirement'] > 0): ?>
  <div class="flash flash-error" role="alert"><a href="<?= h(url('/admin/faq_logs.php#distress')) ?>"><?= h_phrases(msg('よくある質問のチャットに、心の不調らしい文や退職に関わる質問があります（未対応 {count} 件。うち命に関わる言葉 {urgent} 件）。確かめて、対応してください', ['count' => $faqDistressCounts['urgent'] + $faqDistressCounts['concern'] + $faqDistressCounts['retirement'], 'urgent' => $faqDistressCounts['urgent']])) ?></a></div>
<?php endif; ?>
<?php /** @var int $unknownLoginCount まだ確かめていない、無い ID でのログインの数（システム管理者・補佐だけ） */ ?>
<?php if ($unknownLoginCount > 0): ?>
  <div class="flash flash-error" role="alert"><a href="<?= h(url('/admin/logs.php?kind=auth_unknown')) ?>"><?= h_phrases(msg('無い ID でログインしようとした記録があります（未確認 {count} 件）。なりすましのおそれがないか確かめてください', ['count' => $unknownLoginCount])) ?></a></div>
<?php endif; ?>
<?php if ($faqDistressCounts !== null && $faqDistressCounts['violence'] > 0): ?>
  <div class="flash flash-error" role="alert"><a href="<?= h(url('/admin/faq_logs.php#distress')) ?>"><?= h_phrases(msg('よくある質問のチャットに、人への暴力を書いた文があります（未対応 {count} 件）。確かめて、対応してください', ['count' => $faqDistressCounts['violence']])) ?></a></div>
<?php endif; ?>
<?php if ($faqDistressCounts !== null && $faqDistressCounts['sabotage'] > 0): ?>
  <div class="flash flash-error" role="alert"><a href="<?= h(url('/admin/faq_logs.php#distress')) ?>"><?= h_phrases(msg('よくある質問のチャットに、システムやデータを壊す・消すと書いた文があります（未対応 {count} 件）。確かめて、対応してください', ['count' => $faqDistressCounts['sabotage']])) ?></a></div>
<?php endif; ?>
<?php if ($faqDistressCounts !== null && $faqDistressCounts['credential'] > 0): ?>
  <div class="flash flash-error" role="alert"><a href="<?= h(url('/admin/faq_logs.php#distress')) ?>"><?= h_phrases(msg('よくある質問のチャットに、ID・パスワードを求める質問があります（未対応 {count} 件）。なりすましのおそれがないか確かめてください', ['count' => $faqDistressCounts['credential']])) ?></a></div>
<?php endif; ?>
<p class="greeting"><?= h($user['display_name'] !== '' ? $user['display_name'] : $user['login_id']) ?> さん</p>
<?php /** @var string[] $roleLabelList 役割の札 */ ?>
<?php /** @var string[] $roleDutyTextList 課ごとの担当 */ ?>
<?php /** @var string[] $rolePermissionList できること */ ?>
<section class="card role-summary">
  <h2>あなたの役割と権限</h2>
  <p><?php foreach ($roleLabelList as $roleLabel): ?><span class="tag"><?= h($roleLabel) ?></span><?php endforeach; ?></p>
  <?php if ($roleDutyTextList): ?><p>担当: <?= h_phrases(implode('、', $roleDutyTextList)) ?></p><?php endif; ?>
  <?php if ($rolePermissionList): ?>
  <details>
    <summary>できること</summary>
    <ul class="role-permission-list">
      <?php foreach ($rolePermissionList as $rolePermission): ?><li><?= h_phrases($rolePermission) ?></li><?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>
</section>
<?php /** @var array<int, array<string, int|float|string|null>> $activeNoticeList 出している更新のお知らせ */ ?>
<?php if ($activeNoticeList): ?>
<section class="card update-notices">
  <h2>更新のお知らせ</h2>
  <?php foreach ($activeNoticeList as $noticeRow): ?>
    <details class="update-notice">
      <summary><span class="note"><?= h(display_date((string) $noticeRow['show_from'])) ?></span> <?= h((string) $noticeRow['title']) ?></summary>
      <?php if ((string) $noticeRow['body'] !== ''): ?><div class="update-notice-body"><?= h_lines((string) $noticeRow['body']) ?></div><?php endif; ?>
    </details>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($user['department_id'] === null && (survey_can_manage_all_departments($user) || survey_has_result_viewing_menu($user))): ?>
<?php // 課のない管理者・補佐と、課のない部長以上・依頼元の担当（全課共通の調査の結果を見る人。K-067）は、エラーではなく入口を出す ?>
<section class="card">
  <h2><?= can_manage_system($user) ? '管理' : '見られるもの' ?></h2>
  <p class="note"><?= h_phrases('所属の課がないため、報告の欄は出していません。') ?></p>
  <ul class="shortcut-list">
    <?php foreach (menu_items($user) as $menuItem): if (!in_array($menuItem[2], ['確認', '管理'], true)) { continue; } ?>
      <li><a href="<?= h(url($menuItem[0])) ?>"><?= h($menuItem[1]) ?></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php elseif ($user['department_id'] === null): ?>
  <div class="flash flash-error"><?= h_phrases(msg('所属の課が設定されていません。管理者に連絡してください。')) ?></div>
<?php else: ?>
<section class="card">
  <h2><?= h(period_label($period)) ?>の報告</h2>
  <p>状態: <?= report_status_tag_html($mine['status'] ?? null) ?></p>
  <?php if ($closeDate !== null): ?>
    <p class="<?= $closeSoon ? 'warn' : '' ?>">締め日: <?= h(display_date($closeDate)) ?></p>
  <?php endif; ?>
  <?php if ($mine === null): ?>
    <a class="button primary big" href="<?= h($editUrl) ?>">今月の報告を作る</a>
  <?php elseif ($mine['status'] === 'draft'): ?>
    <a class="button primary big" href="<?= h($editUrl) ?>">続きを書く</a>
  <?php else: ?>
    <a class="button big" href="<?= h(url('/reports/view.php?id=' . (int) $mine['id'])) ?>">報告を見る</a>
  <?php endif; ?>
  <?php if ($prev === null || $prev['status'] === 'draft'): ?>
    <p class="note"><?= h(period_label($prevPeriod)) ?>の報告がまだです。
      <a href="<?= h(url('/reports/edit.php?period=' . rawurlencode($prevPeriod))) ?>">作る</a></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($openComments): ?>
<section class="card">
  <h2>回答待ちのコメント <?= count($openComments) ?>件</h2>
  <ul class="list">
    <?php foreach (array_slice($openComments, 0, 5) as $c): ?>
      <li><a href="<?= h(url('/reports/view.php?id=' . (int) $c['report_id'] . '#comment-' . (int) $c['id'])) ?>">
        <?= h($c['field_label']) ?>（<?= h($c['author']) ?>さん）</a></li>
    <?php endforeach; ?>
  </ul>
  <a class="button" href="<?= h(url('/reports/comments.php?tab=received&status=open')) ?>">コメントの一覧</a>
</section>
<?php endif; ?>

<?php if ($surveys): ?>
<section class="card">
  <h2>臨時の調査 <?= count($surveys) ?>件</h2>
  <ul class="list">
    <?php foreach ($surveys as $s): ?>
      <li><a href="<?= h(url('/surveys/answer.php?id=' . (int) $s['id'])) ?>"><?= h($s['title']) ?></a>
        <?php if ($s['due_at'] !== null): ?><span class="note">期限 <?= h(display_date($s['due_at'])) ?></span><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php foreach ($reviewSections as $r): ?>
<section class="card reviewer">
  <h2><?= h($r['dept']['name']) ?> <?= h(period_label($period)) ?>（確認）</h2>
  <p><?php if ($r['doesCheck']): ?>未チェック <strong><?= (int) $r['unchecked'] ?></strong>件 ／ <?php endif; ?>回答あり <strong><?= (int) $r['answered'] ?></strong>件
    <?php if ($r['notSubmitted'] !== null): ?> ／ 未提出 <strong><?= (int) $r['notSubmitted'] ?></strong>人<?php endif; ?></p>
  <?php if (!$r['doesCheck']): ?><p class="note"><?= h_phrases('この課では、報告のチェックをしない設定です（課長が「課の管理」で決めます）。報告を見ること・コメントはできます。') ?></p><?php endif; ?>
  <?php if ($r['unchecked'] > 0): ?>
    <a class="button primary" href="<?= h(url('/status/index.php?period=' . rawurlencode($period) . '&dept=' . (int) $r['dept']['id'] . '&filter=unchecked&go=1')) ?>">未チェックを順に見る</a>
  <?php endif; ?>
  <a class="button" href="<?= h(url('/status/index.php?period=' . rawurlencode($period) . '&dept=' . (int) $r['dept']['id'])) ?>">提出状況</a>
  <?php if ($r['answered'] > 0): ?>
    <a class="button" href="<?= h(url('/reports/comments.php?tab=sent&status=answered')) ?>">回答ありのコメント</a>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<?php if ($mine !== null && $mine['status'] !== 'draft' && !$openComments && !$surveys && !$reviewSections): ?>
  <p class="note">いま対応が必要なものはありません。</p>
<?php endif; ?>
