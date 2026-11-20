<?php
/** @var array<string, mixed> $sheet */
/** @var string $sheetPath */
/** @var string $ownerName */
/** @var string $viewerRole owner（本人）・boss（課長）・deputy（課長代理） */
/** @var array<string, mixed> $kindDefinition */
/** @var array<string, mixed> $scores */
/** @var array<int, array<string, mixed>> $comments */
/** @var array<int, array<string, mixed>> $meetings */
/** @var array<int, array{user_id: int, name: string, role_label: string}> $meetingReviewers 面談の結果の欄を持つ人（課長・課長代理） */
/** @var array<int, array<string, mixed>> $history */
/** @var string[] $errorTextList */
/** @var bool $isMobile */
$sheetData = $sheet['data'];
$isOwner = $viewerRole === 'owner';
$isBoss = $viewerRole === 'boss';
$isNewSheet = $sheet['id'] === null;
$formatScore = function (float $scoreValue): string { return rtrim(rtrim(number_format($scoreValue, 1), '0'), '.'); };
// 文の欄: 書ける人には入力欄、ほかの人には文だけ
$textField = function (string $fieldName, string $fieldValue, bool $isEditable, int $rowCount, string $hintText = '') {
    if (!$isEditable) {
        return '<div class="goal-readonly">' . ($fieldValue !== '' ? h_lines($fieldValue) : '<span class="note">（未記入）</span>') . '</div>';
    }
    return '<textarea name="' . h($fieldName) . '" rows="' . $rowCount . '">' . h($fieldValue) . '</textarea>' . ($hintText !== '' ? '<p class="note">' . h($hintText) . '</p>' : '');
};
// 選ぶ欄: 書ける人には選択肢、ほかの人には値だけ
$choiceField = function (string $fieldName, string $fieldValue, array $optionLabelByValue, bool $isEditable, string $ariaLabel) {
    if (!$isEditable) {
        return '<span class="goal-choice-value">' . ($fieldValue !== '' ? h($optionLabelByValue[$fieldValue] ?? $fieldValue) : '－') . '</span>';
    }
    $optionHtml = '<option value=""></option>';
    foreach ($optionLabelByValue as $optionValue => $optionLabel) {
        $optionHtml .= '<option value="' . h((string) $optionValue) . '"' . ((string) $optionValue === $fieldValue ? ' selected' : '') . '>' . h($optionLabel) . '</option>';
    }
    return '<select name="' . h($fieldName) . '" aria-label="' . h($ariaLabel) . '">' . $optionHtml . '</select>';
};
$gradeOptions = array_combine(array_keys(GOAL_GRADE_FACTORS), array_keys(GOAL_GRADE_FACTORS));
$difficultyOptions = [];
foreach (GOAL_DIFFICULTY_POINTS as $difficultyLabel => $difficultyPoints) {
    $difficultyOptions[$difficultyLabel] = $difficultyLabel . '（' . $difficultyPoints . '）';
}
$competencyOptions = [];
foreach (GOAL_COMPETENCY_SCORES as $scoreNumber => $scoreLabel) {
    $competencyOptions[(string) $scoreNumber] = $scoreNumber . '：' . $scoreLabel;
}
$roleNoteByViewerRole = [
    'owner'  => msg('目標・報告・本人評価を書いて「保存」を押します。配点と上長評価は課長が入れます。'),
    'boss'   => msg('課長として、配点・上長評価・総合評価を入れて「評価を保存」を押します。本人が書いた欄は直せません（直してほしいことは、コメントか面談で伝えてください）。'),
    'deputy' => msg('課長代理として、見てコメントできます（評価は課長が入れます）。面談をしたら、記録を残してください。'),
];
?>
<section class="card goal-sheet">
  <p><a href="<?= h(url('/goals/index.php')) ?>">← 目標管理シート</a></p>
  <h1><?= (int) $sheet['fiscal_year'] ?> 年度 目標管理シート<?= $isOwner ? '' : '（' . h($ownerName) . ' さん）' ?></h1>
  <p class="note"><?= (int) $sheet['fiscal_year'] ?>/10/1〜<?= (int) $sheet['fiscal_year'] + 1 ?>/9/30・様式: <?= h($kindDefinition['label']) ?></p>
  <p class="flash flash-info"><?= h_lines($roleNoteByViewerRole[$viewerRole]) ?></p>
  <?php if ($sheet['unreadable']): ?><div class="flash flash-error" role="alert"><?= h(msg('このシートは読めません（暗号の鍵が変わった可能性があります）。システム管理者に連絡してください。')) ?></div><?php endif; ?>
  <?php foreach ($errorTextList as $errorText): ?><div class="flash flash-error" role="alert"><?= h_lines($errorText) ?></div><?php endforeach; ?>
  <?php if (!$isNewSheet && !$isMobile): ?>
    <p><a class="button" href="<?= h(url('/goals/download.php?id=' . $sheet['id'])) ?>">会社の様式の Excel を受け取る（.xlsx）</a></p>
  <?php endif; ?>
  <?php $selectableKindList = goal_selectable_kinds($user); ?>
  <?php if ($isNewSheet && count($selectableKindList) > 1): ?>
    <form method="get" action="<?= h(url('/goals/edit.php')) ?>" class="filters">
      <input type="hidden" name="year" value="<?= (int) $sheet['fiscal_year'] ?>">
      <label for="goal-kind">様式</label>
      <select id="goal-kind" name="kind">
        <?php foreach ($selectableKindList as $kindCode): ?><option value="<?= h($kindCode) ?>" <?= $kindCode === $sheet['kind'] ? 'selected' : '' ?>><?= h(GOAL_TEMPLATE_KINDS[$kindCode]['label']) ?></option><?php endforeach; ?>
      </select>
      <button type="submit">様式を切り替える</button>
      <span class="note"><?= h(msg('課長以上の様式を選んでいます。営業・間接部門の人は切り替えてください（コンピテンシーの項目が変わります）。')) ?></span>
    </form>
  <?php elseif ($isNewSheet): ?>
    <p class="note"><?= h(msg('様式は役職で決まります（{kind}）。', ['kind' => GOAL_TEMPLATE_KINDS[$sheet['kind']]['label']])) ?></p>
  <?php endif; ?>

  <form method="post" action="<?= h(url($sheetPath)) ?>" class="form goal-form"<?= ($isOwner || $isBoss) ? ' data-leave-warn="1"' : '' ?>>
    <?= form_hidden() ?>
    <?php if (!$isNewSheet): ?><input type="hidden" name="sheet_id" value="<?= (int) $sheet['id'] ?>"><?php else: ?>
      <input type="hidden" name="fiscal_year" value="<?= (int) $sheet['fiscal_year'] ?>"><input type="hidden" name="kind" value="<?= h($sheet['kind']) ?>"><?php endif; ?>

    <fieldset class="goal-head">
      <legend>本人の情報</legend>
      <div class="goal-head-grid">
        <?php foreach (['department_name' => '所属', 'full_name' => '氏名', 'position' => '職位'] as $headKey => $headLabel): ?>
          <div>
            <label<?= $isOwner ? ' for="' . h($headKey) . '"' : '' ?>><?= h($headLabel) ?></label>
            <?php if ($isOwner): ?><input id="<?= h($headKey) ?>" name="<?= h($headKey) ?>" type="text" maxlength="40" value="<?= h($sheetData[$headKey]) ?>"><?php else: ?><div class="goal-readonly"><?= h($sheetData[$headKey]) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (in_array($sheet['kind'], GOAL_BUSINESS_TARGET_KINDS, true)): ?>
        <div class="goal-head-grid">
          <?php foreach (['business_plan' => '業績目標の計画（千円）', 'business_actual' => '業績目標の実績（千円）'] as $businessKey => $businessLabel): ?>
            <div>
              <label<?= $isOwner ? ' for="' . h($businessKey) . '"' : '' ?>><?= h($businessLabel) ?></label>
              <?php if ($isOwner): ?><input id="<?= h($businessKey) ?>" name="<?= h($businessKey) ?>" type="text" inputmode="decimal" maxlength="10" value="<?= h($sheetData[$businessKey]) ?>"><?php else: ?><div class="goal-readonly"><?= h($sheetData[$businessKey]) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="note"><?= h(msg('達成率（実績 ÷ 計画）と評点（120% を超える：5／110% 以上：4／100% 以上：3／95% 以上：2／85% 以上：1／それ未満：0）は、様式の式で計算します。')) ?></p>
      <?php endif; ?>
    </fieldset>

    <?php foreach ($sheetData['goals'] as $goalIndex => $goalData): $namePrefix = 'goals[' . $goalIndex . ']'; $goalNumber = $goalIndex + 1; ?>
      <fieldset class="goal-block" id="goal-<?= $goalNumber ?>">
        <legend>目標<?= $goalNumber ?></legend>
        <label<?= $isOwner ? ' for="goal-theme-' . $goalNumber . '"' : '' ?>>テーマ</label>
        <?php if ($isOwner): ?><input id="goal-theme-<?= $goalNumber ?>" name="<?= h($namePrefix) ?>[theme]" type="text" maxlength="<?= (int) GOAL_THEME_MAX ?>" value="<?= h($goalData['theme']) ?>"><?php else: ?><div class="goal-readonly"><?= h($goalData['theme']) ?></div><?php endif; ?>

        <div class="goal-pair">
          <?php foreach (['goal', 'progress'] as $fieldKey): $fieldSetting = GOAL_TEXT_FIELDS[$fieldKey]; ?>
            <div><span class="label-text"><?= h($fieldSetting[0]) ?></span>
              <?= $textField($namePrefix . '[' . $fieldKey . ']', $goalData[$fieldKey], $isOwner, $fieldSetting[1], msg('様式の欄: {lines} 行・1 行 {width} 字ほど', ['lines' => $fieldSetting[1], 'width' => $fieldSetting[2]])) ?></div>
          <?php endforeach; ?>
        </div>
        <p class="goal-grade-line"><span class="label-text">配点（設定目標が職位に対して。課長が入れる）</span>
          <?= $choiceField($namePrefix . '[difficulty]', $goalData['difficulty'], $difficultyOptions, $isBoss, '目標' . $goalNumber . ' 配点') ?></p>

        <?php foreach (['mid' => ['mid_report', 'mid_plan', '中間'], 'final' => ['final_report', 'final_review', '最終']] as $phaseKey => $phaseFields): ?>
          <div class="goal-pair">
            <?php foreach ([$phaseFields[0], $phaseFields[1]] as $fieldKey): $fieldSetting = GOAL_TEXT_FIELDS[$fieldKey]; ?>
              <div><span class="label-text"><?= h($fieldSetting[0]) ?></span>
                <?= $textField($namePrefix . '[' . $fieldKey . ']', $goalData[$fieldKey], $isOwner, $fieldSetting[1], msg('様式の欄: {lines} 行・1 行 {width} 字ほど', ['lines' => $fieldSetting[1], 'width' => $fieldSetting[2]])) ?></div>
            <?php endforeach; ?>
          </div>
          <p class="goal-grade-line">
            <span class="label-text"><?= h($phaseFields[2]) ?>の本人評価</span> <?= $choiceField($namePrefix . '[' . $phaseKey . '_self]', $goalData[$phaseKey . '_self'], $gradeOptions, $isOwner, '目標' . $goalNumber . ' ' . $phaseFields[2] . 'の本人評価') ?>
            <span class="label-text"><?= h($phaseFields[2]) ?>の上長評価（課長）</span> <?= $choiceField($namePrefix . '[' . $phaseKey . '_boss]', $goalData[$phaseKey . '_boss'], $gradeOptions, $isBoss, '目標' . $goalNumber . ' ' . $phaseFields[2] . 'の上長評価') ?>
          </p>
        <?php endforeach; ?>
      </fieldset>
    <?php endforeach; ?>

    <fieldset class="goal-scores">
      <legend>評点（様式と同じ計算。評価 A=1.2・B=1.0・C1=0.7・C2=0.6・C3=0.5・D=0.3・E=0.1 × 配点）</legend>
      <div class="table-wrap">
      <table class="table goal-score-table">
        <thead><tr><th></th><th>目標1</th><th>目標2</th><th>総合（平均）</th></tr></thead>
        <tbody>
          <?php foreach (['mid' => '中間', 'final' => '最終'] as $phaseKey => $phaseLabel): ?>
            <?php foreach (['self' => '本人評価', 'boss' => '上長評価'] as $whoKey => $whoLabel): ?>
              <tr><th><?= h($phaseLabel . '・' . $whoLabel) ?></th>
                <td class="number"><?= h($formatScore($scores[$phaseKey][$whoKey][0])) ?></td>
                <td class="number"><?= h($formatScore($scores[$phaseKey][$whoKey][1])) ?></td>
                <td class="number"><?= h($formatScore($scores[$phaseKey][$whoKey . '_average'])) ?></td></tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </fieldset>

    <fieldset class="goal-competency" id="competency">
      <legend>コンピテンシー評価（行動特性評価。5：満足できる結果 〜 0：評価に値せず）</legend>
      <p><a href="<?= h(url('/goals/criteria.php')) ?>" target="_blank" rel="noopener" class="goal-criteria-link">コンピテンシー評価基準を見る（別のタブで開く）</a></p>
      <div class="table-wrap">
      <table class="table goal-competency-table">
        <thead><tr><th>大分類</th><th>中分類</th><th>行動指針</th><th>本人評価</th><th>上長評価（課長）</th></tr></thead>
        <tbody>
          <?php foreach ($kindDefinition['items'] as $itemIndex => $itemDefinition): ?>
            <tr>
              <td><?= h($itemDefinition['major']) ?></td>
              <td><?= h($itemDefinition['minor']) ?></td>
              <td class="goal-guide"><?= h($itemDefinition['guide']) ?></td>
              <td><?= $choiceField('competency_self[' . $itemIndex . ']', $sheetData['competency_self'][$itemIndex], $competencyOptions, $isOwner, $itemDefinition['minor'] . ' 本人評価') ?></td>
              <td><?= $choiceField('competency_boss[' . $itemIndex . ']', $sheetData['competency_boss'][$itemIndex], $competencyOptions, $isBoss, $itemDefinition['minor'] . ' 上長評価') ?></td>
            </tr>
          <?php endforeach; ?>
          <tr><th colspan="3">評点（100 点換算）</th><td class="number"><?= h($formatScore($scores['competency_self'])) ?></td><td class="number"><?= h($formatScore($scores['competency_boss'])) ?></td></tr>
        </tbody>
      </table>
      </div>
    </fieldset>

    <p class="goal-grade-line"><span class="label-text">総合評価（課長が入れる）</span>
      <?= $choiceField('overall_boss', $sheetData['overall_boss'], $gradeOptions, $isBoss, '総合評価') ?></p>

    <?php if ($isOwner): ?>
      <div class="form-actions"><button type="submit" name="action" value="save_owner" class="primary">保存</button></div>
    <?php elseif ($isBoss): ?>
      <div class="form-actions"><button type="submit" name="action" value="save_boss" class="primary">評価を保存</button></div>
    <?php endif; ?>
  </form>
</section>

<?php if (!$isNewSheet): ?>
<section class="card goal-comments" id="comments">
  <h2>コメント</h2>
  <p class="note"><?= h_lines(msg('本人・課長・課長代理が書けます。書いたコメントは、あとから直したり消したりできません（監査のため）。直したいことは、新しく書いてください。')) ?></p>
  <?php if (!$comments): ?><p class="note"><?= h(msg('コメントはまだありません。')) ?></p><?php endif; ?>
  <?php foreach ($comments as $commentItem): ?>
    <div class="goal-comment">
      <p class="goal-comment-head"><strong><?= h($commentItem['user_name']) ?></strong> <span class="note"><?= h(display_datetime($commentItem['created_at'])) ?></span></p>
      <div><?= h_lines($commentItem['body']) ?></div>
      <?php foreach ($commentItem['replies'] as $replyItem): ?>
        <div class="goal-reply">
          <p class="goal-comment-head"><strong><?= h($replyItem['user_name']) ?></strong> <span class="note"><?= h(display_datetime($replyItem['created_at'])) ?></span></p>
          <div><?= h_lines($replyItem['body']) ?></div>
        </div>
      <?php endforeach; ?>
      <details class="goal-reply-form">
        <summary>返信</summary>
        <form method="post" action="<?= h(url($sheetPath)) ?>" class="form">
          <?= form_hidden() ?>
          <input type="hidden" name="sheet_id" value="<?= (int) $sheet['id'] ?>">
          <input type="hidden" name="parent_id" value="<?= (int) $commentItem['id'] ?>">
          <textarea name="body" maxlength="1000" rows="3" aria-label="返信"></textarea>
          <button type="submit" name="action" value="reply">返信する</button>
        </form>
      </details>
    </div>
  <?php endforeach; ?>
  <form method="post" action="<?= h(url($sheetPath)) ?>" class="form">
    <?= form_hidden() ?>
    <input type="hidden" name="sheet_id" value="<?= (int) $sheet['id'] ?>">
    <label for="goal-comment-body">コメント（1,000 文字まで）</label>
    <textarea id="goal-comment-body" name="body" maxlength="1000" rows="3"></textarea>
    <button type="submit" name="action" value="comment">コメントする</button>
  </form>
</section>

<section class="card goal-meetings" id="meetings">
  <h2>面談の結果</h2>
  <p class="note"><?= h_lines(msg('個別の面談の結果（直すこと・確かめたこと）を、課長と課長代理が、それぞれ自分の欄に残します。課長代理の面談の結果は、なくてもかまいません（任意）。本人も読めます。あとから直したり消したりできません。')) ?></p>
  <?php
  $reviewerUserIdList = array_column($meetingReviewers, 'user_id');
  $formerMeetingList = array_values(array_filter($meetings, function (array $meetingItem) use ($reviewerUserIdList): bool { return !in_array($meetingItem['created_by'], $reviewerUserIdList, true); }));
  ?>
  <?php if (!$meetingReviewers): ?><p class="note"><?= h(msg('このシートには、面談の結果を書く人（課長・課長代理）がいません。')) ?></p><?php endif; ?>
  <?php foreach ($meetingReviewers as $reviewerItem): $reviewerMeetingList = array_values(array_filter($meetings, function (array $meetingItem) use ($reviewerItem): bool { return $meetingItem['created_by'] === $reviewerItem['user_id']; })); ?>
    <div class="goal-meeting-block" data-reviewer-id="<?= (int) $reviewerItem['user_id'] ?>">
      <h3><?= h($reviewerItem['role_label']) ?> <?= h($reviewerItem['name']) ?> の面談の結果<?= $reviewerItem['role_label'] === '課長代理' ? '（任意）' : '' ?></h3>
      <?php if (!$reviewerMeetingList): ?><p class="note"><?= h($reviewerItem['role_label'] === '課長代理' ? msg('まだありません（課長代理の面談の結果は、なくてもかまいません）。') : msg('まだありません。')) ?></p><?php endif; ?>
      <?php foreach ($reviewerMeetingList as $meetingItem): ?>
        <div class="goal-comment">
          <p class="goal-comment-head"><strong><?= h($meetingItem['held_on']) ?> の面談</strong> <span class="note"><?= h(display_datetime($meetingItem['created_at'])) ?> に記録</span></p>
          <div><?= h_lines($meetingItem['note']) ?></div>
        </div>
      <?php endforeach; ?>
      <?php if ($reviewerItem['user_id'] === (int) $user['id']): ?>
        <form method="post" action="<?= h(url($sheetPath)) ?>" class="form">
          <?= form_hidden() ?>
          <input type="hidden" name="sheet_id" value="<?= (int) $sheet['id'] ?>">
          <label for="goal-meeting-date">面談の日</label>
          <input id="goal-meeting-date" name="held_on" type="date" value="<?= h(date('Y-m-d')) ?>" max="<?= h(date('Y-m-d')) ?>">
          <label for="goal-meeting-note">面談の結果（2,000 文字まで）</label>
          <textarea id="goal-meeting-note" name="note" maxlength="2000" rows="4"></textarea>
          <button type="submit" name="action" value="meeting">面談の結果を残す</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($formerMeetingList): ?>
    <div class="goal-meeting-block">
      <h3>以前の担当の面談の結果</h3>
      <?php foreach ($formerMeetingList as $meetingItem): ?>
        <div class="goal-comment">
          <p class="goal-comment-head"><strong><?= h($meetingItem['held_on']) ?> の面談</strong> <span class="note"><?= h($meetingItem['user_name']) ?>（<?= h(display_datetime($meetingItem['created_at'])) ?> に記録）</span></p>
          <div><?= h_lines($meetingItem['note']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="card goal-history">
  <details>
    <summary>更新の履歴（<?= count($history) ?> 件）</summary>
    <ul>
      <?php foreach ($history as $historyRow): ?>
        <li><?= h(display_datetime((string) $historyRow['created_at'])) ?>　<?= h((string) $historyRow['display_name']) ?>　<?= h(GOAL_HISTORY_LABELS[$historyRow['action']] ?? (string) $historyRow['action']) ?></li>
      <?php endforeach; ?>
    </ul>
  </details>
</section>
<?php endif; ?>
