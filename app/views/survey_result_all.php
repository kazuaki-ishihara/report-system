<?php
// G-11 全課共通の臨時の調査の結果（K-066）: 全課の集計と課ごとのまとめ。個人の回答・未回答者の名前は出さない
/** @var array $req */
/** @var array $questions */
/** @var array $overallTallyList */
/** @var array $departmentBreakdownList */
/** @var string $responseRateText */
/** @var bool $isMobile */
?>
<section class="card wide">
  <p><a href="<?= h(url('/surveys/survey_edit.php?id=' . (int) $req['id'])) ?>">← 調査の設定</a></p>
  <h1><?= h($req['title']) ?>（結果） <span class="tag">全課共通</span></h1>
  <p><strong><?= h($responseRateText) ?></strong></p>
  <p class="note"><?= h_phrases('割合は回答者数に対する % です。複数回答の質問は、足すと 100% を超えます。全課共通の調査は集計だけで、個人の回答と未回答の人の名前は出しません。') ?></p>

  <?php if (!$isMobile): ?>
  <h2>出力</h2>
  <p class="note"><?= h_phrases('全課の集計と課ごとのまとめを出力します。出力したファイルは社外秘です。出力は記録に残ります。') ?></p>
  <div class="actions">
    <?php foreach (['print' => '印刷用（PDF）', 'xlsx' => 'Excel（xlsx）', 'csv' => 'CSV'] as $formatCode => $formatLabel): ?>
      <form method="post" action="<?= h(url('/surveys/result.php?id=' . (int) $req['id'])) ?>" class="inline"<?= $formatCode === 'print' ? ' target="_blank"' : '' ?>>
        <?= form_hidden() ?><input type="hidden" name="format" value="<?= h($formatCode) ?>">
        <button type="submit"><?= h($formatLabel) ?></button></form>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <p class="note"><?= h_phrases('出力は PC から行ってください。') ?></p>
  <?php endif; ?>

  <h2>集計</h2>
  <?php if (!$overallTallyList): ?><p class="note"><?= h_phrases('集計する質問がありません。') ?></p><?php endif; ?>
  <?php $headingTag = 'h3'; include __DIR__ . '/survey_all_departments_tally.php'; ?>
</section>
