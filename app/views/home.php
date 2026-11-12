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
<p class="greeting"><?= h($user['display_name'] !== '' ? $user['display_name'] : $user['login_id']) ?> さん</p>

<?php if ($user['department_id'] === null): ?>
  <div class="flash flash-error">所属の課が設定されていません。管理者に連絡してください。</div>
<?php endif; ?>

<section class="card">
  <h2><?= h(period_label($period)) ?>の報告</h2>
  <p>状態: <strong><?= h(report_status_label($mine['status'] ?? null)) ?></strong></p>
  <?php if ($closeDate !== null): ?>
    <p class="<?= $closeSoon ? 'warn' : '' ?>">締め日: <?= h($closeDate) ?></p>
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

<?php if ($openComments): ?>
<section class="card">
  <h2>回答待ちのコメント <?= count($openComments) ?>件</h2>
  <ul class="list">
    <?php foreach (array_slice($openComments, 0, 5) as $c): ?>
      <li><a href="<?= h(url('/reports/view.php?id=' . (int) $c['report_id'] . '#comment-' . (int) $c['id'])) ?>">
        <?= h($c['field_label']) ?>（<?= h($c['author']) ?>さん）</a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($surveys): ?>
<section class="card">
  <h2>臨時の調査 <?= count($surveys) ?>件</h2>
  <ul class="list">
    <?php foreach ($surveys as $s): ?>
      <li><a href="<?= h(url('/surveys/answer.php?id=' . (int) $s['id'])) ?>"><?= h($s['title']) ?></a>
        <?php if ($s['due_at'] !== null): ?><span class="note">期限 <?= h(substr((string) $s['due_at'], 0, 10)) ?></span><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php foreach ($reviewSections as $r): ?>
<section class="card reviewer">
  <h2><?= h($r['dept']['name']) ?> <?= h(period_label($period)) ?>（確認）</h2>
  <p>未チェック <strong><?= (int) $r['unchecked'] ?></strong>件 ／ 回答あり <strong><?= (int) $r['answered'] ?></strong>件
    <?php if ($r['notSubmitted'] !== null): ?> ／ 未提出 <strong><?= (int) $r['notSubmitted'] ?></strong>人<?php endif; ?></p>
  <?php if ($r['unchecked'] > 0): ?>
    <a class="button primary" href="<?= h(url('/status/index.php?period=' . rawurlencode($period) . '&dept=' . (int) $r['dept']['id'] . '&filter=unchecked&go=1')) ?>">未チェックを順に見る</a>
  <?php endif; ?>
  <a class="button" href="<?= h(url('/status/index.php?period=' . rawurlencode($period) . '&dept=' . (int) $r['dept']['id'])) ?>">提出状況</a>
</section>
<?php endforeach; ?>

<?php if ($mine !== null && $mine['status'] !== 'draft' && !$openComments && !$surveys && !$reviewSections): ?>
  <p class="note">いま対応が必要なものはありません。</p>
<?php endif; ?>
