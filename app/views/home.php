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
<?php /** @var array<int, array{id: int|string, name: string}> $departmentsWithoutFinal 課長がいない課（管理者だけ） */ ?>
<?php if ($departmentsWithoutFinal): ?>
  <div class="flash flash-error" role="alert">
    <?= h(msg('課長がいない課があります（確認を完了にできません）: {departments}', ['departments' => implode('、', array_column($departmentsWithoutFinal, 'name'))])) ?>
    <a href="<?= h(url('/admin/org.php?tab=departments')) ?>">組織・ユーザーで設定する</a>
  </div>
<?php endif; ?>
<?php /** @var int $supportRequestAnsweredCount 管理者側から返信があった自分の要望・依頼の数 */ ?>
<?php /** @var array{open: int, waiting_reply: int}|null $supportRequestStaffCounts 管理者・補佐に知らせる要望・依頼の数 */ ?>
<?php if ($supportRequestAnsweredCount > 0): ?>
  <div class="flash flash-info"><a href="<?= h(url('/requests/index.php?status=all')) ?>"><?= h(msg('要望・依頼にシステム管理者から回答があります（{count} 件）', ['count' => $supportRequestAnsweredCount])) ?></a></div>
<?php endif; ?>
<?php if ($supportRequestStaffCounts !== null && ($supportRequestStaffCounts['open'] + $supportRequestStaffCounts['waiting_reply']) > 0): ?>
  <div class="flash flash-info"><a href="<?= h(url('/requests/index.php')) ?>"><?= h(msg('要望・依頼: 未受付 {open} 件、返信待ち {waiting} 件', ['open' => $supportRequestStaffCounts['open'], 'waiting' => $supportRequestStaffCounts['waiting_reply']])) ?></a></div>
<?php endif; ?>
<p class="greeting"><?= h($user['display_name'] !== '' ? $user['display_name'] : $user['login_id']) ?> さん</p>
<?php /** @var array<int, array<string, mixed>> $activeNoticeList 出している更新のお知らせ */ ?>
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

<?php if ($user['department_id'] === null && can_manage_system($user)): ?>
<section class="card">
  <h2>管理</h2>
  <p class="note">所属の課がないため、報告の欄は出していません。</p>
  <ul class="shortcut-list">
    <?php foreach (menu_items($user) as $menuItem): if (!in_array($menuItem[2], ['確認', '管理'], true)) { continue; } ?>
      <li><a href="<?= h(url($menuItem[0])) ?>"><?= h($menuItem[1]) ?></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php elseif ($user['department_id'] === null): ?>
  <div class="flash flash-error"><?= h_lines(msg('所属の課が設定されていません。管理者に連絡してください。')) ?></div>
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
  <?php if (!$r['doesCheck']): ?><p class="note">この課では、報告のチェックをしない設定です（課長が「課の管理」で決めます）。報告を見ること・コメントはできます。</p><?php endif; ?>
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
