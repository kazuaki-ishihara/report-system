<?php
/** @var array<string, mixed> $department 管理する課の行 */
/** @var array<int, array<string, mixed>> $manageableDepartmentById 自分が課長の課（課 ID => 課の行） */
/** @var array<int, array<string, mixed>> $memberRowList 課員と確認者の行（department_manage_member_rows()） */
/** @var array<int, array{id: int|string, name: string, is_active: int|string}> $teamList 班（無効を含む） */
/** @var string[] $errorTextList */
$departmentId = (int) $department['id'];
$selfUrl = url('/admin/department.php?dept=' . $departmentId);
$isTeamScope = ($department['review_scope'] ?? 'department') === 'team';
$activeTeamList = array_values(array_filter($teamList, function (array $teamRow): bool { return (int) $teamRow['is_active'] === 1; }));
$renderTeamOptions = function (?int $selectedTeamId, string $emptyLabel) use ($activeTeamList): string {
    $optionHtml = '<option value="">' . h($emptyLabel) . '</option>';
    foreach ($activeTeamList as $teamRow) {
        $optionHtml .= '<option value="' . (int) $teamRow['id'] . '"' . ((int) $teamRow['id'] === $selectedTeamId ? ' selected' : '') . '>' . h($teamRow['name']) . '</option>';
    }
    return $optionHtml;
};
?>
<section class="card wide">
  <h1>課の管理</h1>
  <p class="note">自分が課長の課の、締め日・管理の仕方・確認者（課代・リーダー）・チェックする人・項目管理者・班を決めます。課の名前・課長・組織の改訂（引き継ぎ）は、システム管理者が決めます。</p>
  <?php if (count($manageableDepartmentById) > 1): ?>
    <form method="get" action="<?= h(url('/admin/department.php')) ?>" class="filters">
      <select name="dept" aria-label="課"><?php foreach ($manageableDepartmentById as $manageableDepartmentId => $manageableDepartment): ?><option value="<?= (int) $manageableDepartmentId ?>" <?= $manageableDepartmentId === $departmentId ? 'selected' : '' ?>><?= h($manageableDepartment['name']) ?></option><?php endforeach; ?></select>
      <button type="submit">表示</button>
    </form>
  <?php endif; ?>
  <h2><?= h($department['name']) ?></h2>
  <?php foreach ($errorTextList as $errorText): ?><div class="flash flash-error" role="alert"><?= h_lines($errorText) ?></div><?php endforeach; ?>

  <div class="tabbed-sections">
  <section id="dept-basic" data-tab-title="基本">
    <form method="post" action="<?= h($selfUrl) ?>" class="form">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="settings_save">
      <label for="close_day">締め日（1〜31。31 は月末。空欄はなし）</label>
      <input id="close_day" name="close_day" type="number" min="1" max="31" value="<?= h((string) $department['close_day']) ?>">
      <label>管理の仕方（課代・リーダーが見る報告の範囲）</label>
      <div class="choices">
        <label class="choice"><input type="radio" name="review_scope" value="department" <?= $isTeamScope ? '' : 'checked' ?>> 課全体（課代・リーダーは課の全員の報告を見る）</label>
        <label class="choice"><input type="radio" name="review_scope" value="team" <?= $isTeamScope ? 'checked' : '' ?>> 班ごと（課代・リーダーは担当の班の報告だけを見る。課長は全員）</label>
      </div>
      <button type="submit" class="primary">保存</button>
    </form>
  </section>

  <section id="dept-members" data-tab-title="確認者と課員">
    <p class="note">「確認者」に印を付けた人は、この課の報告を見てコメントできます（「班ごと」の課では担当の班の人の報告だけ。「限定」の項目は課長だけ）。
      「チェックする」の人だけが報告のチェックをし、「チェック n/m」の m に数えます。チェックしない人も、報告を見ること・コメントはできます。
      新しく確認者にするときの「チェックする」は、課長代理は印あり、ほか（リーダーなど）は印なしで出します。項目管理者は、この課の課別項目を変更できます。</p>
    <form method="post" action="<?= h($selfUrl) ?>" class="form" data-confirm="<?= h(msg('確認者・チェックする人・項目管理者・班を保存します。よろしいですか？')) ?>">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="members_save">
      <input type="search" class="table-filter" data-filter-table="#department-member-table" placeholder="名前・ID で絞り込む" hidden>
      <table class="table" id="department-member-table">
        <thead><tr><th>名前</th><th>役職</th><?php if ($isTeamScope): ?><th>班</th><?php endif; ?><th>確認者</th><?php if ($isTeamScope): ?><th>担当の班</th><?php endif; ?><th>チェックする</th><th>項目管理者</th></tr></thead>
        <tbody>
        <?php foreach ($memberRowList as $memberRow):
            $memberUserId = (int) $memberRow['id'];
            $isFinalMember = (int) $memberRow['is_final'] === 1;
            $isReviewerMember = (int) $memberRow['is_reviewer'] === 1;
            $isDepartmentMember = (int) $memberRow['department_id'] === $departmentId;
            $doesCheckChecked = $isReviewerMember ? (int) $memberRow['does_check'] === 1 : (string) $memberRow['role_title'] === '課長代理'; ?>
          <tr>
            <td><?= h($memberRow['display_name']) ?> <span class="note"><?= h((string) $memberRow['login_id']) ?></span><?= $isDepartmentMember ? '' : ' <span class="tag">ほかの課</span>' ?></td>
            <td><?= h((string) $memberRow['role_title']) ?></td>
            <?php if ($isTeamScope): ?>
              <td><?php if ($isDepartmentMember): ?><select name="member_team[<?= $memberUserId ?>]" aria-label="<?= h($memberRow['display_name']) ?>の班"><?= $renderTeamOptions($memberRow['team_id'] === null ? null : (int) $memberRow['team_id'], '班なし') ?></select><?php else: ?>—<?php endif; ?></td>
            <?php endif; ?>
            <td><?php if ($isFinalMember): ?><strong>課長</strong><?php else: ?><label class="check small"><input type="checkbox" name="reviewer[<?= $memberUserId ?>]" value="1" <?= $isReviewerMember ? 'checked' : '' ?>> 確認者</label><?php endif; ?></td>
            <?php if ($isTeamScope): ?>
              <td><?php if ($isFinalMember): ?>課全体<?php else: ?><select name="reviewer_team[<?= $memberUserId ?>]" aria-label="<?= h($memberRow['display_name']) ?>の担当の班"><?= $renderTeamOptions($memberRow['reviewer_team_id'] === null ? null : (int) $memberRow['reviewer_team_id'], '課全体') ?></select><?php endif; ?></td>
            <?php endif; ?>
            <td><?php if ($isFinalMember): ?>常にする<?php else: ?><label class="check small"><input type="checkbox" name="does_check[<?= $memberUserId ?>]" value="1" <?= $doesCheckChecked ? 'checked' : '' ?>> する</label><?php endif; ?></td>
            <td><input type="checkbox" name="field_admin[<?= $memberUserId ?>]" value="1" <?= (int) $memberRow['is_field_admin'] === 1 ? 'checked' : '' ?> aria-label="<?= h($memberRow['display_name']) ?>を項目管理者にする"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <button type="submit" class="primary">確認者と課員を保存</button>
    </form>
  </section>

  <section id="dept-teams" data-tab-title="班">
    <?php if (!$isTeamScope): ?><p class="note">この課の管理の仕方は「課全体」です。班は「基本」で「班ごと」にしたときに使います。</p><?php endif; ?>
    <p class="note">「班ごと」の課で使います。課代（課長代理）ごとの班を作り、「確認者と課員」で確認者の担当の班と、課員の班を選びます。班を移った人の過去の報告は、今の班の課代・リーダーが見ます。</p>
    <?php foreach ($teamList as $teamRow): ?>
      <form method="post" action="<?= h($selfUrl) ?>" class="filters">
        <?= form_hidden() ?>
        <input type="hidden" name="action" value="team_update"><input type="hidden" name="team_id" value="<?= (int) $teamRow['id'] ?>">
        <input type="text" name="team_name" value="<?= h($teamRow['name']) ?>" maxlength="40" aria-label="班の名前">
        <label class="check"><input type="checkbox" name="team_active" value="1" <?= (int) $teamRow['is_active'] === 1 ? 'checked' : '' ?>> 有効</label>
        <button type="submit" class="small">保存</button>
      </form>
    <?php endforeach; ?>
    <form method="post" action="<?= h($selfUrl) ?>" class="filters">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="team_add">
      <input type="text" name="team_name" maxlength="40" placeholder="例: 山田班" aria-label="足す班の名前">
      <button type="submit" class="small">班を足す</button>
    </form>
  </section>
  </div>
</section>
