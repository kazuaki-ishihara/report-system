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
    <a href="<?= h(url('/admin/org.php?tab=bulk')) ?>" class="<?= $tab === 'bulk' ? 'active' : '' ?>">一括（CSV）</a>
  </nav>
  <?php foreach ($errors as $e): ?>
    <div class="flash flash-error" role="alert"><?= h_lines($e) ?></div>
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
      <label for="sort_order">並び順</label>
      <input id="sort_order" name="sort_order" type="number" min="0" value="0">
      <button type="submit" class="primary">追加する</button>
    </form>
  <?php endif; ?>

  <?php if ($edit !== null): ?>
    <div class="edit-panel">
    <h2><?= h($edit['name']) ?> の編集 <a class="note" href="<?= h(url('/admin/org.php?tab=departments')) ?>">閉じる</a></h2>
    <div class="tabbed-sections">
    <section id="dept-basic" data-tab-title="基本">
    <form method="post" action="<?= h(url('/admin/org.php?tab=departments&edit=' . (int) $edit['id'])) ?>" class="form">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="dept_save">
      <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
      <label for="e_name">課の名前</label>
      <input id="e_name" name="name" type="text" maxlength="40" value="<?= h($edit['name']) ?>" required>
      <label for="e_sort">並び順</label>
      <input id="e_sort" name="sort_order" type="number" min="0" value="<?= (int) $edit['sort_order'] ?>">
      <label class="check"><input type="checkbox" name="is_active" value="1" <?= (int) $edit['is_active'] === 1 ? 'checked' : '' ?>> 有効</label>
      <label class="check"><input type="checkbox" name="is_management" value="1" <?= $managementDepartmentId === (int) $edit['id'] ? 'checked' : '' ?>> この課をシステムの管理担当にする（この課の課長が、画面の文言（メッセージ）を変えられる。1 つの課だけ）</label>
      <button type="submit" class="primary">保存</button>
    </form>
    </section>
    <section id="dept-final" data-tab-title="課長">
    <p class="note">この課の課長（最終確認者）を決めます。課代・リーダー（確認者）・チェックする人・項目管理者・班・締め日・管理の仕方は、この課の課長が「課の管理」の画面で決めます（システム管理者は、個々の課の管理はしません）。</p>
    <?php $currentFinalCandidate = null; foreach ($candidates as $candidateRow) { if ((int) $candidateRow['is_final'] === 1) { $currentFinalCandidate = $candidateRow; } } ?>
    <p>今の課長: <strong><?= $currentFinalCandidate === null ? h(msg('課長がいません（確認を完了にできません）')) : h($currentFinalCandidate['display_name']) ?></strong></p>
    <form method="post" action="<?= h(url('/admin/org.php?tab=departments&edit=' . (int) $edit['id'])) ?>" class="form"
          data-confirm="<?= h(msg('課長を変えます。課長はこの課の全員の報告（「限定」の項目を含む）を見られるようになります。よろしいですか？')) ?>">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="final_save">
      <input type="hidden" name="dept_id" value="<?= (int) $edit['id'] ?>">
      <label for="final_user_id">課長</label>
      <select id="final_user_id" name="final_user_id">
        <option value="">（課長なし）</option>
        <?php foreach ($candidates as $candidateRow): ?>
          <option value="<?= (int) $candidateRow['id'] ?>" <?= (int) $candidateRow['is_final'] === 1 ? 'selected' : '' ?>><?= h($candidateRow['display_name']) ?>（<?= h((string) $candidateRow['login_id']) ?>・<?= h((string) ($candidateRow['dept_name'] ?? '課なし')) ?>・<?= h((string) $candidateRow['role_title']) ?>）</option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="primary">課長を保存</button>
    </form>
    </section>
    <section id="dept-succession" data-tab-title="引き継ぎ">
    <p class="note">組織の改訂で課をまとめた・分けた・作り直したときに、改訂の前の課を選びます。この課の確認者（課長を含む）が、選んだ課の過去の報告も見られるようになります（前月との比べ・検索を含む）。改訂の前の課を無効にしても、報告は残ります。</p>
    <form method="post" action="<?= h(url('/admin/org.php?tab=departments&edit=' . (int) $edit['id'])) ?>" class="form"
          data-confirm="<?= h(msg('この課の確認者が、選んだ課の過去の報告を見られるようになります。保存しますか？')) ?>">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="succession_save">
      <input type="hidden" name="dept_id" value="<?= (int) $edit['id'] ?>">
      <div class="choices">
        <?php foreach ($allDepartmentList as $otherDepartment): ?>
          <label class="choice"><input type="checkbox" name="predecessor_ids[]" value="<?= (int) $otherDepartment['id'] ?>" <?= isset($predecessorIdSet[(int) $otherDepartment['id']]) ? 'checked' : '' ?>>
            <?= h($otherDepartment['name']) ?><?= (int) $otherDepartment['is_active'] === 1 ? '' : '（無効）' ?></label>
        <?php endforeach; ?>
      </div>
      <button type="submit">引き継ぐ課を保存</button>
    </form>
    </section>
    </div>
    </div>
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
        <?php endforeach; ?>
          <?php $hasFinalReviewer = (bool) array_filter($reviewers[(int) $d['id']] ?? [], function (array $reviewerRow): bool { return (int) $reviewerRow['is_final'] === 1; }); ?>
          <?php if ((int) $d['is_active'] === 1 && !$hasFinalReviewer): ?><span class="tag tag-open"><?= h(msg('課長がいません（確認を完了にできません）')) ?></span><?php endif; ?>
          <?php if (!empty($predecessorNameListByDepartmentId[(int) $d['id']])): ?><br><span class="note">引き継ぎ: <?= h(implode('、', $predecessorNameListByDepartmentId[(int) $d['id']])) ?></span><?php endif; ?></td>
        <td><?= (int) $d['is_active'] === 1 ? '有効' : '無効' ?></td>
        <td><a href="<?= h(url('/admin/org.php?tab=departments&edit=' . (int) $d['id'])) ?>">編集</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>


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
  <input type="search" class="table-filter" data-filter-table="#user-table" placeholder="この一覧をさらに絞り込む" hidden>
  <table class="table" id="user-table">
    <thead><tr><th>ログイン ID</th><th>表示名</th><th>課</th><th>班</th><th>役割</th><th>2 段階認証</th><th>ロック</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr class="<?= (int) $u['is_active'] === 1 ? '' : 'muted' ?>">
        <td><?= h((string) $u['login_id']) ?></td>
        <td><?= h($u['display_name']) ?><?= (int) $u['is_admin'] === 1 ? ' <span class="tag">管理者</span>' : '' ?><?= (int) $u['is_admin_deputy'] === 1 ? ' <span class="tag">補佐</span>' : '' ?></td>
        <td><?= h((string) $u['dept_name']) ?></td>
        <td><?= h((string) ($u['team_name'] ?? '')) ?></td>
        <td><?= h((string) $u['roles']) ?></td>
        <td><?= $u['totp_enabled_at'] !== null ? '設定済み' : '未設定' ?></td>
        <td><?= ($u['locked_until'] !== null && $u['locked_until'] > now()) ? 'ロック中' : '' ?></td>
        <td><a href="<?= h(url('/admin/user_edit.php?id=' . (int) $u['id'])) ?>">編集</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

<?php elseif ($tab === 'bulk'): /** @var array|null $bulkPlan org_csv_plan() の戻り値（取り込んだとき） */ ?>
  <p>毎年の組織改訂で、ユーザーの所属の課・班・役割（課長・確認者・担当の班・項目管理者）・有効をまとめて入れます。手順は「組織の改訂の手順」（docs/org_revision.md）。</p>
  <ol class="steps">
    <li>今の組織を出力する: <a class="button" href="<?= h(url('/admin/org.php?tab=bulk&csv=1')) ?>">今の組織を CSV に出力</a></li>
    <li>CSV を直す（動かない人はそのまま。CSV に載せない人は変わりません。新しい人は先に「ユーザーを追加」で登録します。無い班の名前は、その課に班を作ります）。</li>
    <li>直した CSV を取り込み、変わる内容を確かめてから「反映する」を押す。</li>
  </ol>
  <form method="post" action="<?= h(url('/admin/org.php?tab=bulk')) ?>" class="form" enctype="multipart/form-data">
    <?= form_hidden() ?>
    <input type="hidden" name="action" value="bulk_preview">
    <label for="org_csv">直した CSV（UTF-8、1 MB まで）</label>
    <input type="file" id="org_csv" name="org_csv" accept=".csv,text/csv">
    <button type="submit">取り込んで、変わる内容を確かめる</button>
  </form>
  <?php if ($bulkPlan !== null): ?>
    <h2>変わる内容</h2>
    <?php foreach ($bulkPlan['errorTextList'] as $bulkErrorText): ?><div class="flash flash-error" role="alert"><?= h_lines($bulkErrorText) ?></div><?php endforeach; ?>
    <?php if ($bulkPlan['newTeamList']): ?>
      <p>新しく作る班: <?php foreach ($bulkPlan['newTeamList'] as $newTeam): ?><span class="tag"><?= h($newTeam['departmentName']) ?> / <?= h($newTeam['teamName']) ?></span><?php endforeach; ?></p>
    <?php endif; ?>
    <?php if (!$bulkPlan['changeList']): ?>
      <p><?= h(msg('変わる人はいません。')) ?></p>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>ログイン ID</th><th>表示名</th><th>変わる内容</th></tr></thead>
        <tbody>
        <?php foreach ($bulkPlan['changeList'] as $bulkChange): ?>
          <tr><td><?= h($bulkChange['loginId']) ?></td><td><?= h($bulkChange['displayName']) ?></td><td><?= nl2br(h(implode("\n", $bulkChange['descriptionList']))) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <?php if (!$bulkPlan['errorTextList'] && $bulkPlan['changeList']): ?>
      <form method="post" action="<?= h(url('/admin/org.php?tab=bulk')) ?>" class="form" data-confirm="<?= h(msg('この内容で組織を反映します。よろしいですか？')) ?>">
        <?= form_hidden() ?>
        <input type="hidden" name="action" value="bulk_apply">
        <input type="hidden" name="plan_token" value="<?= h($bulkPlan['token']) ?>">
        <button type="submit" class="primary">すぐに反映する（<?= count($bulkPlan['changeList']) ?> 人）</button>
      </form>
      <form method="post" action="<?= h(url('/admin/org.php?tab=bulk')) ?>" class="filters org-schedule-form" data-confirm="<?= h(msg('この内容を、選んだ日に反映するよう予約します。よろしいですか？')) ?>">
        <?= form_hidden() ?>
        <input type="hidden" name="action" value="bulk_schedule">
        <input type="hidden" name="plan_token" value="<?= h($bulkPlan['token']) ?>">
        <label for="apply_on">予約して反映する日</label>
        <input id="apply_on" name="apply_on" type="date" min="<?= h(date('Y-m-d', strtotime('+1 day'))) ?>" required>
        <button type="submit">この日に反映するよう予約する</button>
      </form>
      <p class="note">年度内の組織改訂は、すぐに発令される場合と、事前に予告がある場合があります。予告があるときは予約しておくと、その日に反映されます（その日の最初の画面の表示で、その時点の組織に対してもう一度確かめてから反映します）。</p>
    <?php elseif ($bulkPlan['errorTextList']): ?>
      <p class="note"><?= h(msg('誤りを直してから、もう一度取り込んでください（反映はしていません）。')) ?></p>
    <?php endif; ?>
  <?php endif; ?>

  <?php /** @var array<int, array<string, mixed>> $scheduleList 組織の改訂の予約（新しい順） */ ?>
  <h2>予約した組織の改訂</h2>
  <?php if (!$scheduleList): ?>
    <p class="note">予約はありません。</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>反映する日</th><th>人数</th><th>状態</th><th>結果</th><th>予約した人</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($scheduleList as $scheduleRow): $scheduleStatusLabel = ['scheduled' => '予約中', 'applied' => '反映済み', 'failed' => '失敗', 'cancelled' => '取り消し'][$scheduleRow['status']] ?? ''; ?>
      <tr>
        <td><?= h(display_date((string) $scheduleRow['apply_on'])) ?></td>
        <td><?= (int) $scheduleRow['change_count'] ?> 人</td>
        <td><span class="tag<?= $scheduleRow['status'] === 'failed' ? ' tag-open' : ($scheduleRow['status'] === 'applied' ? ' tag-resolved' : '') ?>"><?= h($scheduleStatusLabel) ?></span></td>
        <td><?= h_lines((string) $scheduleRow['result_text']) ?></td>
        <td><?= h((string) ($scheduleRow['created_by_name'] ?? '')) ?></td>
        <td class="actions"><?php if ($scheduleRow['status'] === 'scheduled'): ?>
          <form method="post" action="<?= h(url('/admin/org.php?tab=bulk')) ?>" class="inline" data-confirm="<?= h(msg('この予約を取り消します。よろしいですか？')) ?>">
            <?= form_hidden() ?><input type="hidden" name="schedule_id" value="<?= (int) $scheduleRow['id'] ?>">
            <button type="submit" name="action" value="schedule_cancel" class="small danger">取り消す</button>
          </form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

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
