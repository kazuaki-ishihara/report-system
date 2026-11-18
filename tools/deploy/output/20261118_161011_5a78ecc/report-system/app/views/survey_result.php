<?php
/** @var array $req */
/** @var array $questions */
/** @var array $responses */
/** @var array $answers */
/** @var array $summary */
/** @var array $notAnswered */
/** @var array $targets */
/** @var bool $showDetail */
$total = count($responses);
?>
<section class="card wide">
  <p><a href="<?= h(url('/surveys/survey_edit.php?id=' . (int) $req['id'])) ?>">← 調査の設定</a></p>
  <h1><?= h($req['title']) ?>（結果）</h1>
  <p>回答 <strong><?= $total ?>/<?= count($targets) ?></strong>
    <?php if ($req['result_visibility'] === 'summary'): ?><span class="tag">依頼元には集計のみ</span><?php endif; ?></p>
  <a class="button" href="<?= h(url('/export/index.php?type=survey&survey_id=' . (int) $req['id'])) ?>">出力へ</a>

  <h2>集計</h2>
  <?php foreach ($questions as $i => $q): if (!isset($summary[$q['id']])) { continue; } ?>
    <h3><?= ($i + 1) ?>. <?= h($q['label']) ?></h3>
    <table class="table">
      <thead><tr><th>選択肢</th><th>件数</th><th>割合</th></tr></thead>
      <tbody>
      <?php $opts = $q['type'] === 'checkbox' ? [['code' => '1', 'label' => 'はい']] : $q['options'];
      foreach ($opts as $o): $n = (int) ($summary[$q['id']][(string) $o['code']] ?? 0); ?>
        <tr><td><?= h($o['label']) ?></td><td><?= $n ?></td><td><?= $total > 0 ? round($n * 100 / $total) . '%' : '—' ?></td></tr>
      <?php endforeach; ?>
      <tr class="muted"><td>未回答</td><td><?= (int) ($summary[$q['id']]['__none'] ?? 0) ?></td><td></td></tr>
      </tbody>
    </table>
  <?php endforeach; ?>

  <h2>個人の回答</h2>
  <?php if (!$showDetail): ?>
    <p class="note">個人の回答を開くと、記録に残ります。</p>
    <a class="button" href="<?= h(url('/surveys/result.php?id=' . (int) $req['id'] . '&detail=1')) ?>">個人の回答を表示</a>
  <?php else: ?>
    <div class="table-input">
    <table class="table">
      <thead><tr><th>回答者</th><?php foreach ($questions as $i => $q): ?><th><?= ($i + 1) ?>. <?= h($q['label']) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($responses as $r): ?>
        <tr><td><?= h($r['display_name']) ?></td>
          <?php foreach ($questions as $q): ?><td><?= form_display_html($q, $answers[(int) $r['id']][$q['id']] ?? '') ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <h2>未回答（<?= count($notAnswered) ?>人）</h2>
  <p><?= h(implode('、', array_map(function ($t) { return $t['display_name']; }, $notAnswered))) ?: '—' ?></p>
</section>
