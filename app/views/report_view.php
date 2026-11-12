<?php
/** @var array $report */
/** @var array $owner */
/** @var bool $isOwner */
/** @var bool $isReviewer */
/** @var bool $isFinal */
/** @var bool $canRestricted */
/** @var array $fields */
/** @var array $values */
/** @var array $blocks */
/** @var array $reviewers */
/** @var array|null $myCheck */
/** @var array $changedKeys */
/** @var array $threads */
/** @var array $history */
/** @var bool $canComment */
$rid = (int) $report['id'];
$uid = (int) $user['id'];
$typeLabels = ['request' => '追加情報の依頼', 'confirm' => '認識の確認', 'note' => '連絡'];
$stateLabels = ['open' => '回答待ち', 'answered' => '回答あり', 'resolved' => '解決'];
$fieldLabel = [];
foreach ($fields as $f) {
    $fieldLabel[$f['id']] = $f['label'];
}

/** 1 つの対象（項目または報告全体）のコメント欄 */
$renderThreads = function (int $rpId, int $fid) use ($threads, $typeLabels, $stateLabels, $rid, $uid, $user, $isOwner, $isFinal, $canComment, $report): string {
    $key = $rpId . ':' . $fid;
    ob_start();
    foreach ($threads[$key] ?? [] as $t) {
        $hd = $t['head'];
        $canResolve = (int) $hd['user_id'] === $uid || $isFinal;
        ?>
        <div class="thread" id="comment-<?= (int) $hd['id'] ?>">
          <div class="comment">
            <span class="tag"><?= h($typeLabels[$hd['comment_type']] ?? '') ?></span>
            <?php if ($hd['thread_status'] !== null): ?><span class="tag tag-<?= h($hd['thread_status']) ?>"><?= h($stateLabels[$hd['thread_status']]) ?></span><?php endif; ?>
            <strong><?= h($hd['author']) ?></strong> <span class="note"><?= h(substr((string) $hd['created_at'], 5, 11)) ?></span>
            <div class="comment-text"><?= $hd['text'] === null ? '<span class="muted">（削除されました）</span>' : nl2br(h($hd['text'])) ?></div>
          </div>
          <?php foreach ($t['replies'] as $r): ?>
            <div class="comment reply" id="comment-<?= (int) $r['id'] ?>">
              <strong><?= h($r['author']) ?></strong> <span class="note"><?= h(substr((string) $r['created_at'], 5, 11)) ?></span>
              <div class="comment-text"><?= $r['text'] === null ? '<span class="muted">（削除されました）</span>' : nl2br(h($r['text'])) ?></div>
              <?php if ($r['text'] !== null && ((int) $r['user_id'] === $uid || is_admin($user))): ?>
                <form method="post" action="<?= h(url('/reports/act.php')) ?>" class="inline" data-confirm="このコメントを削除します。よろしいですか？">
                  <?= form_hidden() ?><input type="hidden" name="report_id" value="<?= $rid ?>">
                  <input type="hidden" name="comment_id" value="<?= (int) $r['id'] ?>">
                  <button type="submit" name="action" value="comment_delete" class="small">削除</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if ($report['status'] !== 'draft'): ?>
          <details class="reply-form">
            <summary>返信<?= $canResolve && $hd['thread_status'] !== null ? '・解決' : '' ?></summary>
            <form method="post" action="<?= h(url('/reports/act.php')) ?>" class="form">
              <?= form_hidden() ?><input type="hidden" name="report_id" value="<?= $rid ?>">
              <input type="hidden" name="parent_id" value="<?= (int) $hd['id'] ?>">
              <textarea name="comment" maxlength="1000" data-free-text="1" aria-label="返信"></textarea>
              <p class="field-warning" hidden>客先名や個人名が含まれていませんか</p>
              <button type="submit" name="action" value="reply">返信する</button>
              <?php if ($canResolve && $hd['thread_status'] === 'resolved'): ?>
                <button type="submit" name="action" value="reopen" formnovalidate>回答待ちに戻す</button>
              <?php elseif ($canResolve && $hd['thread_status'] !== null): ?>
                <button type="submit" name="action" value="resolve" formnovalidate>解決にする</button>
              <?php endif; ?>
              <?php if ($hd['text'] !== null && ((int) $hd['user_id'] === $uid || is_admin($user))): ?>
                <button type="submit" name="action" value="comment_delete" formnovalidate class="danger" data-confirm="このコメントを削除します。よろしいですか？">削除</button>
                <input type="hidden" name="comment_id" value="<?= (int) $hd['id'] ?>">
              <?php endif; ?>
            </form>
          </details>
          <?php endif; ?>
        </div>
        <?php
    }
    if ($canComment) {
        ?>
        <details class="comment-form">
          <summary>＋ コメント</summary>
          <form method="post" action="<?= h(url('/reports/act.php')) ?>" class="form">
            <?= form_hidden() ?><input type="hidden" name="report_id" value="<?= $rid ?>">
            <input type="hidden" name="field_id" value="<?= $fid ?>"><input type="hidden" name="report_project_id" value="<?= $rpId ?>">
            <select name="comment_type" aria-label="コメントの種類">
              <option value="request">追加情報の依頼（回答を求める）</option>
              <option value="confirm">認識の確認（回答を求める）</option>
              <option value="note">連絡（返答不要）</option>
            </select>
            <textarea name="comment" maxlength="1000" required data-free-text="1" aria-label="コメント"></textarea>
            <p class="field-warning" hidden>客先名や個人名が含まれていませんか</p>
            <button type="submit" name="action" value="comment">コメントする</button>
          </form>
        </details>
        <?php
    }
    return (string) ob_get_clean();
};

/** 1 つの項目の表示 */
$renderField = function (array $f, int $rpId) use ($values, $canRestricted, $changedKeys, $isOwner, $renderThreads, $report): string {
    $v = $values[$rpId][$f['id']] ?? ['value' => '', 'warning' => false];
    $hidden = $f['conf'] === 'restricted' && !$canRestricted;
    $purged = $report['free_text_purged_at'] !== null && form_is_free_text($f);
    $html = '<div class="view-field" id="f-' . $rpId . '-' . (int) $f['id'] . '"><div class="view-label">' . h($f['label']) . form_badges(array_merge($f, ['required' => false]));
    if (isset($changedKeys[$rpId . ':' . $f['id']])) {
        $html .= ' <span class="tag tag-changed">更新あり</span>';
    }
    if (!$isOwner && !$hidden && $v['warning']) {
        $html .= ' <span class="tag tag-warning">注意: 客先名の可能性</span>';
    }
    $html .= '</div><div class="view-value">';
    if ($hidden) {
        $html .= '<span class="muted">（限定のため表示しません）</span>';
    } elseif ($purged) {
        $html .= '<span class="muted">（保管期限を過ぎたため削除しました）</span>';
    } else {
        $html .= form_display_html($f, $v['value']);
    }
    $html .= '</div>';
    if (!$hidden) {
        $html .= $renderThreads($rpId, (int) $f['id']);
    }
    return $html . '</div>';
};
$blockFields = array_values(array_filter($fields, function ($f) { return $f['scope'] === 'project' && $f['type'] !== 'project'; }));
$reportFields = array_values(array_filter($fields, function ($f) { return $f['scope'] === 'report'; }));
?>
<section class="card">
  <p><a href="<?= h(url('/index.php')) ?>">← ホーム</a></p>
  <h1><?= h($owner['display_name']) ?>さん <?= h(period_label((string) $report['period'])) ?>
    <span class="tag"><?= h(report_status_label((string) $report['status'])) ?></span>
    <?php if ((int) $report['is_legacy'] === 1): ?><span class="tag">移行データ</span><?php endif; ?>
    <?php if ((int) $report['unchanged'] === 1): ?><span class="tag">先月から変更なし</span><?php endif; ?></h1>
  <?php if ($report['submitted_at'] !== null): ?><p class="note">提出 <?= h((string) $report['submitted_at']) ?></p><?php endif; ?>
  <?php if ($isOwner && $report['status'] !== 'confirmed' && (int) $report['is_legacy'] === 0): ?>
    <a class="button" href="<?= h(url('/reports/edit.php?period=' . rawurlencode((string) $report['period']))) ?>">編集する</a>
  <?php endif; ?>

  <?php if ($report['status'] !== 'draft' && $reviewers): ?>
  <h2>チェック</h2>
  <ul class="list compact">
    <?php foreach ($reviewers as $r):
        $st = $r['checked_revision'] === null ? '未チェック' : ((int) $r['checked_revision'] < (int) $report['revision'] ? '更新あり（再確認待ち）' : 'チェック済 ' . substr((string) $r['checked_at'], 5, 5)); ?>
      <li><span><?= (int) $r['is_final'] === 1 ? '課長 ' : '' ?><?= h($r['display_name']) ?></span><span class="<?= $r['checked_revision'] === null ? 'muted' : '' ?>"><?= h($st) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<?php foreach ($blocks as $i => $b): ?>
<section class="card">
  <h2>案件 <?= $i + 1 ?>: <?= h($b['code']) ?><?= $b['alias'] !== '' ? ' <span class="note">' . h($b['alias']) . '</span>' : '' ?></h2>
  <?php foreach ($blockFields as $f): ?><?= $renderField($f, (int) $b['id']) ?><?php endforeach; ?>
</section>
<?php endforeach; ?>

<section class="card">
  <h2>報告全体</h2>
  <?php foreach ($reportFields as $f): ?><?= $renderField($f, 0) ?><?php endforeach; ?>
  <div class="view-field" id="f-0-0"><div class="view-label">報告全体へのコメント</div><?= $renderThreads(0, 0) ?></div>
</section>

<?php if ($history): ?>
<section class="card">
  <details>
    <summary>変更履歴（<?= count($history) ?>件）</summary>
    <ul class="list">
      <?php foreach ($history as $hrow): ?>
        <li><span><?= h($fieldLabel[(int) $hrow['field_id']] ?? '') ?>: <?= h(mb_strimwidth((string) ($hrow['old_value'] ?? '?'), 0, 40, '…')) ?> → <?= h(mb_strimwidth((string) ($hrow['new_value'] ?? '?'), 0, 40, '…')) ?></span>
          <span class="note"><?= h($hrow['author']) ?> <?= h(substr((string) $hrow['created_at'], 5, 11)) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </details>
</section>
<?php endif; ?>

<?php if (!$isOwner && $report['status'] !== 'draft'): ?>
<div class="sticky-actions">
  <?php if ($isReviewer): ?>
    <form method="post" action="<?= h(url('/reports/act.php')) ?>" class="inline">
      <?= form_hidden() ?><input type="hidden" name="report_id" value="<?= $rid ?>">
      <input type="hidden" name="seq" value="<?= $next ? '1' : '0' ?>">
      <button type="submit" name="action" value="check" class="primary">
        <?= ($myCheck !== null && (int) $myCheck['checked_revision'] >= (int) $report['revision']) ? 'チェック済み（もう一度付ける）' : 'チェック済みにする' ?></button>
    </form>
  <?php endif; ?>
  <?php if ($isFinal && $report['status'] === 'submitted'): ?>
    <form method="post" action="<?= h(url('/reports/act.php')) ?>" class="inline" data-confirm="確認を完了にします。完了すると本人は変更できなくなります。よろしいですか？">
      <?= form_hidden() ?><input type="hidden" name="report_id" value="<?= $rid ?>">
      <button type="submit" name="action" value="confirm">確認を完了にする</button>
    </form>
  <?php elseif ($isFinal && $report['status'] === 'confirmed'): ?>
    <form method="post" action="<?= h(url('/reports/act.php')) ?>" class="inline" data-confirm="確認完了を取り消して提出済みに戻します。よろしいですか？">
      <?= form_hidden() ?><input type="hidden" name="report_id" value="<?= $rid ?>">
      <button type="submit" name="action" value="unconfirm">確認完了を取り消す</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
