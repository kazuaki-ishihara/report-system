<?php
/** @var array|null $field */
/** @var array|null $post */
/** @var array $options */
/** @var array $settings */
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
    <div class="flash flash-error" role="alert"><?= h($e) ?></div>
  <?php endforeach; ?>

  <form method="post" action="<?= h(url('/admin/field_edit.php?' . ($field === null ? 'dept=' . rawurlencode($deptParam) : 'id=' . (int) $field['id']))) ?>" class="form">
    <?= form_hidden() ?>
    <label for="label">表示名</label>
    <input id="label" name="label" type="text" maxlength="60" value="<?= h($val('label')) ?>" required>

    <label for="code">コード</label>
    <?php if ($field === null): ?>
      <input id="code" name="code" type="text" maxlength="40" pattern="[a-z][a-z0-9_]{2,39}" value="<?= h($val('code')) ?>" required>
      <p class="note">英小文字で始まる、英小文字・数字・_。作成後は変更できません。</p>
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

    <fieldset class="sub">
      <legend>選択肢（プルダウン・ラジオ・チェックボックス）</legend>
      <table class="table">
        <thead><tr><th>コード</th><th>表示名</th><th>有効</th></tr></thead>
        <tbody>
        <?php foreach ($optRows as $i => $o): $isExisting = $o['code'] !== '' && in_array($o['code'], $existingCodes, true); ?>
          <tr>
            <td><?php if ($isExisting): ?><input type="hidden" name="opt_code[<?= $i ?>]" value="<?= h($o['code']) ?>"><?= h($o['code']) ?>
                <?php else: ?><input type="text" name="opt_code[<?= $i ?>]" value="<?= h($o['code']) ?>" maxlength="40" pattern="[a-z0-9_]{1,40}"><?php endif; ?></td>
            <td><input type="text" name="opt_label[<?= $i ?>]" value="<?= h($o['label']) ?>" maxlength="60"></td>
            <td><input type="checkbox" name="opt_active[<?= $i ?>]" value="1" <?= $o['active'] ? 'checked' : '' ?>></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="note">選択肢は削除できません（使わなくなったら「有効」を外します）。コードは保存される値で、あとから変えられません。表示名は変えられます。足りなければ保存すると空の行が増えます。</p>
    </fieldset>

    <fieldset class="sub">
      <legend>入力の制限</legend>
      <label for="max_length">最大文字数（1 行・複数行）</label>
      <input id="max_length" name="max_length" type="number" min="1" max="10000" value="<?= h($set('max_length')) ?>" placeholder="1 行は 200、複数行は 2000">
      <label for="min">最小（数値）</label><input id="min" name="min" type="text" value="<?= h($set('min')) ?>">
      <label for="max">最大（数値）</label><input id="max" name="max" type="text" value="<?= h($set('max')) ?>">
      <label for="decimals">小数の桁（数値、0〜4）</label><input id="decimals" name="decimals" type="number" min="0" max="4" value="<?= h($set('decimals')) ?>">
      <label for="max_rows">行数の上限（表）</label><input id="max_rows" name="max_rows" type="number" min="1" max="50" value="<?= h($set('max_rows')) ?>">
      <label for="columns_text">表の列（1 行に 1 列。キー|表示名|種類|選択肢）</label>
      <textarea id="columns_text" name="columns_text" placeholder="item|課題|multi_text&#10;status|状態|select|未着手,対応中,完了"><?= h($post !== null ? (string) ($post['columns_text'] ?? '') : $columnsText) ?></textarea>
      <p class="note">種類: single_text / multi_text / number / date / select</p>
    </fieldset>

    <label class="check"><input type="checkbox" name="is_required" value="1" <?= $flag('is_required', false) ? 'checked' : '' ?>> 必須（提出のときに確かめます）</label>
    <label class="check"><input type="checkbox" name="carry_over" value="1" <?= $flag('carry_over', true) ? 'checked' : '' ?>> 前月から引き継ぐ</label>

    <label for="confidentiality">機密区分</label>
    <select id="confidentiality" name="confidentiality">
      <?php foreach (FIELD_CONF as $k => $lbl):
          $disabled = $k === 'restricted' && !is_admin($user) && $val('confidentiality', 'normal') !== 'restricted'; ?>
        <option value="<?= h($k) ?>" <?= $val('confidentiality', 'normal') === $k ? 'selected' : '' ?> <?= $disabled ? 'disabled' : '' ?>><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="note">自由記述（1 行・複数行・表）は、区分にかかわらず暗号化して保存します。「限定」は本人・課長・管理者だけが見られ、出力されません（管理者だけが設定できます）。</p>

    <label for="help_text">説明文</label>
    <textarea id="help_text" name="help_text" maxlength="300"><?= h($val('help_text')) ?></textarea>
    <label for="placeholder">入力例</label>
    <input id="placeholder" name="placeholder" type="text" maxlength="100" value="<?= h($val('placeholder')) ?>">

    <fieldset class="sub">
      <legend>表示の条件（任意）</legend>
      <label for="show_when_field">この項目のコードが</label>
      <input id="show_when_field" name="show_when_field" type="text" value="<?= h($post !== null ? (string) ($post['show_when_field'] ?? '') : (string) ($sw['field'] ?? '')) ?>" placeholder="例: progress">
      <label for="show_when_values">次の値のときだけ表示（カンマ区切り）</label>
      <input id="show_when_values" name="show_when_values" type="text" value="<?= h($post !== null ? (string) ($post['show_when_values'] ?? '') : implode(',', (array) ($sw['values'] ?? []))) ?>" placeholder="例: delayed,other">
    </fieldset>

    <label class="check"><input type="checkbox" name="show_in_list" value="1" <?= $flag('show_in_list', false) ? 'checked' : '' ?>> 横並び表示に出す</label>
    <label class="check"><input type="checkbox" name="is_searchable" value="1" <?= $flag('is_searchable', false) ? 'checked' : '' ?>> 検索の対象にする</label>
    <label class="check"><input type="checkbox" name="include_in_export" value="1" <?= $flag('include_in_export', true) ? 'checked' : '' ?>> 出力する</label>

    <button type="submit" class="primary">保存</button>
  </form>
</section>
