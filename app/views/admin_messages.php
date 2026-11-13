<?php
/** @var array<int, array{anchorId: string, defaultText: string, screenNames: string[], placeholders: string[], isChanged: bool, currentText: string, updatedAt: ?string, updatedByName: ?string, errorText: ?string}> $messageRowList */
/** @var array<int, array{msg_key: string, body: string, updated_at: string, updated_by_name: ?string}> $unusedOverrideRowList */
/** @var string[] $screenNameList */
/** @var string $filterScreenName */
/** @var string $filterKeywordText */
/** @var bool $isChangedOnly */
/** @var string $selfPath */
/** @var int $totalCount */
/** @var int $changedCount */
?>
<section class="card wide">
  <h1>メッセージ</h1>
  <p class="note">画面に出す文言を変えられます（500 文字まで。改行も入れられます）。変えた文言は、すべての課の画面に出ます。<code>{count}</code> のような差し込みは、そのまま残してください（件数や名前が入ります）。ログインとパスワードの再設定の文言は変えられません。</p>
  <p>全 <strong><?= (int) $totalCount ?></strong> 件 ／ 変えた文言 <strong><?= (int) $changedCount ?></strong> 件</p>

  <form method="get" action="<?= h(url('/admin/messages.php')) ?>" class="filters">
    <select name="screen" aria-label="画面">
      <option value="">すべての画面</option>
      <?php foreach ($screenNameList as $screenName): ?>
        <option value="<?= h($screenName) ?>" <?= $screenName === $filterScreenName ? 'selected' : '' ?>><?= h($screenName) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="q" value="<?= h($filterKeywordText) ?>" placeholder="文言で探す" maxlength="100">
    <label class="check"><input type="checkbox" name="changed" value="1" <?= $isChangedOnly ? 'checked' : '' ?>> 変えた文言だけ</label>
    <button type="submit">絞り込む</button>
  </form>

  <?php if (!$messageRowList): ?>
    <p>該当する文言はありません。</p>
  <?php endif; ?>
  <ul class="message-list">
    <?php foreach ($messageRowList as $messageRow): ?>
      <li id="<?= h($messageRow['anchorId']) ?>" class="message-item<?= $messageRow['isChanged'] ? ' is-changed' : '' ?>">
        <p class="note">
          <?php foreach ($messageRow['screenNames'] as $screenName): ?><span class="tag"><?= h($screenName) ?></span> <?php endforeach; ?>
          <?php if ($messageRow['isChanged']): ?><span class="tag tag-open">変更済み</span> <?= h((string) $messageRow['updatedByName']) ?> <?= h((string) $messageRow['updatedAt']) ?><?php endif; ?>
        </p>
        <p class="message-default">既定: <?= h_lines($messageRow['defaultText']) ?></p>
        <form method="post" action="<?= h(url($selfPath)) ?>" class="message-form">
          <?= form_hidden() ?>
          <input type="hidden" name="msg_key" value="<?= h($messageRow['defaultText']) ?>">
          <label for="<?= h($messageRow['anchorId']) ?>-body">画面に出す文言<?= $messageRow['placeholders'] ? '（差し込み: ' . h(implode('・', array_map(function (string $placeholderName): string { return '{' . $placeholderName . '}'; }, $messageRow['placeholders']))) . '）' : '' ?></label>
          <textarea id="<?= h($messageRow['anchorId']) ?>-body" name="body" maxlength="500" rows="2"><?= h($messageRow['currentText']) ?></textarea>
          <?php if ($messageRow['errorText'] !== null): ?><p class="field-error" role="alert"><?= h_lines($messageRow['errorText']) ?></p><?php endif; ?>
          <button type="submit" name="action" value="save" class="small">保存</button>
          <?php if ($messageRow['isChanged']): ?>
            <button type="submit" name="action" value="reset" class="small" data-confirm="<?= h(msg('既定の文言に戻します。よろしいですか？')) ?>">既定に戻す</button>
          <?php endif; ?>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($unusedOverrideRowList): ?>
    <h2>使われていない文言</h2>
    <p class="note">プログラムの更新で既定の文言が変わったため、画面に出なくなった変更です。新しい文言で変え直してから消してください。</p>
    <ul class="message-list">
      <?php foreach ($unusedOverrideRowList as $unusedOverrideRow): ?>
        <li class="message-item">
          <p class="message-default">既定だった文言: <?= h((string) $unusedOverrideRow['msg_key']) ?></p>
          <p>変えた文言: <?= h((string) $unusedOverrideRow['body']) ?>（<?= h((string) $unusedOverrideRow['updated_by_name']) ?> <?= h(display_datetime($unusedOverrideRow['updated_at'])) ?>）</p>
          <form method="post" action="<?= h(url($selfPath)) ?>" class="inline" data-confirm="<?= h(msg('この変更を消します。よろしいですか？')) ?>">
            <?= form_hidden() ?>
            <input type="hidden" name="msg_key" value="<?= h((string) $unusedOverrideRow['msg_key']) ?>">
            <button type="submit" name="action" value="delete_unused" class="small danger">消す</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
