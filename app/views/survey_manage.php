<?php
/** @var array $rows */
/** @var string $state */
$states = ['all' => 'すべて', 'draft' => '下書き', 'open' => '公開中', 'closed' => '締め切り', 'template' => 'ひな形'];
$labels = ['draft' => '下書き', 'open' => '公開中', 'closed' => '締め切り'];
?>
<section class="card wide">
  <h1>臨時の調査</h1>
  <form method="get" action="<?= h(url('/surveys/manage.php')) ?>" class="filters">
    <select name="state"><?php foreach ($states as $k => $l): ?><option value="<?= h($k) ?>" <?= $state === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
    <button type="submit">表示</button>
    <a class="button primary" href="<?= h(url('/surveys/survey_edit.php')) ?>">＋ 新しく作る</a>
  </form>
  <table class="table">
    <thead><tr><th>題名</th><th>課</th><th>状態</th><th>期限</th><th>回答</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['title']) ?><?php if ($r['requester'] !== ''): ?><br><span class="note">依頼元: <?= h($r['requester']) ?></span><?php endif; ?></td>
        <td><?= h($r['dept_name']) ?></td>
        <td><?= (int) $r['is_template'] === 1 ? 'ひな形' : h($labels[$r['status']] ?? '') ?></td>
        <td><?= h((string) ($r['due_at'] ?? '—')) ?></td>
        <td><?= $r['status'] === 'draft' ? '—' : (int) $r['answered'] . '/' . (int) $r['targets'] ?></td>
        <td class="actions">
          <a href="<?= h(url('/surveys/survey_edit.php?id=' . (int) $r['id'])) ?>"><?= (int) $r['is_template'] === 1 || $r['status'] === 'draft' ? '編集' : '設定' ?></a>
          <?php if ($r['status'] !== 'draft'): ?><a href="<?= h(url('/surveys/result.php?id=' . (int) $r['id'])) ?>">結果</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
