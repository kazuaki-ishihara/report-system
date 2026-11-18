<?php
/** @var array $rows */
/** @var string $deptParam */
/** @var int|null $deptId */
/** @var array $manageable */
/** @var callable $canEdit */
$groups = ['report' => '報告全体', 'project' => '案件ブロック（案件の数だけ繰り返す）'];
$back = '/admin/fields.php?dept=' . rawurlencode($deptParam);
?>
<section class="card wide">
  <h1>項目マスタ</h1>
  <form method="get" action="<?= h(url('/admin/fields.php')) ?>" class="filters">
    <label for="dept">様式</label>
    <select id="dept" name="dept">
      <?php if (can_manage_system($user)): ?><option value="common" <?= $deptParam === 'common' ? 'selected' : '' ?>>共通項目のみ</option><?php endif; ?>
      <?php foreach ($manageable as $d): ?>
        <option value="<?= (int) $d['id'] ?>" <?= $deptParam === (string) $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?>の様式（共通＋<?= h($d['name']) ?>）</option>
      <?php endforeach; ?>
    </select>
    <button type="submit">表示</button>
    <?php if ($deptId !== null || can_manage_system($user)): ?>
      <a class="button" href="<?= h(url('/admin/field_edit.php?dept=' . rawurlencode($deptParam))) ?>">＋ 項目を足す</a>
    <?php endif; ?>
    <?php if ($deptId !== null): ?>
      <a class="button" href="<?= h(url('/admin/field_edit.php?preview=' . (int) $deptId)) ?>">プレビュー</a>
    <?php endif; ?>
  </form>
  <p class="note">変更は、これから作る報告に反映されます。すでにある報告は、作成したときの項目のまま表示されます。</p>

  <?php foreach (['active' => 1, 'inactive' => 0] as $stateName => $state): ?>
    <?php if ($state === 0): ?><details><summary>無効の項目</summary><?php endif; ?>
    <?php foreach ($groups as $scope => $gLabel):
        $list = array_values(array_filter($rows, function ($r) use ($scope, $state) { return $r['field_scope'] === $scope && (int) $r['is_active'] === $state; }));
        if (!$list) { continue; } ?>
      <h2><?= h($gLabel) ?></h2>
      <table class="table">
        <thead><tr><th>項目</th><th>入力部品</th><th>適用</th><th>区分</th><th>設定</th><th>使用</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $r): $editable = $canEdit($r); ?>
          <tr id="field-<?= (int) $r['id'] ?>">
            <td><?= h($r['label']) ?><br><span class="note"><?= h($r['code']) ?></span></td>
            <td><?= h(FIELD_TYPES[$r['field_type']] ?? $r['field_type']) ?></td>
            <td><?= $r['department_id'] === null ? '共通' : h((string) $r['dept_name']) ?></td>
            <td><?= h(FIELD_CONF[$r['confidentiality']] ?? '') ?></td>
            <td class="note"><?= (int) $r['is_required'] === 1 ? '必須 ' : '' ?><?= (int) $r['carry_over'] === 1 ? '引継ぎ ' : '' ?><?= (int) $r['show_in_list'] === 1 ? '一覧 ' : '' ?></td>
            <td><?= (int) $r['used'] > 0 ? (int) $r['used'] . '件' : '—' ?></td>
            <td class="actions">
              <?php if ($editable): ?>
                <a href="<?= h(url('/admin/field_edit.php?id=' . (int) $r['id'] . '&dept=' . rawurlencode($deptParam))) ?>">編集</a>
                <?php if ($state === 1): ?>
                  <?php foreach (['move_up' => '↑', 'move_down' => '↓'] as $act => $lbl): ?>
                    <form method="post" action="<?= h(url($back)) ?>" class="inline"><?= form_hidden() ?>
                      <input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="field_id" value="<?= (int) $r['id'] ?>">
                      <button type="submit" class="small" title="<?= $act === 'move_up' ? '上へ' : '下へ' ?>"><?= $lbl ?></button></form>
                  <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($r['field_type'] !== 'project'): ?>
                  <form method="post" action="<?= h(url($back)) ?>" class="inline" <?= $state === 1 ? 'data-confirm="' . h(msg('新しい報告に出なくなります。過去の報告には残ります。無効にしますか？')) . '"' : '' ?>><?= form_hidden() ?>
                    <input type="hidden" name="action" value="<?= $state === 1 ? 'deactivate' : 'activate' ?>"><input type="hidden" name="field_id" value="<?= (int) $r['id'] ?>">
                    <button type="submit" class="small"><?= $state === 1 ? '無効にする' : '有効にする' ?></button></form>
                <?php endif; ?>
              <?php else: ?>
                <span class="note">見るだけ</span>
              <?php endif; ?>
              <?php if ($deptId !== null && $state === 1 && $r['department_id'] === null && in_array($r['field_type'], CHOICE_TYPES, true) && can_manage_fields_of($user, $deptId)): ?>
                <a class="button small" href="<?= h(url('/admin/field_options.php?field=' . (int) $r['id'] . '&dept=' . (int) $deptId)) ?>">この課の選択肢</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>
    <?php if ($state === 0): ?></details><?php endif; ?>
  <?php endforeach; ?>
</section>
