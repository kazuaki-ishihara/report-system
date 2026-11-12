<?php
/** @var array $depts */
/** @var int $deptId */
/** @var string $period */
/** @var string $filter */
/** @var array $list */
/** @var array $stats */
/** @var int|null $firstUnchecked */
$filters = ['all' => 'すべて', 'unchecked' => '自分が未チェック', 'not_submitted' => '未提出', 'open_comments' => '回答待ちあり', 'warning' => '注意の印あり'];
$myLabels = ['unchecked' => '未チェック', 'updated' => '更新あり', 'checked' => '✓'];
?>
<section class="card">
  <p><a href="<?= h(url('/index.php')) ?>">← ホーム</a></p>
  <h1>提出状況</h1>
  <form method="get" action="<?= h(url('/status/index.php')) ?>" class="filters">
    <input type="month" name="period" value="<?= h($period) ?>" aria-label="対象月">
    <select name="dept" aria-label="課">
      <?php foreach ($depts as $id => $d): ?><option value="<?= (int) $id ?>" <?= $id === $deptId ? 'selected' : '' ?>><?= h($d['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="filter" aria-label="絞り込み">
      <?php foreach ($filters as $k => $l): ?><option value="<?= h($k) ?>" <?= $filter === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
    </select>
    <button type="submit">表示</button>
  </form>
  <p>提出 <strong><?= (int) $stats['submitted'] ?>/<?= (int) $stats['members'] ?></strong> ／ 確認完了 <strong><?= (int) $stats['confirmed'] ?></strong> ／ 自分が未チェック <strong><?= (int) $stats['unchecked'] ?></strong></p>
  <?php if ($firstUnchecked !== null): ?>
    <a class="button primary" href="<?= h(url('/reports/view.php?id=' . $firstUnchecked . '&seq=1')) ?>">未チェックを順に見る</a>
  <?php endif; ?>
  <a class="button" href="<?= h(url('/status/compare.php?period=' . rawurlencode($period) . '&dept=' . $deptId)) ?>">横並び・まとめ</a>

  <?php if (!$list): ?>
    <p class="note">該当する人はいません。</p>
  <?php else: ?>
  <ul class="list">
    <?php foreach ($list as $r): $submitted = in_array($r['status'], ['submitted', 'confirmed'], true); ?>
      <li>
        <span><?php if ($submitted): ?><a href="<?= h(url('/reports/view.php?id=' . (int) $r['report_id'])) ?>"><?= h($r['display_name']) ?></a><?php else: ?><?= h($r['display_name']) ?><?php endif; ?></span>
        <span>
          <span class="tag"><?= h(report_status_label($r['status'])) ?></span>
          <?php if ($submitted): ?><span class="note">✓<?= (int) $r['checked'] ?>/<?= (int) $r['reviewers'] ?></span><?php endif; ?>
          <?php if ($r['my_state'] !== '' && $r['my_state'] !== 'checked'): ?><span class="tag tag-open"><?= h($myLabels[$r['my_state']]) ?></span><?php endif; ?>
          <?php if ((int) $r['open_count'] > 0): ?><span class="tag">回答待ち <?= (int) $r['open_count'] ?></span><?php endif; ?>
          <?php if ($submitted && (int) $r['warnings'] > 0): ?><span class="tag tag-warning">注意</span><?php endif; ?>
          <?php if ((int) $r['unchanged'] === 1): ?><span class="tag">変更なし</span><?php endif; ?>
        </span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
