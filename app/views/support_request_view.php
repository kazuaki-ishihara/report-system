<?php
/** @var array<string, mixed> $requestRow 要望・依頼の行 */
/** @var string $titleText 件名（復号したもの） */
/** @var string $bodyText 内容（復号したもの） */
/** @var array{display_name: string, login_id: string, department_name: ?string}|null $requesterRow 出した人 */
/** @var array<int, array<string, mixed>> $messageList やり取り（body_text は復号した本文） */
/** @var bool $isStaff 管理者・補佐か */
/** @var bool $isRequester 出した本人か */
/** @var bool $isActive 続いているか（未受付〜リリース待ち） */
/** @var string[] $errorTextList */
/** @var string $typedReplyText */
/** @var string $typedStatusCode */
/** @var int[] $mergedRequestIdList この要望にまとめた要望の番号（管理者側だけ） */
/** @var array<int, array{id: int|string, kind: string, body: string}> $replyTemplateList 返信の定型文（管理者側だけ。この要望の種類とすべての種類） */
$requestId = (int) $requestRow['id'];
$selfUrl = url('/requests/view.php?id=' . $requestId);
?>
<section class="card">
  <p><a href="<?= h(url('/requests/index.php')) ?>">← 要望・依頼の一覧</a></p>
  <h1>#<?= $requestId ?> <?= h($titleText) ?></h1>
  <p>
    <span class="tag support-status-<?= h((string) $requestRow['status']) ?>"><?= h(SUPPORT_REQUEST_STATUS_LABELS[$requestRow['status']] ?? '') ?></span>
    <span class="tag"><?= h(SUPPORT_REQUEST_KIND_LABELS[$requestRow['kind']] ?? '') ?></span>
    <?php if ($requestRow['urgency'] === 'urgent'): ?><span class="tag tag-open">急ぎ</span><?php endif; ?>
  </p>
  <ul class="list compact">
    <li><span>出した人</span><span><?= h((string) ($requesterRow['display_name'] ?? '')) ?><?= ($requesterRow['department_name'] ?? null) !== null ? '（' . h((string) $requesterRow['department_name']) . '）' : '' ?></span></li>
    <li><span>関係する画面</span><span><?= h(SUPPORT_REQUEST_SCREEN_LABELS[$requestRow['screen_code']] ?? (string) $requestRow['screen_code']) ?></span></li>
    <?php if ($requestRow['source_path'] !== ''): ?><li><span>開いていた画面</span><span><a href="<?= h((string) $requestRow['source_path']) ?>"><?= h((string) $requestRow['source_path']) ?></a></span></li><?php endif; ?>
    <?php if ($requestRow['client_text'] !== ''): ?><li><span>端末</span><span><?= h((string) $requestRow['client_text']) ?></span></li><?php endif; ?>
    <li><span>出した日時</span><span><?= h(display_datetime($requestRow['created_at'])) ?></span></li>
    <?php if ((string) $requestRow['plan_text'] !== ''): ?><li><span>対応の予定</span><span><strong><?= h((string) $requestRow['plan_text']) ?></strong></span></li><?php endif; ?>
    <?php if ($requestRow['merged_into_id'] !== null): ?><li><span>まとめた先</span><span><?= $isStaff ? '<a href="' . h(url('/requests/view.php?id=' . (int) $requestRow['merged_into_id'])) . '">#' . (int) $requestRow['merged_into_id'] . '</a>' : '#' . (int) $requestRow['merged_into_id'] . '（同じ内容の要望。対応の結果はここにも届きます）' ?></span></li><?php endif; ?>
    <?php if ($isStaff && $mergedRequestIdList): ?><li><span>まとめた要望</span><span><?php foreach ($mergedRequestIdList as $mergedRequestId): ?><a href="<?= h(url('/requests/view.php?id=' . $mergedRequestId)) ?>">#<?= (int) $mergedRequestId ?></a> <?php endforeach; ?><span class="note">（返信はこれらにも届きます）</span></span></li><?php endif; ?>
    <?php if ($requestRow['closed_at'] !== null): ?><li><span>閉じた日時</span><span><?= h(display_datetime($requestRow['closed_at'])) ?></span></li><?php endif; ?>
  </ul>
  <?php if ($isStaff && (int) $requestRow['has_warning'] === 1): ?>
    <p class="field-warning">客先名や個人名らしい言葉が含まれています。必要なら、出した人に書き直しを頼んでください。</p>
  <?php endif; ?>
  <h2>内容</h2>
  <div class="support-request-body"><?= h_lines($bodyText) ?></div>
</section>

<section class="card">
  <h2>やり取り</h2>
  <?php if (!$messageList): ?>
    <p class="note"><?= $isStaff ? 'まだ返信していません。' : 'システム管理者からの返信は、ここに届きます。' ?></p>
  <?php else: ?>
  <div class="thread support-thread">
    <?php foreach ($messageList as $messageRow): ?>
      <div class="comment<?= (int) $messageRow['is_staff'] === 1 ? ' support-message-staff' : '' ?>">
        <div>
          <strong><?= (int) $messageRow['is_staff'] === 1 ? 'システム管理者' : h((string) $messageRow['display_name']) ?></strong>
          <?php if ((int) $messageRow['is_staff'] === 1): ?><span class="note">（<?= h((string) $messageRow['display_name']) ?>）</span><?php endif; ?>
          <span class="note"><?= h(display_datetime($messageRow['created_at'])) ?></span>
          <?php if ($messageRow['status_change'] !== null): ?><span class="tag support-status-<?= h((string) $messageRow['status_change']) ?>">→ <?= h(SUPPORT_REQUEST_STATUS_LABELS[$messageRow['status_change']] ?? '') ?></span><?php endif; ?>
        </div>
        <?php if ($messageRow['body_text'] !== ''): ?><div class="comment-text"><?= h_lines((string) $messageRow['body_text']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php foreach ($errorTextList as $errorText): ?><div class="flash flash-error" role="alert"><?= h_lines($errorText) ?></div><?php endforeach; ?>

  <?php if ($isStaff && $requestRow['status'] !== 'withdrawn'): ?>
    <form method="post" action="<?= h($selfUrl) ?>" class="form support-staff-form">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="staff_reply">
      <?php if ($replyTemplateList): ?>
        <label for="staff_reply_template">返信の定型文</label>
        <select id="staff_reply_template" class="support-reply-template-select" data-target="staff_reply_body">
          <option value="">（選ぶと返信の欄に入ります）</option>
          <?php foreach ($replyTemplateList as $replyTemplate): ?><option value="<?= h((string) $replyTemplate['body']) ?>"><?= h(mb_strimwidth((string) $replyTemplate['body'], 0, 60, '…', 'UTF-8')) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
      <label for="staff_reply_body">出した人への返信（対応した内容・検討の結果）</label>
      <textarea id="staff_reply_body" name="body" rows="5" maxlength="<?= SUPPORT_REQUEST_BODY_MAX_LENGTH ?>"><?= h($typedReplyText) ?></textarea>
      <p class="note">状態のボタンを押すと、状態を変えて返信します。返信を空のまま押すと、定型の返信を送ります（見送りは検討の結果を書いてください）。</p>
      <div class="support-status-buttons" role="group" aria-label="状態を変えて返信する">
        <?php foreach (SUPPORT_REQUEST_STAFF_STATUSES as $statusCode): $isCurrentStatus = $statusCode === $requestRow['status']; ?>
          <button type="submit" name="status" value="<?= h($statusCode) ?>" class="small support-status-<?= h($statusCode) ?><?= $isCurrentStatus ? ' is-current' : '' ?>"
                  <?= $isCurrentStatus ? 'disabled aria-current="true"' : '' ?>
                  title="<?= h($statusCode === 'declined' ? '検討の結果を書いてから押してください' : support_request_default_reply_text($statusCode)) ?>"><?= h(SUPPORT_REQUEST_STATUS_LABELS[$statusCode]) ?><?= $isCurrentStatus ? '（今）' : '' ?></button>
        <?php endforeach; ?>
      </div>
      <button type="submit" name="status" value="" class="primary">返信だけ送る（状態は変えない）</button>
    </form>
  <?php endif; ?>
  <?php if ($isStaff && $requestRow['status'] !== 'withdrawn'): ?>
    <details class="support-staff-extra">
      <summary>対応の予定・同じ内容の要望にまとめる・お知らせ</summary>
      <form method="post" action="<?= h($selfUrl) ?>" class="filters">
        <?= form_hidden() ?>
        <label for="plan_text">対応の予定（出した人にも見えます）</label>
        <input id="plan_text" name="plan_text" type="text" maxlength="<?= SUPPORT_REQUEST_PLAN_MAX_LENGTH ?>" value="<?= h((string) $requestRow['plan_text']) ?>" placeholder="例: 2026年12月にリリース予定">
        <button type="submit" name="action" value="plan_save" class="small">予定を保存</button>
      </form>
      <?php if (in_array($requestRow['status'], SUPPORT_REQUEST_ACTIVE_STATUSES, true) && $requestRow['merged_into_id'] === null): ?>
      <form method="post" action="<?= h($selfUrl) ?>" class="filters" data-confirm="<?= h(msg('この要望を、入れた番号の要望にまとめて閉じます。よろしいですか？')) ?>">
        <?= form_hidden() ?>
        <label for="merge_into">同じ内容の要望の番号</label>
        <input id="merge_into" name="merge_into" type="text" inputmode="numeric" maxlength="10" placeholder="例: 12">
        <button type="submit" name="action" value="merge" class="small">この要望をまとめる</button>
      </form>
      <?php endif; ?>
      <p class="note">
        <?php if (in_array($requestRow['status'], ['released', 'done'], true)): ?><a href="<?= h(url('/admin/notices.php?request=' . $requestId)) ?>">全員への更新のお知らせを書く</a><?php endif; ?>
        <?php if ($requestRow['kind'] === 'question' && !in_array($requestRow['status'], SUPPORT_REQUEST_ACTIVE_STATUSES, true)): ?> <a href="<?= h(url('/faq.php?request=' . $requestId . '#faq-form')) ?>">よくある質問に載せる</a><?php endif; ?>
      </p>
    </details>
  <?php endif; ?>
  <?php if ($isStaff): ?>
  <?php elseif ($isRequester && $isActive): ?>
    <form method="post" action="<?= h($selfUrl) ?>" class="form">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="reply">
      <label for="reply_body">返信（補足・追加の情報）</label>
      <textarea id="reply_body" name="body" rows="4" maxlength="<?= SUPPORT_REQUEST_BODY_MAX_LENGTH ?>" required><?= h($typedReplyText) ?></textarea>
      <button type="submit" class="primary">返信する</button>
    </form>
  <?php elseif ($isRequester): ?>
    <p class="note">この要望・依頼は閉じています。続きがあるときは、新しく出してください。</p>
  <?php endif; ?>

  <?php if ($isRequester && $requestRow['status'] === 'open'): ?>
    <details class="support-withdraw">
      <summary>取り下げる</summary>
      <form method="post" action="<?= h($selfUrl) ?>" class="form" data-confirm="<?= h(msg('この要望・依頼を取り下げます。よろしいですか？')) ?>">
        <?= form_hidden() ?>
        <input type="hidden" name="action" value="withdraw">
        <label for="withdraw_body">取り下げの理由（任意）</label>
        <textarea id="withdraw_body" name="body" rows="2" maxlength="<?= SUPPORT_REQUEST_BODY_MAX_LENGTH ?>"></textarea>
        <button type="submit" class="danger">取り下げる</button>
      </form>
    </details>
  <?php endif; ?>
</section>
