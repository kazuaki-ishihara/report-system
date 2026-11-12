<?php
/** @var string $tab */
/** @var array $errors */
?>
<section class="card wide">
  <h1>組織・ユーザー</h1>
  <nav class="tabs">
    <a href="<?= h(url('/admin/org.php?tab=departments')) ?>" class="<?= $tab === 'departments' ? 'active' : '' ?>">課</a>
    <a href="<?= h(url('/admin/org.php?tab=users')) ?>" class="<?= $tab === 'users' ? 'active' : '' ?>">ユーザー</a>
    <a href="<?= h(url('/admin/org.php?tab=words')) ?>" class="<?= $tab === 'words' ? 'active' : '' ?>">注意の言葉</a>
  </nav>
  <?php foreach ($errors as $e): ?>
    <div class="flash flash-error" role="alert"><?= h($e) ?></div>
  <?php endforeach; ?>

<?php if ($tab === 'departments'): ?>
  <p><a class="button" href="<?= h(url('/admin/org.php?tab=departments&new=1')) ?>">＋ 課を追加</a></p>
  <?php if ($newForm): ?>
    <form method="post" action="<?= h(url('/admin/org.php?tab=departments')) ?>" class="form">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="dept_save">
      <label for="name">課の名前</label>
      <input id="name" name="name" type="text" maxlength="40" required>
      <p class="note">課の名前には人名を入れないことをおすすめします（例: A課）。</p>
      <label for="close_day">締め日（1〜31。31 は月末。空欄はなし）</label>
      <input id="close_day" name="close_day" type="number" min="1" max="31">
      <label for="sort_order">並び順</label>
      <input id="sort_order" name="sort_order" type="number" min="0" value="0">
      <button type="submit" class="primary">追加する</button>
    </form>
  <?php endif; ?>

  <table class="table">
    <thead><tr><th>課</th><th>締め日</th><th>確認者</th><th>状態</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($departments as $d): ?>
      <tr>
        <td><?= h($d['name']) ?></td>
        <td><?= $d['close_day'] === null ? '—' : ((int) $d['close_day'] === 31 ? '月末' : (int) $d['close_day'] . '日') ?></td>
        <td><?php foreach ($reviewers[(int) $d['id']] ?? [] as $r): ?>
          <span class="tag"><?= (int) $r['is_final'] === 1 ? '課長 ' : '' ?><?= h($r['display_name']) ?></span>
        <?php endforeach; ?></td>
        <td><?= (int) $d['is_active'] === 1 ? '有効' : '無効' ?></td>
        <td><a href="<?= h(url('/admin/org.php?tab=departments&edit=' . (int) $d['id'])) ?>">編集</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($edit !== null): ?>
    <h2><?= h($edit['name']) ?> の編集</h2>
    <form method="post" action="<?= h(url('/admin/org.php?tab=departments&edit=' . (int) $edit['id'])) ?>" class="form">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="dept_save">
      <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
      <label for="e_name">課の名前</label>
      <input id="e_name" name="name" type="text" maxlength="40" value="<?= h($edit['name']) ?>" required>
      <label for="e_close">締め日（1〜31。31 は月末。空欄はなし）</label>
      <input id="e_close" name="close_day" type="number" min="1" max="31" value="<?= h((string) $edit['close_day']) ?>">
      <label for="e_sort">並び順</label>
      <input id="e_sort" name="sort_order" type="number" min="0" value="<?= (int) $edit['sort_order'] ?>">
      <label class="check"><input type="checkbox" name="is_active" value="1" <?= (int) $edit['is_active'] === 1 ? 'checked' : '' ?>> 有効</label>
      <button type="submit" class="primary">保存</button>
    </form>

    <h2>確認者と項目管理者</h2>
    <p class="note">確認者はこの課の全員の報告を見られます（「限定」の項目は課長だけ）。項目管理者はこの課の課別項目を変更できます。</p>
    <form method="post" action="<?= h(url('/admin/org.php?tab=departments&edit=' . (int) $edit['id'])) ?>" class="form"
          data-confirm="確認者に設定した人は、この課の全員の報告を見られるようになります。保存しますか？">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="roles_save">
      <input type="hidden" name="dept_id" value="<?= (int) $edit['id'] ?>">
      <table class="table">
        <thead><tr><th>名前</th><th>所属</th><th>役割</th><th>項目管理者</th></tr></thead>
        <tbody>
        <?php foreach ($candidates as $c):
            $role = (int) $c['is_reviewer'] === 1 ? ((int) $c['is_final'] === 1 ? 'final' : 'reviewer') : ''; ?>
          <tr>
            <td><?= h($c['display_name']) ?> <span class="note"><?= h($c['login_id']) ?></span></td>
            <td><?= h((string) $c['dept_name']) ?></td>
            <td>
              <select name="role[<?= (int) $c['id'] ?>]">
                <option value="" <?= $role === '' ? 'selected' : '' ?>>—</option>
                <option value="reviewer" <?= $role === 'reviewer' ? 'selected' : '' ?>>確認者</option>
                <option value="final" <?= $role === 'final' ? 'selected' : '' ?>>課長（最終確認者）</option>
              </select>
            </td>
            <td><input type="checkbox" name="field_admin[<?= (int) $c['id'] ?>]" value="1" <?= (int) $c['is_field_admin'] === 1 ? 'checked' : '' ?>></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <button type="submit" class="primary">確認者を保存</button>
    </form>
  <?php endif; ?>

<?php elseif ($tab === 'users'): ?>
  <form method="get" action="<?= h(url('/admin/org.php')) ?>" class="filters">
    <input type="hidden" name="tab" value="users">
    <select name="dept"><option value="">課: すべて</option>
      <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>" <?= $deptFilter === (string) $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="state">
      <option value="active" <?= $state === 'active' ? 'selected' : '' ?>>有効</option>
      <option value="inactive" <?= $state === 'inactive' ? 'selected' : '' ?>>無効</option>
      <option value="all" <?= $state === 'all' ? 'selected' : '' ?>>すべて</option>
    </select>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="ID・名前で検索">
    <button type="submit">絞り込む</button>
    <a class="button" href="<?= h(url('/admin/user_edit.php')) ?>">＋ ユーザーを追加</a>
  </form>
  <table class="table">
    <thead><tr><th>ログイン ID</th><th>表示名</th><th>課</th><th>役割</th><th>2 段階認証</th><th>ロック</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr class="<?= (int) $u['is_active'] === 1 ? '' : 'muted' ?>">
        <td><?= h((string) $u['login_id']) ?></td>
        <td><?= h($u['display_name']) ?><?= (int) $u['is_admin'] === 1 ? ' <span class="tag">管理者</span>' : '' ?></td>
        <td><?= h((string) $u['dept_name']) ?></td>
        <td><?= h((string) $u['roles']) ?></td>
        <td><?= $u['totp_enabled_at'] !== null ? '設定済み' : '未設定' ?></td>
        <td><?= ($u['locked_until'] !== null && $u['locked_until'] > now()) ? 'ロック中' : '' ?></td>
        <td><a href="<?= h(url('/admin/user_edit.php?id=' . (int) $u['id'])) ?>">編集</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

<?php else: ?>
  <p>自由記述にこれらの言葉が入ると、「客先名や個人名が含まれていませんか」と注意を出します。</p>
  <p class="warn">実際の客先名は入れないでください（この一覧が漏れても客先が分からないように）。</p>
  <form method="post" action="<?= h(url('/admin/org.php?tab=words')) ?>" class="filters">
    <?= form_hidden() ?>
    <input type="hidden" name="action" value="word_add">
    <input type="text" name="word" maxlength="20" placeholder="例: 株式会社" required>
    <button type="submit">追加</button>
  </form>
  <ul class="list">
    <?php foreach ($words as $w): ?>
      <li><span><?= h($w['word']) ?></span>
        <form method="post" action="<?= h(url('/admin/org.php?tab=words')) ?>" class="inline">
          <?= form_hidden() ?>
          <input type="hidden" name="action" value="word_delete">
          <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
          <button type="submit">削除</button>
        </form></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
