<?php
/** @var bool $canSubmit 要望・依頼を出せるか（課長・確認者） */
/** @var bool $isStaff 管理者・補佐か（全員分を見て対応する） */
/** @var bool $isAllScope 全員分の一覧か */
/** @var string $statusFilter 'active'・'all'・状態のコード */
/** @var string $kindFilter 種類のコード（空ならすべて） */
/** @var array<int, array<string, mixed>> $requestRowList 要望・依頼の行（title_text は復号した件名） */
/** @var string[] $errorTextList */
/** @var array<string, string> $typedValueByName 入れた値（誤りで戻ったとき） */
/** @var array{open: int, waiting_reply: int}|null $staffCounts 管理者側に知らせる件数 */
/** @var bool $isNewFormOpen 新しく出す欄を開いておくか（誤りで戻った・画面のリンクから来た） */
/** @var array<int, array{id: int|string, kind: string, body: string}> $replyTemplateList 返信の定型文（管理者側だけ。種類ごと） */
$statusFilterLabelByName = ['active' => '続いているもの（未受付〜リリース待ち）', 'all' => 'すべて'] + SUPPORT_REQUEST_STATUS_LABELS;
$listQueryText = $isStaff && !$isAllScope ? 'scope=mine&' : '';
?>
<section class="card<?= $isStaff ? ' wide' : '' ?>">
  <p><a href="<?= h(url('/index.php')) ?>">← ホーム</a></p>
  <h1>要望・依頼</h1>
  <p class="note"><?= $isStaff
      ? '課長・確認者からの改善要望・対応依頼・不具合・質問です。開いて、対応した内容や検討の結果を返信し、状態を変えてください（完了・見送りにするときは返信が必須です）。'
      : 'システムへの改善要望・対応依頼・不具合・質問を、システム管理者へ出せます。対応した内容や検討の結果は、この画面に返信が届きます（ホームにも出ます）。' ?></p>

  <?php if ($isStaff && $staffCounts !== null): ?>
    <p>未受付 <strong><?= (int) $staffCounts['open'] ?></strong> ／ 返信待ち（出した人が返信） <strong><?= (int) $staffCounts['waiting_reply'] ?></strong></p>
  <?php endif; ?>

  <?php if ($canSubmit): ?>
  <details class="support-request-new" id="new" <?= $isNewFormOpen ? 'open' : '' ?>>
    <summary>＋ 新しく出す</summary>
    <?php foreach ($errorTextList as $errorText): ?><div class="flash flash-error" role="alert"><?= h_lines($errorText) ?></div><?php endforeach; ?>
    <form method="post" action="<?= h(url('/requests/index.php')) ?>" class="form">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="create">
      <label for="request_kind">種類</label>
      <select id="request_kind" name="kind">
        <?php foreach (SUPPORT_REQUEST_KIND_LABELS as $kindCode => $kindLabel): ?>
          <option value="<?= h($kindCode) ?>" <?= $typedValueByName['kind'] === $kindCode ? 'selected' : '' ?>><?= h($kindLabel) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="request_title">件名（<?= SUPPORT_REQUEST_TITLE_MAX_LENGTH ?> 文字まで）</label>
      <input id="request_title" name="title" type="text" maxlength="<?= SUPPORT_REQUEST_TITLE_MAX_LENGTH ?>" value="<?= h($typedValueByName['title']) ?>" required>
      <label for="request_body">内容（<?= SUPPORT_REQUEST_BODY_MAX_LENGTH ?> 文字まで）</label>
      <textarea id="request_body" name="body" maxlength="<?= SUPPORT_REQUEST_BODY_MAX_LENGTH ?>" rows="6" required
                placeholder="困っていること、こうなってほしいこと、起きたこと（いつ・どの画面で・何をしたら）"><?= h($typedValueByName['body']) ?></textarea>
      <p class="note">客先名・固有名詞・個人名は書かないでください（報告と同じです）。</p>
      <fieldset class="chip-choices">
        <legend>関係する画面（押して選ぶ）</legend>
        <?php foreach (SUPPORT_REQUEST_SCREEN_LABELS as $screenCode => $screenLabel): ?>
          <label class="chip-choice"><input type="radio" name="screen_code" value="<?= h($screenCode) ?>" <?= $typedValueByName['screen_code'] === $screenCode ? 'checked' : '' ?>><span><?= h($screenLabel) ?></span></label>
        <?php endforeach; ?>
      </fieldset>
      <?php if ($typedValueByName['source_path'] !== ''): ?>
        <input type="hidden" name="source_path" value="<?= h($typedValueByName['source_path']) ?>">
        <p class="note">開いていた画面（<?= h($typedValueByName['source_path']) ?>）と、使っている端末の種類も一緒に送ります。システム管理者が、どの画面の話かを確かめるためです。</p>
      <?php else: ?>
        <p class="note">使っている端末の種類（PC／スマートフォン、ブラウザー）も一緒に送ります。画面の要望は、その画面の下の「この画面について要望・依頼を出す」から出すと、開いていた画面も自動で入ります。</p>
      <?php endif; ?>
      <label for="request_urgency">急ぎ</label>
      <select id="request_urgency" name="urgency">
        <?php foreach (SUPPORT_REQUEST_URGENCY_LABELS as $urgencyCode => $urgencyLabel): ?>
          <option value="<?= h($urgencyCode) ?>" <?= $typedValueByName['urgency'] === $urgencyCode ? 'selected' : '' ?>><?= h($urgencyLabel) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="primary">出す</button>
    </form>
  </details>
  <?php endif; ?>

  <?php if ($isStaff && $canSubmit): ?>
  <nav class="tabs">
    <a href="<?= h(url('/requests/index.php')) ?>" class="<?= $isAllScope ? 'active' : '' ?>">すべての要望・依頼</a>
    <a href="<?= h(url('/requests/index.php?scope=mine')) ?>" class="<?= $isAllScope ? '' : 'active' ?>">自分が出したもの</a>
  </nav>
  <?php endif; ?>

  <form method="get" action="<?= h(url('/requests/index.php')) ?>" class="filters">
    <?php if ($isStaff && !$isAllScope): ?><input type="hidden" name="scope" value="mine"><?php endif; ?>
    <select name="status" aria-label="状態">
      <?php foreach ($statusFilterLabelByName as $filterName => $filterLabel): ?>
        <option value="<?= h($filterName) ?>" <?= $statusFilter === $filterName ? 'selected' : '' ?>><?= h($filterLabel) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="kind" aria-label="種類">
      <option value="">種類: すべて</option>
      <?php foreach (SUPPORT_REQUEST_KIND_LABELS as $kindCode => $kindLabel): ?>
        <option value="<?= h($kindCode) ?>" <?= $kindFilter === $kindCode ? 'selected' : '' ?>><?= h($kindLabel) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">表示</button>
  </form>

  <?php if ($isStaff && $isAllScope): ?>
    <?php $csvQueryText = http_build_query(['status' => $statusFilter, 'kind' => $kindFilter, 'csv' => '1']); ?>
    <p class="actions">
      <a class="button small" href="<?= h(url('/requests/index.php?' . $csvQueryText)) ?>">この条件で CSV に出力（件名と状態）</a>
      <a class="button small" href="<?= h(url('/requests/index.php?' . $csvQueryText . '&body=1')) ?>">内容も含めて CSV に出力</a>
    </p>
  <?php endif; ?>
  <?php if (!$requestRowList): ?>
    <p class="note">該当する要望・依頼はありません。</p>
  <?php else: ?>
  <ul class="list support-request-list">
    <?php foreach ($requestRowList as $requestRow): ?>
      <li>
        <span>
          <a href="<?= h(url('/requests/view.php?id=' . (int) $requestRow['id'])) ?>">#<?= (int) $requestRow['id'] ?> <?= h($requestRow['title_text']) ?></a>
          <span class="note"><?= h(SUPPORT_REQUEST_KIND_LABELS[$requestRow['kind']] ?? '') ?><?= $requestRow['screen_code'] !== '' ? ' ／ ' . h(SUPPORT_REQUEST_SCREEN_LABELS[$requestRow['screen_code']] ?? (string) $requestRow['screen_code']) : '' ?><?php if ($isAllScope): ?> ／ <?= h((string) $requestRow['requester_name']) ?><?= $requestRow['department_name'] !== null ? '（' . h((string) $requestRow['department_name']) . '）' : '' ?><?php endif; ?> ／ <?= h(display_datetime($requestRow['last_activity_at'])) ?></span>
        </span>
        <span>
          <span class="tag support-status-<?= h((string) $requestRow['status']) ?>"><?= h(SUPPORT_REQUEST_STATUS_LABELS[$requestRow['status']] ?? '') ?></span>
          <?php if ($requestRow['urgency'] === 'urgent'): ?><span class="tag tag-open">急ぎ</span><?php endif; ?>
          <?php if ((string) $requestRow['plan_text'] !== ''): ?><span class="tag tag-changed"><?= h((string) $requestRow['plan_text']) ?></span><?php endif; ?>
          <?php if ($requestRow['merged_into_id'] !== null): ?><span class="tag">#<?= (int) $requestRow['merged_into_id'] ?> にまとめた</span><?php endif; ?>
          <?php if (!$isAllScope && (int) $requestRow['unseen_staff_message_count'] > 0): ?><span class="tag tag-changed">回答あり</span><?php endif; ?>
          <?php if ($isAllScope && $requestRow['status'] !== 'open' && in_array($requestRow['status'], SUPPORT_REQUEST_ACTIVE_STATUSES, true) && $requestRow['last_message_is_staff'] !== null && (int) $requestRow['last_message_is_staff'] === 0): ?><span class="tag tag-changed">返信あり</span><?php endif; ?>
        </span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($isStaff): ?>
  <details class="reply-templates" id="reply-templates">
    <summary>返信の定型文（<?= count($replyTemplateList) ?> / <?= SUPPORT_REPLY_TEMPLATE_MAX_COUNT ?>）</summary>
    <p class="note">要望・依頼の返信で選んで使う文です（例: 「ユーザーを追加しました。仮のパスワードは別にお伝えします。」）。要望の種類ごとに用意でき、返信では、その種類と「すべての種類」の定型文だけが出ます。</p>
    <?php if ($replyTemplateList): ?>
    <ul class="list compact">
      <?php foreach ($replyTemplateList as $replyTemplate): ?>
        <li><span><span class="tag"><?= h($replyTemplate['kind'] === '' ? 'すべての種類' : (SUPPORT_REQUEST_KIND_LABELS[$replyTemplate['kind']] ?? '')) ?></span> <?= h_lines((string) $replyTemplate['body']) ?></span>
          <form method="post" action="<?= h(url('/requests/index.php')) ?>" class="inline" data-confirm="<?= h(msg('この定型文を消します。よろしいですか？')) ?>">
            <?= form_hidden() ?><input type="hidden" name="template_id" value="<?= (int) $replyTemplate['id'] ?>">
            <button type="submit" name="action" value="template_delete" class="small danger">消す</button>
          </form></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <form method="post" action="<?= h(url('/requests/index.php')) ?>" class="form">
      <?= form_hidden() ?>
      <label for="template_kind">要望の種類</label>
      <select id="template_kind" name="template_kind">
        <option value="">すべての種類</option>
        <?php foreach (SUPPORT_REQUEST_KIND_LABELS as $kindCode => $kindLabel): ?><option value="<?= h($kindCode) ?>"><?= h($kindLabel) ?></option><?php endforeach; ?>
      </select>
      <label for="template_body">足す定型文（<?= SUPPORT_REPLY_TEMPLATE_MAX_LENGTH ?> 文字まで）</label>
      <textarea id="template_body" name="template_body" rows="2" maxlength="<?= SUPPORT_REPLY_TEMPLATE_MAX_LENGTH ?>" required></textarea>
      <button type="submit" name="action" value="template_add">定型文を足す</button>
    </form>
  </details>
  <?php endif; ?>
</section>
