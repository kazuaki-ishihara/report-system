<?php
/** @var array $reports */
/** @var array $comments */
/** @var array $surveys */
$items = [];
foreach ($reports as $r) {
    $items[] = ['報告（' . report_status_label($r['status']) . '）', $r['display_name'] . ' ' . period_label($r['period']), $r['deleted_at'], 'restore_report', (int) $r['id']];
}
foreach ($comments as $c) {
    $items[] = ['コメント', '報告 #' . (int) $c['report_id'] . ' ' . $c['field_label'] . '（' . $c['display_name'] . '）', $c['deleted_at'], 'restore_comment', (int) $c['id']];
}
foreach ($surveys as $s) {
    $items[] = ['臨時の調査', $s['title'] . '（' . $s['display_name'] . '）', $s['deleted_at'], 'restore_survey', (int) $s['id']];
}
?>
<section class="card wide">
  <h1>削除データ</h1>
  <nav class="tabs">
    <a href="<?= h(url('/admin/logs.php')) ?>">記録</a>
    <a href="<?= h(url('/admin/trash.php')) ?>" class="active">削除データ</a>
  </nav>
  <p class="note">削除から <?= (int) cfg('trash_days') ?> 日で完全に消えます。</p>
  <?php if ($isOperatorDeputy): ?>
  <p class="note">補佐の画面では、種類ごとの件数だけを出します。戻すのは管理者が行います。</p>
  <table class="table">
    <thead><tr><th>種類</th><th>件数</th></tr></thead>
    <tbody>
      <tr><td>報告</td><td><?= count($reports) ?></td></tr>
      <tr><td>コメント</td><td><?= count($comments) ?></td></tr>
      <tr><td>臨時の調査</td><td><?= count($surveys) ?></td></tr>
    </tbody>
  </table>
  <?php elseif (!$items): ?><p>削除データはありません。</p><?php else: ?>
  <table class="table">
    <thead><tr><th>種類</th><th>内容</th><th>削除日時</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr><td><?= h($it[0]) ?></td><td><?= h($it[1]) ?></td><td><?= h(display_datetime((string) $it[2])) ?></td>
        <td><form method="post" action="<?= h(url('/admin/trash.php')) ?>" class="inline"><?= form_hidden() ?>
          <input type="hidden" name="id" value="<?= $it[4] ?>"><button type="submit" name="action" value="<?= h($it[3]) ?>" class="small">戻す</button></form></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
