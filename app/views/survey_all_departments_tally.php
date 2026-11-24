<?php
// 全課共通の調査の集計（G-11 の結果と G-10 の印刷で共通。K-066）。include で使う部品（render() では使わない）
/** @var array $overallTallyList survey_tally() の結果（全課） */
/** @var array $departmentBreakdownList survey_department_breakdown() の結果 */
/** @var string $headingTag 質問の見出しのタグ（h2 か h3） */
$headingTag = $headingTag ?? 'h3';
?>
<<?= $headingTag ?>>課ごとの回答の状況</<?= $headingTag ?>>
<table class="table print-table survey-tally-table">
  <thead><tr><th>課</th><th class="num">対象</th><th class="num">回答</th><th class="num">回答率</th><th class="survey-bar-cell">グラフ</th></tr></thead>
  <tbody>
  <?php foreach ($departmentBreakdownList as $departmentBreakdown):
      $departmentRatePercent = $departmentBreakdown['target_count'] > 0 ? (int) round($departmentBreakdown['respondent_count'] * 100 / $departmentBreakdown['target_count']) : null; ?>
    <tr><td><?= h($departmentBreakdown['department_name']) ?></td><td class="num"><?= (int) $departmentBreakdown['target_count'] ?></td>
      <td class="num"><?= (int) $departmentBreakdown['respondent_count'] ?></td><td class="num"><?= $departmentRatePercent === null ? '—' : $departmentRatePercent . '%' ?></td>
      <td class="survey-bar-cell"><?= survey_bar_svg($departmentRatePercent) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="note"><?= h_phrases('課は今の所属で数えます。回答者が ' . SURVEY_DEPARTMENT_BREAKDOWN_MIN_RESPONDENTS . ' 人より少ない課は、選択肢ごとの内訳を出しません（個人の回答が分からないように）。') ?></p>

<?php foreach ($overallTallyList as $questionNumber => $tally): $questionId = (int) $tally['question']['id']; ?>
<div class="survey-tally">
  <<?= $headingTag ?>><?= ($questionNumber + 1) ?>. <?= h($tally['question']['label']) ?><?php if ($tally['is_multiple']): ?><span class="note">（複数回答）</span><?php endif; ?></<?= $headingTag ?>>
  <table class="table print-table survey-tally-table">
    <thead><tr><th>選択肢（全課）</th><th class="num">件数</th><th class="num">割合</th><th class="survey-bar-cell">グラフ</th></tr></thead>
    <tbody>
    <?php foreach ($tally['option_tally_list'] as $optionTally): ?>
      <tr><td><?= h($optionTally['label']) ?></td><td class="num"><?= (int) $optionTally['count'] ?></td>
        <td class="num"><?= $optionTally['percent'] === null ? '—' : (int) $optionTally['percent'] . '%' ?></td>
        <td class="survey-bar-cell"><?= survey_bar_svg($optionTally['percent']) ?></td></tr>
    <?php endforeach; ?>
      <tr class="muted"><td>未回答</td><td class="num"><?= (int) $tally['unanswered_count'] ?></td><td></td><td></td></tr>
    </tbody>
  </table>
  <div class="table-input">
  <table class="table print-table survey-cross-table">
    <thead><tr><th>課ごと</th><?php foreach ($tally['option_tally_list'] as $optionTally): ?><th class="num"><?= h($optionTally['label']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($departmentBreakdownList as $departmentBreakdown): $departmentTally = $departmentBreakdown['tally_by_question_id'][$questionId] ?? null; ?>
      <tr><td><?= h($departmentBreakdown['department_name']) ?><span class="note">（回答 <?= (int) $departmentBreakdown['respondent_count'] ?> 人）</span></td>
      <?php if ($departmentTally === null): ?>
        <td colspan="<?= count($tally['option_tally_list']) ?>" class="note">内訳は出しません（回答者が <?= SURVEY_DEPARTMENT_BREAKDOWN_MIN_RESPONDENTS ?> 人より少ない）</td>
      <?php else: foreach ($departmentTally['option_tally_list'] as $departmentOptionTally): ?>
        <td class="num"><?= (int) $departmentOptionTally['count'] ?><span class="note">（<?= $departmentOptionTally['percent'] === null ? '—' : (int) $departmentOptionTally['percent'] . '%' ?>）</span></td>
      <?php endforeach; endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
