<?php
/** @var array $depts */
/** @var int $deptId */
/** @var string $period */
/** @var string $view */
/** @var bool $diff */
/** @var array $data */
/** @var array $prevIndex */
/** @var array $freeColumns */
/** @var array $hasFree */
/** @var array $summary */
?>
<section class="card wide">
  <p><a href="<?= h(url('/status/index.php?period=' . rawurlencode($period) . '&dept=' . $deptId)) ?>">← 提出状況</a></p>
  <h1>横並び・まとめ</h1>
  <form method="get" action="<?= h(url('/status/compare.php')) ?>" class="filters">
    <input type="month" name="period" value="<?= h($period) ?>" aria-label="対象月">
    <select name="dept" aria-label="課"><?php foreach ($depts as $id => $d): ?><option value="<?= (int) $id ?>" <?= $id === $deptId ? 'selected' : '' ?>><?= h($d['name']) ?></option><?php endforeach; ?></select>
    <select name="view" aria-label="表示"><option value="table" <?= $view === 'table' ? 'selected' : '' ?>>横並び</option><option value="summary" <?= $view === 'summary' ? 'selected' : '' ?>>まとめ</option></select>
    <label class="check"><input type="checkbox" name="diff" value="1" <?= $diff ? 'checked' : '' ?>> 前月との差分</label>
    <button type="submit">表示</button>
    <?php if (!is_mobile_request()): ?><a class="button" href="<?= h(url('/export/index.php?period=' . rawurlencode($period) . '&dept=' . $deptId)) ?>">出力へ</a><?php endif; ?>
  </form>

  <?php if (!$data['rows']): ?>
    <p class="note">提出済みの報告がありません。</p>
  <?php elseif ($view === 'table'): ?>
    <div class="table-input">
    <table class="table compare">
      <thead><tr><th><span class="compare-head-badges"></span><span class="compare-head-label">氏名</span></th><th><span class="compare-head-badges"></span><span class="compare-head-label">案件</span></th>
        <?php foreach (array_merge($data['columns'], $freeColumns) as $f): ?><th><span class="compare-head-badges"><?= form_badges(array_merge($f, ['required' => false])) ?></span><span class="compare-head-label"><?= h($f['label']) ?></span></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($data['rows'] as $r): $prev = $prevIndex[$r['name'] . '|' . $r['project']] ?? null; ?>
        <tr>
          <td><a href="<?= h(url('/reports/view.php?id=' . (int) $r['report_id'])) ?>"><?= h($r['name']) ?></a></td>
          <td><?= h($r['project']) ?><?= $diff && $prev === null ? ' <span class="tag tag-changed">新規</span>' : '' ?></td>
          <?php foreach ($data['columns'] as $f):
              $text = $r['cells'][$f['code']]['text'] ?? '';
              $changed = $diff && $prev !== null && ($prev['cells'][$f['code']]['value'] ?? '') !== ($r['cells'][$f['code']]['value'] ?? ''); ?>
            <td class="<?= $changed ? 'changed' : '' ?>"><?= nl2br(h($text)) ?><?= $changed ? ' *' : '' ?></td>
          <?php endforeach; ?>
          <?php foreach ($freeColumns as $f):
              $rp = $f['scope'] === 'report' ? 0 : (int) $r['rp_id'];
              $key = (int) $r['report_id'] . ':' . $rp . ':' . (int) $f['id'];
              $show = ($f['scope'] === 'report' && $r['first']) || $f['scope'] === 'project'; ?>
            <td><?php if ($show && isset($hasFree[$key])): ?><button type="button" class="small open-cell" data-cell="<?= h($key) ?>">開く</button><span class="cell-text"></span><?php endif; ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="note">* は前月から変わった値。自由記述は「開く」を押したときだけ表示します（開いたことは記録されます）。</p>
  <?php else: ?>
    <?php foreach ($summary['groups'] as $g): ?>
      <h2><?= h($g['label']) ?>（<?= count($g['items']) ?>件）</h2>
      <?php if (!$g['items']): ?><p class="note">なし</p><?php else: ?>
      <ul class="list">
        <?php foreach ($g['items'] as $r): ?>
          <li><span><?= h($r['project']) ?> <a href="<?= h(url('/reports/view.php?id=' . (int) $r['report_id'])) ?>"><?= h($r['name']) ?></a></span>
            <span class="note"><?php foreach ($data['columns'] as $f): $t = $r['cells'][$f['code']]['text'] ?? ''; if ($t === '' || !in_array($f['type'], ['select', 'radio', 'month', 'date'], true)) { continue; } ?><?= h($f['label']) ?>: <?= h($t) ?>　<?php endforeach; ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    <?php endforeach; ?>
    <h2>集計</h2>
    <?php foreach ($summary['counts'] as $label => $c): ?>
      <p><strong><?= h($label) ?></strong>: <?php foreach ($c as $k => $n): ?><?= h($k) ?> <?= (int) $n ?>　<?php endforeach; ?></p>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
