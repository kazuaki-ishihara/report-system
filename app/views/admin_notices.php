<?php
/** @var array<int, array<string, mixed>> $noticeList 更新のお知らせ（新しい順） */
/** @var int|null $editedNoticeId 編集しているお知らせ（新しく書くときは null） */
/** @var int|null $sourceRequestId もとの要望・依頼の番号（「リリース済み」の画面から来たとき） */
/** @var array<string, string> $typedValueByName */
/** @var string[] $errorTextList */
?>
<section class="card wide">
  <h1>更新のお知らせ</h1>
  <p class="note">システムの改善や変更を、全員のホームに知らせます。出す期間の間だけ出ます。要望を出した人の名前や、要望の文はそのまま書かないでください（全員に出ます）。</p>
  <?php foreach ($errorTextList as $errorText): ?><div class="flash flash-error" role="alert"><?= h_lines($errorText) ?></div><?php endforeach; ?>
  <form method="post" action="<?= h(url('/admin/notices.php')) ?>" class="form">
    <?= form_hidden() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editedNoticeId === null ? '' : (int) $editedNoticeId ?>">
    <input type="hidden" name="source_request_id" value="<?= $sourceRequestId === null ? '' : (int) $sourceRequestId ?>">
    <h2><?= $editedNoticeId === null ? '新しく書く' : '編集' ?><?php if ($sourceRequestId !== null): ?> <span class="note">（要望・依頼 #<?= (int) $sourceRequestId ?> の対応）</span><?php endif; ?></h2>
    <label for="notice_title">題名（<?= NOTICE_TITLE_MAX_LENGTH ?> 文字まで）</label>
    <input id="notice_title" name="title" type="text" maxlength="<?= NOTICE_TITLE_MAX_LENGTH ?>" value="<?= h($typedValueByName['title']) ?>" required placeholder="例: 提出状況の状態の札を色分けしました">
    <label for="notice_body">内容（<?= NOTICE_BODY_MAX_LENGTH ?> 文字まで。空でもよい）</label>
    <textarea id="notice_body" name="body" rows="4" maxlength="<?= NOTICE_BODY_MAX_LENGTH ?>" data-free-text="1"><?= h($typedValueByName['body']) ?></textarea>
    <p class="field-warning" hidden>客先名や個人名が含まれていませんか</p>
    <div class="filters">
      <label for="notice_from">出す日</label><input id="notice_from" name="show_from" type="date" value="<?= h($typedValueByName['show_from']) ?>" required>
      <label for="notice_until">出す最後の日</label><input id="notice_until" name="show_until" type="date" value="<?= h($typedValueByName['show_until']) ?>" required>
    </div>
    <button type="submit" class="primary">保存</button>
    <?php if ($editedNoticeId !== null): ?><a href="<?= h(url('/admin/notices.php')) ?>">新しく書く方に戻る</a><?php endif; ?>
  </form>

  <h2>これまでのお知らせ</h2>
  <?php if (!$noticeList): ?>
    <p class="note">まだありません。</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>題名</th><th>出す期間</th><th>状態</th><th>更新</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($noticeList as $noticeRow): $noticeStateLabel = notice_state_label($noticeRow); ?>
      <tr>
        <td><?= h((string) $noticeRow['title']) ?><?= $noticeRow['source_request_id'] !== null ? ' <span class="note">#' . (int) $noticeRow['source_request_id'] . '</span>' : '' ?></td>
        <td><?= h(display_date((string) $noticeRow['show_from'])) ?> 〜 <?= h(display_date((string) $noticeRow['show_until'])) ?></td>
        <td><span class="tag<?= $noticeStateLabel === '出している' ? ' tag-resolved' : '' ?>"><?= h($noticeStateLabel) ?></span></td>
        <td><?= h((string) ($noticeRow['updated_by_name'] ?? '')) ?></td>
        <td class="actions">
          <a href="<?= h(url('/admin/notices.php?edit=' . (int) $noticeRow['id'])) ?>">編集</a>
          <form method="post" action="<?= h(url('/admin/notices.php')) ?>" class="inline" data-confirm="<?= h(msg('このお知らせを消します。よろしいですか？')) ?>">
            <?= form_hidden() ?><input type="hidden" name="id" value="<?= (int) $noticeRow['id'] ?>">
            <button type="submit" name="action" value="delete" class="small danger">消す</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
