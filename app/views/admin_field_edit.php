<?php
/** @var array|null $field */
/** @var array|null $post */
/** @var array $options */
/** @var array $settings */
/** @var array<int, array> $showWhenCandidateFieldList 表示の条件に選べる項目（定義。options を含む） */
$val = function (string $k, $default = '') use ($field, $post) {
    if ($post !== null) {
        return isset($post[$k]) && is_string($post[$k]) ? $post[$k] : $default;
    }
    return $field !== null && isset($field[$k]) ? (string) $field[$k] : $default;
};
$flag = function (string $k, bool $default) use ($field, $post): bool {
    if ($post !== null) {
        return ($post[$k] ?? '') === '1';
    }
    return $field !== null ? (int) $field[$k] === 1 : $default;
};
$set = function (string $k) use ($settings, $post): string {
    if ($post !== null) {
        return isset($post[$k]) && is_string($post[$k]) ? $post[$k] : '';
    }
    return isset($settings[$k]) ? (string) $settings[$k] : '';
};
$type = $val('field_type', 'single_text');
$optRows = $options;
if ($post !== null && isset($post['opt_code']) && is_array($post['opt_code'])) {
    $optRows = [];
    foreach ($post['opt_code'] as $i => $c) {
        $optRows[] = ['code' => (string) $c, 'label' => (string) ($post['opt_label'][$i] ?? ''), 'active' => ($post['opt_active'][$i] ?? '') === '1', 'existing' => false];
    }
}
$existingCodes = array_map(function ($o) { return $o['code']; }, $options);
for ($i = 0; $i < 3; $i++) {
    $optRows[] = ['code' => '', 'label' => '', 'active' => true];
}
$sw = $settings['show_when'] ?? null;
?>
<section class="card">
  <h1><?= $field === null ? '項目の追加' : '項目の編集' ?>（<?= h($deptName) ?>）</h1>
  <p><a href="<?= h(url('/admin/fields.php?dept=' . rawurlencode($deptParam))) ?>">← 項目マスタ</a></p>
  <?php if ($used): ?>
    <p class="note">この項目には入力済みのデータがあります。入力部品は互換のある種類（1 行→複数行、プルダウン⇔ラジオ）にだけ変えられます。区分は変えられません。</p>
  <?php endif; ?>
  <?php foreach ($errors as $e): ?>
    <div class="flash flash-error" role="alert"><?= h_lines($e) ?></div>
  <?php endforeach; ?>

  <form method="post" action="<?= h(url('/admin/field_edit.php?' . ($field === null ? 'dept=' . rawurlencode($deptParam) : 'id=' . (int) $field['id']))) ?>" class="form" data-leave-warn="1">
    <?= form_hidden() ?>
    <label for="label">表示名</label>
    <input id="label" name="label" type="text" maxlength="60" value="<?= h($val('label')) ?>" required>

    <label for="code">コード</label>
    <?php if ($field === null): ?>
      <input id="code" name="code" type="text" maxlength="40" pattern="[a-z][a-z0-9_]{2,39}" value="<?= h($val('code')) ?>" placeholder="空欄なら自動（item_番号）">
      <p class="note">空欄なら自動で付けます。決めたいときは、英小文字で始まる英小文字・数字・_ で入れます。作成後は変更できません。</p>
    <?php else: ?>
      <p><strong><?= h($field['code']) ?></strong>（変更できません）</p>
    <?php endif; ?>

    <label>区分</label>
    <div class="choices">
      <label class="choice"><input type="radio" name="field_scope" value="report" <?= $val('field_scope', 'project') === 'report' ? 'checked' : '' ?>> 報告全体</label>
      <label class="choice"><input type="radio" name="field_scope" value="project" <?= $val('field_scope', 'project') === 'project' ? 'checked' : '' ?>> 案件ブロック（案件ごとに繰り返す）</label>
    </div>

    <label for="field_type">入力部品</label>
    <select id="field_type" name="field_type">
      <?php foreach (FIELD_TYPES as $k => $lbl): ?><option value="<?= h($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
    </select>

    <fieldset class="sub" data-field-types="select radio multi_check">
      <legend>選択肢（プルダウン・ラジオ・チェックボックス）</legend>
      <table class="table">
        <thead><tr><th>表示名</th><th>コード（任意）</th><th>有効</th><th>削除</th></tr></thead>
        <tbody>
        <?php foreach ($optRows as $i => $o): $isExisting = $o['code'] !== '' && in_array($o['code'], $existingCodes, true); ?>
          <tr>
            <td><input type="text" name="opt_label[<?= $i ?>]" value="<?= h($o['label']) ?>" maxlength="60"></td>
            <td><?php if ($isExisting): ?><input type="hidden" name="opt_code[<?= $i ?>]" value="<?= h($o['code']) ?>"><span class="note"><?= h($o['code']) ?></span>
                <?php else: ?><input type="text" name="opt_code[<?= $i ?>]" value="<?= h($o['code']) ?>" maxlength="40" pattern="[a-z0-9_]{1,40}" placeholder="自動"><?php endif; ?></td>
            <td><input type="checkbox" name="opt_active[<?= $i ?>]" value="1" <?= $o['active'] ? 'checked' : '' ?>></td>
            <td><?php if ($isExisting): ?><input type="checkbox" name="opt_delete[<?= $i ?>]" value="1" aria-label="<?= h($o['label']) ?>を削除" data-confirm-on="<?= h(msg('使われていない選択肢を削除します（報告で使われていれば削除せず、知らせます）')) ?>"><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <button type="button" class="small add-option-row" hidden>＋ 選択肢の行を足す</button>
      <p class="note">表示名だけ入れれば、コードは自動で付けます（o1, o2, …）。報告で使われていない選択肢は「削除」で消せます。使われている選択肢は、「有効」を外して新しい報告に出ないようにします。コードは保存される値で、あとから変えられません。表示名は変えられます。</p>
    </fieldset>

    <fieldset class="sub">
      <legend>入力の制限</legend>
      <p class="note no-setting-note" hidden>この入力部品には、入力の制限はありません。</p>
      <div data-field-types="single_text multi_text">
        <label for="max_length">最大文字数</label>
        <input id="max_length" name="max_length" type="number" min="1" max="10000" value="<?= h($set('max_length')) ?>" placeholder="1 行は 200、複数行は 2000">
      </div>
      <div data-field-types="number">
        <label for="min">最小</label><input id="min" name="min" type="text" value="<?= h($set('min')) ?>">
        <label for="max">最大</label><input id="max" name="max" type="text" value="<?= h($set('max')) ?>">
        <label for="decimals">小数の桁（0〜4）</label><input id="decimals" name="decimals" type="number" min="0" max="4" value="<?= h($set('decimals')) ?>">
      </div>
      <div data-field-types="table">
        <label for="max_rows">行数の上限</label><input id="max_rows" name="max_rows" type="number" min="1" max="50" value="<?= h($set('max_rows')) ?>">
        <label for="columns_text">表の列（1 行に 1 列。キー|表示名|種類|選択肢）</label>
        <textarea id="columns_text" name="columns_text" placeholder="item|課題|multi_text&#10;status|状態|select|未着手,対応中,完了"><?= h($post !== null ? (string) ($post['columns_text'] ?? '') : $columnsText) ?></textarea>
        <p class="note">種類: single_text（1 行）/ multi_text（複数行）/ number（数値）/ date（日付）/ select（選択。選択肢をカンマ区切り）</p>
      </div>
    </fieldset>

    <label class="check"><input type="checkbox" name="is_required" value="1" <?= $flag('is_required', false) ? 'checked' : '' ?>> 必須（提出のときに確かめます）</label>
    <label class="check"><input type="checkbox" name="carry_over" value="1" <?= $flag('carry_over', true) ? 'checked' : '' ?>> 前月から引き継ぐ</label>

    <label for="confidentiality">機密区分</label>
    <select id="confidentiality" name="confidentiality">
      <?php foreach (FIELD_CONF as $k => $lbl):
          $disabled = $k === 'restricted' && !can_manage_system($user) && $val('confidentiality', 'normal') !== 'restricted'; ?>
        <option value="<?= h($k) ?>" <?= $val('confidentiality', 'normal') === $k ? 'selected' : '' ?> <?= $disabled ? 'disabled' : '' ?>><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="note">自由記述（1 行・複数行・表）は、区分にかかわらず暗号化して保存します。「限定」は本人・課長・管理者だけが見られ、出力されません（管理者だけが設定できます）。</p>

    <label for="help_text">説明文</label>
    <textarea id="help_text" name="help_text" maxlength="300"><?= h($val('help_text')) ?></textarea>
    <label for="placeholder">入力例</label>
    <input id="placeholder" name="placeholder" type="text" maxlength="100" value="<?= h($val('placeholder')) ?>">

    <?php
    // 表示の条件の今の値（入力エラーで戻ったときは送られた値）
    $selectedShowWhenFieldCode = $post !== null ? (string) ($post['show_when_field'] ?? '') : (string) ($sw['field'] ?? '');
    if ($post !== null) {
        $selectedShowWhenValueList = isset($post['show_when_value']) && is_array($post['show_when_value'])
            ? array_map('strval', $post['show_when_value'])
            : array_values(array_filter(array_map('trim', explode(',', (string) ($post['show_when_values'] ?? ''))), 'strlen'));
    } else {
        $selectedShowWhenValueList = array_map('strval', (array) ($sw['values'] ?? []));
    }
    ?>
    <fieldset class="sub show-when-box">
      <legend>表示の条件（任意）</legend>
      <p class="note">ほかの選択式の項目で、決めた値を選んだときだけ、この項目を出します（例: 進捗が「遅延あり」のときだけ「進捗の補足」を出す）。同じ区分（報告全体／案件ブロック）の項目だけ選べます。</p>
      <label for="show_when_field">この項目が</label>
      <select id="show_when_field" name="show_when_field">
        <option value="">（条件なし。いつも出す）</option>
        <?php foreach ($showWhenCandidateFieldList as $candidateField): ?>
          <option value="<?= h($candidateField['code']) ?>" data-scope="<?= h($candidateField['scope']) ?>" <?= $candidateField['code'] === $selectedShowWhenFieldCode ? 'selected' : '' ?>><?= h($candidateField['label']) ?>（<?= $candidateField['scope'] === 'report' ? '報告全体' : '案件ブロック' ?>）</option>
        <?php endforeach; ?>
      </select>
      <?php foreach ($showWhenCandidateFieldList as $candidateField): ?>
        <div class="choices show-when-values" data-field-code="<?= h($candidateField['code']) ?>">
          <span class="note"><?= h($candidateField['label']) ?> が次のどれかのとき:</span>
          <?php if ($candidateField['type'] === 'checkbox'): ?>
            <label class="choice"><input type="checkbox" name="show_when_value[]" value="1" <?= $candidateField['code'] === $selectedShowWhenFieldCode && in_array('1', $selectedShowWhenValueList, true) ? 'checked' : '' ?>> チェックあり</label>
          <?php else: ?>
            <?php foreach ($candidateField['options'] as $candidateOption): if (!$candidateOption['active']) { continue; } ?>
              <label class="choice"><input type="checkbox" name="show_when_value[]" value="<?= h($candidateOption['code']) ?>" <?= $candidateField['code'] === $selectedShowWhenFieldCode && in_array($candidateOption['code'], $selectedShowWhenValueList, true) ? 'checked' : '' ?>> <?= h($candidateOption['label']) ?></label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </fieldset>

    <label class="check"><input type="checkbox" name="show_in_list" value="1" <?= $flag('show_in_list', false) ? 'checked' : '' ?>> 横並び表示に出す</label>
    <label class="check"><input type="checkbox" name="is_searchable" value="1" <?= $flag('is_searchable', false) ? 'checked' : '' ?>> 検索の対象にする</label>
    <label class="check"><input type="checkbox" name="include_in_export" value="1" <?= $flag('include_in_export', true) ? 'checked' : '' ?>> 出力する</label>

    <button type="submit" class="primary">保存</button>
  </form>
</section>
