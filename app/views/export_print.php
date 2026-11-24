<?php
/** @var array $headerLines 社外秘・題名・出力者（調査は 4 行目に回答の状況） */
/** @var array $header */
/** @var array $rows */
/** @var array $summaryRows */
/** @var array|null $surveyTallyList 調査のとき survey_tally() の結果（質問ごとの表とグラフで出す）。月次報告は null */
/** @var array|null $departmentBreakdownList 全課共通の調査のとき survey_department_breakdown() の結果（課ごとのまとめも出す。K-066） */
$surveyTallyList = $surveyTallyList ?? null;
$departmentBreakdownList = $departmentBreakdownList ?? null;
?>
<section class="print-page">
  <div class="print-head">
    <p class="warn"><?= h($headerLines[0]) ?></p>
    <h1><?= h($headerLines[1]) ?></h1>
    <p class="note"><?= h($headerLines[2]) ?></p>
    <?php for ($lineIndex = 3; $lineIndex < count($headerLines); $lineIndex++): ?>
    <p><?= h($headerLines[$lineIndex]) ?></p>
    <?php endfor; ?>
  </div>
  <p class="no-print"><button type="button" class="primary print-button">印刷する（PDF として保存）</button>
    <span class="note"><?= h_phrases('印刷の画面で、送信先に「PDF に保存」（Firefox では「ファイルへ出力」）を選びます。') ?></span></p>
  <?php if ($surveyTallyList !== null && $departmentBreakdownList !== null): ?>
    <?php $overallTallyList = $surveyTallyList; $headingTag = 'h2'; include __DIR__ . '/survey_all_departments_tally.php'; ?>
  <?php elseif ($surveyTallyList !== null): ?>
    <?php foreach ($surveyTallyList as $questionNumber => $tally): ?>
    <div class="survey-tally">
      <h2><?= ($questionNumber + 1) ?>. <?= h($tally['question']['label']) ?><?php if ($tally['is_multiple']): ?><span class="note">（複数回答）</span><?php endif; ?></h2>
      <table class="table print-table survey-tally-table">
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
    </div>
    <?php endforeach; ?>
  <?php elseif ($rows): ?>
  <table class="table print-table">
    <thead><tr><?php foreach ($header as $hd): ?><th><?= h($hd) ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr><?php foreach ($r as $c): ?><td><?= nl2br(h((string) $c)) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  <?php if ($summaryRows): ?>
  <table class="table print-table">
    <tbody><?php foreach ($summaryRows as $r): ?><tr><?php foreach ($r as $c): ?><td><?= nl2br(h((string) $c)) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
</section>
