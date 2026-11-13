<?php
/** @var array|null $req */
/** @var array $depts */
/** @var bool $editable */
/** @var array $errors */
/** @var array $questions */
/** @var array $members */
/** @var array $targets */
/** @var array $sources */
/** @var array|null $post */
$v = function (string $k, string $default = '') use ($req, $post): string {
    if ($post !== null && isset($post[$k]) && is_string($post[$k]) && ($post['action'] ?? '') === 'save_meta') {
        return $post[$k];
    }
    return $req !== null && isset($req[$k]) ? (string) $req[$k] : $default;
};
$self = $req === null ? '/surveys/survey_edit.php' : '/surveys/survey_edit.php?id=' . (int) $req['id'];
$statusLabel = $req === null ? '新規' : ((int) $req['is_template'] === 1 ? 'ひな形' : ['draft' => '下書き', 'open' => '公開中', 'closed' => '締め切り'][$req['status']]);
?>
<section class="card">
  <p><a href="<?= h(url('/surveys/manage.php')) ?>">← 臨時の調査</a></p>
  <h1><?= $req === null ? '臨時の調査を作る' : h($req['title']) ?> <span class="tag"><?= h($statusLabel) ?></span></h1>
  <?php foreach ($errors as $e): ?><div class="flash flash-error" role="alert"><?= h_lines($e) ?></div><?php endforeach; ?>

  <form method="post" action="<?= h(url($self)) ?>" class="form">
    <?= form_hidden() ?>
    <input type="hidden" name="action" value="save_meta">
    <?php if ($req === null): ?>
      <label for="department_id">課</label>
      <select id="department_id" name="department_id"><?php foreach ($depts as $id => $d): ?><option value="<?= (int) $id ?>"><?= h($d['name']) ?></option><?php endforeach; ?></select>
    <?php endif; ?>
    <label for="title">題名</label>
    <input id="title" name="title" type="text" maxlength="100" value="<?= h($v('title')) ?>" required>
    <label for="requester">依頼元（例: 経営企画）</label>
    <input id="requester" name="requester" type="text" maxlength="100" value="<?= h($v('requester')) ?>">
    <label for="purpose">目的（回答する人に表示します）</label>
    <textarea id="purpose" name="purpose" maxlength="300"><?= h($v('purpose')) ?></textarea>
    <label for="due_at">回答期限</label>
    <input id="due_at" name="due_at" type="date" value="<?= h($v('due_at')) ?>">
    <?php if ($editable): ?>
      <label>結果の扱い</label>
      <div class="choices">
        <label class="choice"><input type="radio" name="result_visibility" value="summary" <?= $v('result_visibility', 'summary') === 'summary' ? 'checked' : '' ?>> 依頼元には集計のみを渡す</label>
        <label class="choice"><input type="radio" name="result_visibility" value="detail" <?= $v('result_visibility', 'summary') === 'detail' ? 'checked' : '' ?>> 個人の回答も渡す</label>
      </div>
      <label>対象</label>
      <div class="choices">
        <label class="choice"><input type="radio" name="target_type" value="all" <?= $v('target_type', 'all') === 'all' ? 'checked' : '' ?>> 課の全員（公開の時点の所属者）</label>
        <label class="choice"><input type="radio" name="target_type" value="users" <?= $v('target_type', 'all') === 'users' ? 'checked' : '' ?>> 人を指定</label>
      </div>
      <details <?= $v('target_type', 'all') === 'users' ? 'open' : '' ?>>
        <summary>人を指定するとき</summary>
        <?php foreach ($members as $m): ?>
          <label class="choice"><input type="checkbox" name="targets[]" value="<?= (int) $m['id'] ?>" <?= in_array((int) $m['id'], $targets, true) ? 'checked' : '' ?>> <?= h($m['display_name']) ?></label>
        <?php endforeach; ?>
      </details>
    <?php else: ?>
      <input type="hidden" name="result_visibility" value="<?= h($req['result_visibility']) ?>">
      <input type="hidden" name="target_type" value="<?= h($req['target_type']) ?>">
      <p class="note">公開後は、題名・依頼元・目的・期限だけ変えられます。対象 <?= count($targets) ?> 人。</p>
    <?php endif; ?>
    <button type="submit"><?= $req === null ? '作成して質問を足す' : '保存' ?></button>
  </form>
</section>

<?php if ($req !== null): ?>
<section class="card">
  <h2>質問（<?= count($questions) ?>）</h2>
  <?php if (!$questions): ?><p class="note">質問がまだありません。下から足してください。</p><?php endif; ?>
  <ol>
    <?php foreach ($questions as $q): ?>
      <li>
        <?= h($q['label']) ?> <span class="note"><?= h(FIELD_TYPES[$q['type']] ?? $q['type']) ?><?= $q['source'] !== null ? '・月次の項目' : '' ?><?= $q['required'] ? '・必須' : '' ?></span><?= form_badges(array_merge($q, ['required' => false])) ?>
        <?php if ($q['options']): ?><br><span class="note"><?= h(implode(' / ', array_map(function ($o) { return $o['label']; }, $q['options']))) ?></span><?php endif; ?>
        <?php if ($editable): ?>
          <span class="actions">
          <?php foreach (['q_up' => '↑', 'q_down' => '↓', 'q_remove' => '外す'] as $act => $lbl): ?>
            <form method="post" action="<?= h(url($self)) ?>" class="inline"><?= form_hidden() ?>
              <input type="hidden" name="q_id" value="<?= (int) $q['id'] ?>"><button type="submit" name="action" value="<?= $act ?>" class="small"><?= $lbl ?></button></form>
          <?php endforeach; ?>
          </span>
        <?php endif; ?>
        <?php if ($q['source'] === null && is_final_reviewer_of((int) $user['id'], (int) $req['department_id'])): ?>
          <a class="note" href="<?= h(url('/admin/field_edit.php?dept=' . (int) $req['department_id'] . '&from_q=' . (int) $q['id'])) ?>">月次の項目へ移す</a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>

  <?php if ($editable): ?>
    <h3>月次報告の項目を使う</h3>
    <p class="note">回答欄には、回答する人の最新の月次報告の値が入ります。</p>
    <form method="post" action="<?= h(url($self)) ?>" class="filters">
      <?= form_hidden() ?>
      <select name="field_id" aria-label="月次の項目"><?php foreach ($sources as $f): ?><option value="<?= (int) $f['id'] ?>"><?= h($f['label']) ?></option><?php endforeach; ?></select>
      <button type="submit" name="action" value="add_source">足す</button>
    </form>
    <h3>この調査だけの質問を足す</h3>
    <form method="post" action="<?= h(url($self)) ?>" class="form">
      <?= form_hidden() ?>
      <label for="q_label">質問</label>
      <input id="q_label" name="q_label" type="text" maxlength="100" placeholder="例: AI を業務で使っていますか" required>
      <label for="q_type">答え方</label>
      <select id="q_type" name="q_type"><?php foreach (SURVEY_TYPES as $t): ?><option value="<?= h($t) ?>" <?= $t === 'radio' ? 'selected' : '' ?>><?= h(FIELD_TYPES[$t]) ?></option><?php endforeach; ?></select>
      <label for="q_options">選択肢（1 行に 1 つ。プルダウン・ラジオ・チェックボックスのとき）</label>
      <textarea id="q_options" name="q_options" placeholder="毎日使う&#10;ときどき使う&#10;使わない"></textarea>
      <label for="q_conf">機密区分</label>
      <select id="q_conf" name="q_conf"><?php foreach (FIELD_CONF as $k => $l): ?><option value="<?= h($k) ?>" <?= $k === 'internal' ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
      <label for="q_help">説明（任意）</label>
      <input id="q_help" name="q_help" type="text" maxlength="300">
      <label class="check"><input type="checkbox" name="q_required" value="1"> 必須</label>
      <button type="submit" name="action" value="add_custom">足す</button>
    </form>
  <?php endif; ?>
</section>

<section class="card">
  <h2>操作</h2>
  <div class="actions">
    <?php if ($editable && (int) $req['is_template'] === 0): ?>
      <form method="post" action="<?= h(url($self)) ?>" class="inline" data-confirm="<?= h(msg('公開すると質問は変えられません。公開しますか？')) ?>"><?= form_hidden() ?>
        <button type="submit" name="action" value="publish" class="primary">公開する</button></form>
    <?php endif; ?>
    <?php if ($req['status'] === 'open'): ?>
      <form method="post" action="<?= h(url($self)) ?>" class="inline" data-confirm="<?= h(msg('締め切ります。よろしいですか？')) ?>"><?= form_hidden() ?>
        <button type="submit" name="action" value="close">締め切る</button></form>
    <?php endif; ?>
    <?php if ($req['status'] !== 'draft' && (int) $req['is_template'] === 0): ?><a class="button" href="<?= h(url('/surveys/result.php?id=' . (int) $req['id'])) ?>">結果を見る</a><?php endif; ?>
    <form method="post" action="<?= h(url($self)) ?>" class="inline"><?= form_hidden() ?><button type="submit" name="action" value="copy">複製</button></form>
    <?php if ((int) $req['is_template'] === 0): ?>
      <form method="post" action="<?= h(url($self)) ?>" class="inline"><?= form_hidden() ?><button type="submit" name="action" value="save_template">ひな形として保存</button></form>
    <?php endif; ?>
    <form method="post" action="<?= h(url($self)) ?>" class="inline" data-confirm="<?= h(msg('削除します。よろしいですか？')) ?>"><?= form_hidden() ?>
      <button type="submit" name="action" value="delete" class="danger">削除</button></form>
  </div>
</section>
<?php endif; ?>
