<?php
/** @var array $commonField 共通の項目（report_fields の行） */
/** @var array $department 課（departments の行） */
/** @var array<int, array{code: string, label: string, active: bool}> $commonOptionList 共通の有効な選択肢 */
/** @var array<string, array> $departmentOptionRowByCode 課の設定（コード => department_field_options の行） */
/** @var array<int, array> $addedOptionRowList 課で足した選択肢 */
/** @var array<string, bool> $isAddedOptionUsedByCode 足した選択肢が報告で使われているか */
/** @var array<int, array{code: string, label: string}> $previewOptionList この課の報告に出る選択肢（今の保存内容） */
/** @var string[] $errorTextList */
/** @var array|null $postedValues 入力エラーで戻ったときの送信値 */
/** @var string $selfPath */
$postedOverrideLabelByCode = $postedValues['override_label'] ?? [];
$postedShownFlagByCode = $postedValues['shown'] ?? null;
?>
<section class="card wide">
  <p><a href="<?= h(url('/admin/fields.php?dept=' . (int) $department['id'])) ?>">← 項目マスタ（<?= h($department['name']) ?>の様式）</a></p>
  <h1><?= h($commonField['label']) ?> の選択肢（<?= h($department['name']) ?>）</h1>
  <p class="note">共通の項目の選択肢を、<?= h($department['name']) ?>でだけ変えます（ほかの課は変わりません）。表示名を変える・出さない・選択肢を足す、ができます。変更は、これから作る報告に反映されます（作った報告は作ったときの選択肢のまま）。</p>
  <p class="note">この課の報告に今出る選択肢: <?php foreach ($previewOptionList as $previewOption): ?><span class="tag"><?= h($previewOption['label']) ?></span><?php endforeach; ?></p>
  <?php foreach ($errorTextList as $errorText): ?><div class="flash flash-error" role="alert"><?= h_lines($errorText) ?></div><?php endforeach; ?>

  <form method="post" action="<?= h(url($selfPath)) ?>" class="form" data-leave-warn="1">
    <?= form_hidden() ?>
    <h2>共通の選択肢</h2>
    <table class="table">
      <thead><tr><th>共通の表示名</th><th>この課での表示名（空なら共通のまま）</th><th>この課で出す</th></tr></thead>
      <tbody>
      <?php foreach ($commonOptionList as $commonOption):
          $departmentOptionRow = $departmentOptionRowByCode[$commonOption['code']] ?? null;
          $overrideLabel = $postedValues !== null ? (string) ($postedOverrideLabelByCode[$commonOption['code']] ?? '') : (string) ($departmentOptionRow['label'] ?? '');
          $isShown = $postedShownFlagByCode !== null ? (($postedShownFlagByCode[$commonOption['code']] ?? '') === '1') : !($departmentOptionRow !== null && (int) $departmentOptionRow['is_hidden'] === 1); ?>
        <tr>
          <td><?= h($commonOption['label']) ?></td>
          <td><input type="text" name="override_label[<?= h($commonOption['code']) ?>]" value="<?= h($overrideLabel) ?>" maxlength="60" placeholder="<?= h($commonOption['label']) ?>"></td>
          <td><input type="checkbox" name="shown[<?= h($commonOption['code']) ?>]" value="1" <?= $isShown ? 'checked' : '' ?> aria-label="<?= h($commonOption['label']) ?>をこの課で出す"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <h2>この課で足した選択肢</h2>
    <?php if (!$addedOptionRowList): ?><p class="note">まだありません。</p><?php endif; ?>
    <?php if ($addedOptionRowList): ?>
    <table class="table">
      <thead><tr><th>表示名</th><th>この課で出す</th><th>使用</th></tr></thead>
      <tbody>
      <?php foreach ($addedOptionRowList as $addedOptionRow): $addedCode = (string) $addedOptionRow['code']; ?>
        <tr>
          <td><input type="text" name="added_label[<?= h($addedCode) ?>]" value="<?= h((string) ($postedValues['added_label'][$addedCode] ?? $addedOptionRow['label'])) ?>" maxlength="60" required></td>
          <td><input type="checkbox" name="shown[<?= h($addedCode) ?>]" value="1" <?= ($postedShownFlagByCode !== null ? (($postedShownFlagByCode[$addedCode] ?? '') === '1') : (int) $addedOptionRow['is_hidden'] === 0) ? 'checked' : '' ?>></td>
          <td><?= $isAddedOptionUsedByCode[$addedCode] ? '使用あり（削除できません）' : '未使用' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <h2>選択肢を足す</h2>
    <div class="new-option-list">
      <?php for ($newOptionIndex = 0; $newOptionIndex < 3; $newOptionIndex++): ?>
        <input type="text" name="new_label[]" maxlength="60" placeholder="例: 調査・企画" aria-label="足す選択肢の表示名 <?= $newOptionIndex + 1 ?>">
      <?php endfor; ?>
    </div>
    <p class="note">表示名を入れるだけで追加できます（コードは自動で付きます）。さらに追加する場合は、一度保存すると空の入力欄が出ます。</p>
    <button type="submit" name="action" value="save" class="primary">保存</button>
  </form>

  <?php foreach ($addedOptionRowList as $addedOptionRow): if ($isAddedOptionUsedByCode[(string) $addedOptionRow['code']]) { continue; } ?>
    <form method="post" action="<?= h(url($selfPath)) ?>" class="inline" data-confirm="<?= h(msg('この選択肢を削除します。よろしいですか？')) ?>">
      <?= form_hidden() ?>
      <input type="hidden" name="code" value="<?= h((string) $addedOptionRow['code']) ?>">
      <button type="submit" name="action" value="delete_added" class="small danger">「<?= h((string) $addedOptionRow['label']) ?>」を削除</button>
    </form>
  <?php endforeach; ?>
</section>
