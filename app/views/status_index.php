<?php
/** @var array $depts */
/** @var int $deptId */
/** @var string $period */
/** @var string $filter */
/** @var array $list */
/** @var array $stats */
/** @var int|null $firstUnchecked */
/** @var int $bulkCandidateCount まとめてチェックできる報告の数 */
/** @var bool $isPersonRowsShown 人ごとの行を出すか（補佐は確認者でない課では出さない） */
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
  <div class="status-progress">
    <p>提出 <strong><?= (int) $stats['submitted'] ?>/<?= (int) $stats['members'] ?></strong> ／ 確認完了 <strong><?= (int) $stats['confirmed'] ?></strong><?php if ($isPersonRowsShown): ?> ／ 自分が未チェック <strong><?= (int) $stats['unchecked'] ?></strong><?php endif; ?></p>
    <label class="progress-label"><span class="progress-name">提出</span><progress class="progress-submitted" max="<?= max(1, (int) $stats['members']) ?>" value="<?= (int) $stats['submitted'] ?>"><?= (int) $stats['submitted'] ?>/<?= (int) $stats['members'] ?></progress></label>
    <label class="progress-label"><span class="progress-name">確認完了</span><progress class="progress-confirmed" max="<?= max(1, (int) $stats['members']) ?>" value="<?= (int) $stats['confirmed'] ?>"><?= (int) $stats['confirmed'] ?>/<?= (int) $stats['members'] ?></progress></label>
  </div>
  <?php if ($firstUnchecked !== null): ?>
    <a class="button primary" href="<?= h(url('/reports/view.php?id=' . $firstUnchecked . '&seq=1')) ?>">未チェックを順に見る</a>
  <?php endif; ?>
  <?php if ($isPersonRowsShown): ?>
  <a class="button" href="<?= h(url('/status/compare.php?period=' . rawurlencode($period) . '&dept=' . $deptId)) ?>">横並び・まとめ</a>
  <?php endif; ?>

  <?php if (!$isPersonRowsShown): ?>
    <p class="note">システム管理者補佐の画面では、課ごとの件数だけを出します（人ごとの状態は出しません）。</p>
  <?php elseif (!$list): ?>
    <p class="note">該当する人はいません。</p>
  <?php else: ?>
  <?php if ($bulkCandidateCount > 0): ?>
    <form method="post" action="<?= h(url('/status/index.php?period=' . rawurlencode($period) . '&dept=' . $deptId)) ?>" id="bulk-check-form" class="bulk-check-box"
          data-confirm="<?= h(msg('選んだ「変更なし」の報告をチェック済みにします。よろしいですか？')) ?>">
      <?= form_hidden() ?>
      <p>「前月から変更なし」で提出され、そのあと直していない報告が <strong><?= (int) $bulkCandidateCount ?></strong> 件あります。中身を開かずにまとめてチェックできます。</p>
      <button type="submit" name="action" value="bulk_check" class="primary">選んだ報告をまとめてチェック</button>
    </form>
  <?php endif; ?>
  <ul class="list">
    <?php foreach ($list as $r): $submitted = in_array($r['status'], ['submitted', 'confirmed'], true); ?>
      <li>
        <span><?php if ($r['can_bulk_check']): ?><input type="checkbox" name="report_ids[]" value="<?= (int) $r['report_id'] ?>" form="bulk-check-form" checked aria-label="<?= h($r['display_name']) ?>さんの報告をまとめてチェックする"> <?php endif; ?><?php if ($r['can_view']): ?><a href="<?= h(url('/reports/view.php?id=' . (int) $r['report_id'])) ?>"><?= h($r['display_name']) ?></a><?php else: ?><?= h($r['display_name']) ?><?php endif; ?></span>
        <span>
          <?= report_status_tag_html($r['status']) ?>
          <?php if ($submitted): ?><span class="note">✓<?= (int) $r['checked'] ?>/<?= (int) $r['reviewers'] ?></span><?php endif; ?>
          <?php if ($r['my_state'] !== '' && $r['my_state'] !== 'checked'): ?><span class="tag tag-open"><?= h($myLabels[$r['my_state']]) ?></span><?php endif; ?>
          <?php if ((int) $r['open_count'] > 0): ?><span class="tag tag-open">回答待ち <?= (int) $r['open_count'] ?></span><?php endif; ?>
          <?php if ($submitted && (int) $r['warnings'] > 0): ?><span class="tag tag-warning">注意</span><?php endif; ?>
          <?php if ((int) $r['unchanged'] === 1): ?><span class="tag">変更なし</span><?php endif; ?>
        </span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
