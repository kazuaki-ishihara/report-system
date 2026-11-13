<?php
/** @var array $projects */
/** @var string $state */
/** @var array $departments */
/** @var array $errors */
/** @var int|null $editId */
$deptSelect = function (string $name, $selected) use ($departments): string {
    $html = '<select name="' . h($name) . '"><option value="">全課</option>';
    foreach ($departments as $d) {
        $html .= '<option value="' . (int) $d['id'] . '"' . ((string) $selected === (string) $d['id'] ? ' selected' : '') . '>' . h($d['name']) . '</option>';
    }
    return $html . '</select>';
};
?>
<section class="card wide">
  <h1>案件の管理</h1>
  <p class="warn">客先名・案件名は入力しないでください。客先との対応は、このシステムの外で管理します。</p>
  <?php foreach ($errors as $e): ?>
    <div class="flash flash-error" role="alert"><?= h_lines($e) ?></div>
  <?php endforeach; ?>

  <form method="post" action="<?= h(url('/admin/projects.php')) ?>" class="filters">
    <?= form_hidden() ?>
    <input type="hidden" name="action" value="add">
    <input type="text" name="alias" maxlength="40" placeholder="社内向けの呼び名（例: 物流系・在庫管理更改）" data-free-text="1">
    <?= $deptSelect('department_id', '') ?>
    <button type="submit" class="primary">＋ 案件を追加（コードは自動）</button>
  </form>

  <form method="get" action="<?= h(url('/admin/projects.php')) ?>" class="filters">
    <select name="state">
      <option value="active" <?= $state === 'active' ? 'selected' : '' ?>>有効</option>
      <option value="ended" <?= $state === 'ended' ? 'selected' : '' ?>>終了</option>
      <option value="all" <?= $state === 'all' ? 'selected' : '' ?>>すべて</option>
    </select>
    <button type="submit">表示</button>
    <a class="button" href="<?= h(url('/admin/projects.php?csv=1')) ?>">案件の一覧を CSV に出力（案件台帳の取り込み用）</a>
  </form>

  <table class="table">
    <thead><tr><th>コード</th><th>社内向けの呼び名</th><th>課</th><th>状態</th><th>使用中</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($projects as $p): $active = (int) $p['is_active'] === 1; ?>
      <tr>
        <td><?= h($p['code']) ?></td>
        <?php if ($editId === (int) $p['id']): ?>
          <td colspan="2">
            <form method="post" action="<?= h(url('/admin/projects.php')) ?>" class="filters">
              <?= form_hidden() ?>
              <input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <input type="text" name="alias" maxlength="40" value="<?= h($p['alias']) ?>" data-free-text="1">
              <?= $deptSelect('department_id', $p['department_id']) ?>
              <button type="submit" class="primary">保存</button>
            </form>
          </td>
        <?php else: ?>
          <td><?= $p['alias'] === '' ? '<span class="muted">（なし）</span>' : h($p['alias']) ?></td>
          <td><?= $p['dept_name'] === null ? '全課' : h($p['dept_name']) ?></td>
        <?php endif; ?>
        <td><?= $active ? '有効' : '終了（' . h((string) $p['ended_on']) . '）' ?></td>
        <td><?= (int) $p['users_now'] > 0 ? (int) $p['users_now'] . ' 人' : '—' ?></td>
        <td class="actions">
          <a href="<?= h(url('/admin/projects.php?edit=' . (int) $p['id'] . '&state=' . rawurlencode($state))) ?>">編集</a>
          <?php if ($active): ?>
            <form method="post" action="<?= h(url('/admin/projects.php')) ?>" class="inline" data-confirm="<?= h(msg('案件を終了にします。呼び名も消しますか？（消してもコードと過去の報告は残ります）チェックで選んでから押してください。')) ?>">
              <?= form_hidden() ?>
              <input type="hidden" name="action" value="end"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <label class="check small"><input type="checkbox" name="clear_alias" value="1" checked> 呼び名を消す</label>
              <button type="submit" class="small">終了</button>
            </form>
          <?php else: ?>
            <form method="post" action="<?= h(url('/admin/projects.php')) ?>" class="inline">
              <?= form_hidden() ?>
              <input type="hidden" name="action" value="reopen"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button type="submit" class="small">再開</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
