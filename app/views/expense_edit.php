<?php
/** @var int|null $sheetId 直すときの精算書の ID（新しく作るときは null） */
/** @var array<string, mixed> $sheetData */
/** @var array<int, array<string, string>> $editRowList 入力欄に出す明細の行（空の行を含む） */
/** @var array{subtotal: int, advance: int, total: int} $totals */
/** @var string[] $errorTextList */
/** @var bool $isMobile */
$formPath = '/expenses/edit.php' . ($sheetId !== null ? '?id=' . $sheetId : '');
?>
<section class="card">
  <p><a href="<?= h(url('/expenses/index.php')) ?>">← 通勤定期代の精算</a></p>
  <h1><?= $sheetId === null ? '精算書を作る' : '精算書を直す' ?></h1>
  <p class="flash flash-info"><?= h_lines(msg('このシステムで受け付けるのは通勤定期だけです。通勤実費・業務交通費などは、別のシステムで申請してください。')) ?></p>
  <?php if (isset($sheetData['unreadable'])): ?>
    <div class="flash flash-error" role="alert"><?= h(msg('この精算書は読めません（暗号の鍵が変わった可能性があります）。システム管理者に連絡してください。')) ?></div>
  <?php endif; ?>
  <?php foreach ($errorTextList as $errorText): ?><div class="flash flash-error" role="alert"><?= h_lines($errorText) ?></div><?php endforeach; ?>
  <?php if ($sheetId !== null && !$isMobile): ?>
    <p><a class="button" href="<?= h(url('/expenses/download.php?id=' . $sheetId)) ?>">精算書の Excel を受け取る（.xlsx）</a>
      <span class="note"><?= h(msg('総務の様式に、保存した内容を入れたファイルです。')) ?></span></p>
  <?php endif; ?>

  <form method="post" action="<?= h(url($formPath)) ?>" class="form expense-form" data-leave-warn="1">
    <?= form_hidden() ?>
    <?php if ($sheetId !== null): ?><input type="hidden" name="sheet_id" value="<?= (int) $sheetId ?>"><?php endif; ?>

    <fieldset class="expense-head">
      <legend>精算書の上の欄</legend>
      <div class="expense-head-grid">
        <div>
          <span class="label-text">精算方法</span>
          <?php foreach (EXPENSE_PAYMENT_METHODS as $methodCode => $methodLabel): ?>
            <label class="choice"><input type="radio" name="payment_method" value="<?= h($methodCode) ?>" <?= $sheetData['payment_method'] === $methodCode ? 'checked' : '' ?>> <?= h($methodLabel) ?></label>
          <?php endforeach; ?>
        </div>
        <div>
          <label for="submitted_on">提出日</label>
          <input id="submitted_on" name="submitted_on" type="date" value="<?= h($sheetData['submitted_on']) ?>" required>
        </div>
        <div>
          <label for="employee_number">社員番号</label>
          <input id="employee_number" name="employee_number" type="text" inputmode="numeric" maxlength="20" value="<?= h($sheetData['employee_number']) ?>" required>
        </div>
        <div>
          <label for="office">所属</label>
          <select id="office" name="office" required>
            <option value="">選んでください</option>
            <?php foreach (EXPENSE_OFFICES as $officeName): ?>
              <option value="<?= h($officeName) ?>" <?= $sheetData['office'] === $officeName ? 'selected' : '' ?>><?= h($officeName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="department_name">部署</label>
          <input id="department_name" name="department_name" type="text" maxlength="40" value="<?= h($sheetData['department_name']) ?>" required>
        </div>
        <div>
          <label for="full_name">氏名</label>
          <input id="full_name" name="full_name" type="text" maxlength="40" value="<?= h($sheetData['full_name']) ?>" required>
        </div>
      </div>
    </fieldset>

    <fieldset class="expense-rows">
      <legend>明細（<?= (int) EXPENSE_MAX_ROWS ?> 行まで。空の行は保存しません）</legend>
      <p class="note"><?= h_lines(msg('1 行に 1 つの定期券を書きます。月／日には、定期券の使い始めの日と終わりの日を入れます。行が足りないときは、保存すると空の行が増えます。')) ?></p>
      <div class="table-wrap">
      <table class="table expense-input-table">
        <thead><tr><th>#</th><?php foreach (EXPENSE_ROW_COLUMNS as $columnLabel): ?><th><?= h($columnLabel) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php foreach ($editRowList as $rowIndex => $rowData): $namePrefix = 'rows[' . $rowIndex . ']'; ?>
            <tr>
              <td class="row-number"><?= $rowIndex + 1 ?></td>
              <td><input type="date" name="<?= h($namePrefix) ?>[start_date]" value="<?= h($rowData['start_date']) ?>" aria-label="<?= $rowIndex + 1 ?> 行目 月／日（から）"></td>
              <td><input type="date" name="<?= h($namePrefix) ?>[end_date]" value="<?= h($rowData['end_date']) ?>" aria-label="<?= $rowIndex + 1 ?> 行目 月／日（まで）"></td>
              <td class="expense-fixed-kind"><?= h(EXPENSE_ACCEPTED_KIND) ?></td>
              <td><input type="text" name="<?= h($namePrefix) ?>[purpose]" maxlength="60" value="<?= h($rowData['purpose']) ?>" aria-label="<?= $rowIndex + 1 ?> 行目 業務・行先"></td>
              <td><select name="<?= h($namePrefix) ?>[transport]" aria-label="<?= $rowIndex + 1 ?> 行目 交通手段">
                <option value=""></option>
                <?php foreach (EXPENSE_TRANSPORTS as $transportName): ?><option value="<?= h($transportName) ?>" <?= $rowData['transport'] === $transportName ? 'selected' : '' ?>><?= h($transportName) ?></option><?php endforeach; ?>
              </select></td>
              <td><input type="text" name="<?= h($namePrefix) ?>[route]" maxlength="80" value="<?= h($rowData['route']) ?>" placeholder="例: 門真南～(谷町六丁目)～天王寺" aria-label="<?= $rowIndex + 1 ?> 行目 発～(経由)～着"></td>
              <td><select name="<?= h($namePrefix) ?>[trip]" aria-label="<?= $rowIndex + 1 ?> 行目 片道往復">
                <option value=""></option>
                <?php foreach (EXPENSE_TRIPS as $tripName): ?><option value="<?= h($tripName) ?>" <?= $rowData['trip'] === $tripName ? 'selected' : '' ?>><?= h($tripName) ?></option><?php endforeach; ?>
              </select></td>
              <td><input type="text" name="<?= h($namePrefix) ?>[amount]" inputmode="numeric" maxlength="9" class="expense-amount" value="<?= h($rowData['amount']) ?>" aria-label="<?= $rowIndex + 1 ?> 行目 金額"></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </fieldset>

    <fieldset class="expense-totals">
      <legend>金額</legend>
      <p>小計: <strong class="expense-subtotal"><?= h(number_format($totals['subtotal'])) ?></strong> 円</p>
      <label for="advance_amount">仮払い（あれば。合計から引きます）</label>
      <input id="advance_amount" name="advance_amount" type="text" inputmode="numeric" maxlength="9" class="expense-advance" value="<?= h($sheetData['advance_amount']) ?>">
      <p>合計（小計 − 仮払い）: <strong class="expense-total"><?= h(number_format($totals['total'])) ?></strong> 円</p>
    </fieldset>

    <div class="form-actions">
      <button type="submit" name="action" value="save" class="primary">保存</button>
    </div>
  </form>

  <?php if ($sheetId !== null): ?>
    <form method="post" action="<?= h(url($formPath)) ?>" class="expense-delete-form" data-confirm="<?= h(msg('この精算書を削除します。元に戻せません。よろしいですか？')) ?>">
      <?= form_hidden() ?>
      <input type="hidden" name="sheet_id" value="<?= (int) $sheetId ?>">
      <button type="submit" name="action" value="delete" class="danger small">この精算書を削除</button>
    </form>
  <?php endif; ?>
</section>
