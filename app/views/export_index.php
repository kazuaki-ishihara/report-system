<?php
/** @var array $depts */
/** @var bool $mobile */
/** @var array $surveys */
/** @var string $period */
/** @var string $deptSel */
/** @var string $type */
/** @var string $surveySel */
?>
<section class="card">
  <h1>出力</h1>
  <?php if ($mobile): ?>
    <div class="flash flash-error">出力は PC から行ってください（スマートフォンにファイルを残さないため）。</div>
  <?php else: ?>
  <p class="warn">この出力は記録されます。出力したファイルは社外秘として扱ってください。</p>
  <form method="post" action="<?= h(url('/export/index.php')) ?>" class="form" target="_blank">
    <?= form_hidden(false) ?>
    <label>対象</label>
    <div class="choices">
      <label class="choice"><input type="radio" name="type" value="report" <?= $type === 'report' ? 'checked' : '' ?>> 月次報告</label>
      <label class="choice"><input type="radio" name="type" value="survey" <?= $type === 'survey' ? 'checked' : '' ?>> 臨時の調査</label>
    </div>
    <fieldset class="sub">
      <legend>月次報告</legend>
      <label for="dept">課</label>
      <select id="dept" name="dept"><?php foreach ($depts as $id => $d): ?><option value="<?= (int) $id ?>" <?= $deptSel === (string) $id ? 'selected' : '' ?>><?= h($d['name']) ?></option><?php endforeach; ?></select>
      <label for="from">対象月（開始〜終了。最大 12 か月）</label>
      <div class="filters"><input id="from" type="month" name="from" value="<?= h($period) ?>"> 〜 <input type="month" name="to" value="<?= h($period) ?>" aria-label="終了"></div>
      <label>内容</label>
      <div class="choices">
        <label class="choice"><input type="radio" name="content" value="table" checked> 横並び</label>
        <label class="choice"><input type="radio" name="content" value="summary"> まとめ</label>
        <label class="choice"><input type="radio" name="content" value="both"> 両方</label>
      </div>
    </fieldset>
    <fieldset class="sub">
      <legend>臨時の調査</legend>
      <select name="survey_id" aria-label="臨時の調査"><?php foreach ($surveys as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $surveySel === (string) $s['id'] ? 'selected' : '' ?>><?= h($s['title']) ?></option><?php endforeach; ?></select>
      <p class="note">「集計のみ」の調査は、集計だけを出力します。</p>
    </fieldset>
    <label>形式</label>
    <div class="choices">
      <label class="choice"><input type="radio" name="format" value="print" checked> 印刷用画面（ブラウザーの「PDF として保存」で保存）</label>
      <label class="choice"><input type="radio" name="format" value="xlsx"> Excel 形式（.xlsx。LibreOffice でも開けます）</label>
      <label class="choice"><input type="radio" name="format" value="csv"> CSV</label>
    </div>
    <label class="check"><input type="checkbox" name="free" value="1"> 自由記述を含める</label>
    <?php if (is_admin($user)): ?>
      <label class="check"><input type="checkbox" name="restricted" value="1"> 「限定」の項目を含める（管理者のみ）</label>
    <?php endif; ?>
    <button type="submit" class="primary">出力する</button>
  </form>
  <?php endif; ?>
</section>
