<?php
/** @var array $req */
/** @var array $questions */
/** @var array $responses */
/** @var array $answers */
/** @var array<int, array> $surveyTallyByQuestionId 質問 ID => survey_tally() の 1 件 */
/** @var array $notAnswered */
/** @var array $targets */
/** @var bool $showDetail */
$total = count($responses);
?>
<section class="card wide">
  <p><a href="<?= h(url('/surveys/survey_edit.php?id=' . (int) $req['id'])) ?>">← 調査の設定</a></p>
  <h1><?= h($req['title']) ?>（結果）</h1>
  <p>回答 <strong><?= $total ?>/<?= count($targets) ?></strong><?php if (count($targets) > 0): ?>（回答率 <?= (int) round($total * 100 / count($targets)) ?>%）<?php endif; ?>
    <?php if ($req['result_visibility'] === 'summary'): ?><span class="tag">依頼元には集計のみ</span><?php endif; ?></p>
  <a class="button" href="<?= h(url('/export/index.php?type=survey&survey_id=' . (int) $req['id'])) ?>">出力へ</a>

  <h2>集計</h2>
  <p class="note"><?= h_phrases('割合は回答者数に対する % です。複数回答の質問は、足すと 100% を超えます。') ?></p>
  <?php foreach ($questions as $i => $q): if (!isset($surveyTallyByQuestionId[$q['id']])) { continue; } $tally = $surveyTallyByQuestionId[$q['id']]; ?>
    <h3><?= ($i + 1) ?>. <?= h($q['label']) ?><?php if ($tally['is_multiple']): ?><span class="note">（複数回答）</span><?php endif; ?></h3>
    <table class="table survey-tally-table">
      <thead><tr><th>選択肢</th><th class="num">件数</th><th class="num">割合</th><th class="survey-bar-cell">グラフ</th></tr></thead>
      <tbody>
      <?php foreach ($tally['option_tally_list'] as $optionTally): ?>
        <tr><td><?= h($optionTally['label']) ?></td><td class="num"><?= (int) $optionTally['count'] ?></td>
          <td class="num"><?= $optionTally['percent'] === null ? '—' : (int) $optionTally['percent'] . '%' ?></td>
          <td class="survey-bar-cell"><?= survey_bar_svg($optionTally['percent']) ?></td></tr>
      <?php endforeach; ?>
      <tr class="muted"><td>未回答</td><td class="num"><?= (int) $tally['unanswered_count'] ?></td><td></td><td></td></tr>
      </tbody>
    </table>
  <?php endforeach; ?>

  <h2>個人の回答</h2>
  <?php if (!$showDetail): ?>
    <p class="note"><?= h_phrases('個人の回答を開くと、記録に残ります。') ?></p>
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
