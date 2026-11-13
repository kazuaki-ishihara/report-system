<?php
/** @var string $tabName 'sent'（自分が出したコメント）または 'received'（自分の報告へのコメント） */
/** @var string $statusFilterName 'unresolved'・'open'・'answered'・'resolved' */
/** @var array<int, array{commentId: int, reportId: int, periodLabel: string, ownerName: string, authorName: string, targetLabel: string, commentType: string, threadStatus: string, snippetText: string, replyCount: int, createdAt: string}> $commentItemList */
/** @var bool $isReviewer 確認者か（「自分が出したコメント」のタブを出す） */
$commentTypeLabelByCode = ['request' => '追加情報の依頼', 'confirm' => '認識の確認', 'note' => '連絡'];
$threadStatusLabelByCode = ['open' => '回答待ち', 'answered' => '回答あり', 'resolved' => '解決'];
$statusFilterLabelByName = ['unresolved' => '未解決（回答待ち・回答あり）', 'open' => '回答待ち', 'answered' => '回答あり', 'resolved' => '解決'];
?>
<section class="card">
  <p><a href="<?= h(url('/index.php')) ?>">← ホーム</a></p>
  <h1>コメント</h1>
  <nav class="tabs">
    <?php if ($isReviewer || $tabName === 'sent'): ?>
      <a href="<?= h(url('/reports/comments.php?tab=sent&status=' . rawurlencode($statusFilterName))) ?>" class="<?= $tabName === 'sent' ? 'active' : '' ?>">自分が出したコメント</a>
    <?php endif; ?>
    <a href="<?= h(url('/reports/comments.php?tab=received&status=' . rawurlencode($statusFilterName))) ?>" class="<?= $tabName === 'received' ? 'active' : '' ?>">自分の報告へのコメント</a>
  </nav>
  <form method="get" action="<?= h(url('/reports/comments.php')) ?>" class="filters">
    <input type="hidden" name="tab" value="<?= h($tabName) ?>">
    <select name="status" aria-label="状態">
      <?php foreach ($statusFilterLabelByName as $filterName => $filterLabel): ?>
        <option value="<?= h($filterName) ?>" <?= $filterName === $statusFilterName ? 'selected' : '' ?>><?= h($filterLabel) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">表示</button>
  </form>
  <p class="note"><?= $tabName === 'sent'
      ? '「回答あり」は本人が返信したコメントです。内容を確かめて「解決」にしてください。'
      : '「回答待ち」は、あなたの返信を待っているコメントです。' ?></p>

  <?php if (!$commentItemList): ?>
    <p><?= h(msg('該当するコメントはありません。')) ?></p>
  <?php else: ?>
    <ul class="list comment-list">
      <?php foreach ($commentItemList as $commentItem): ?>
        <li>
          <a href="<?= h(url('/reports/view.php?id=' . $commentItem['reportId'] . '#comment-' . $commentItem['commentId'])) ?>">
            <span class="tag <?= $commentItem['threadStatus'] === 'open' ? 'tag-open' : '' ?>"><?= h($threadStatusLabelByCode[$commentItem['threadStatus']] ?? '') ?></span>
            <?= h($commentItem['ownerName']) ?>さん <?= h($commentItem['periodLabel']) ?> ／ <?= h($commentItem['targetLabel']) ?>
          </a>
          <span class="note">
            <?= h($commentTypeLabelByCode[$commentItem['commentType']] ?? '') ?> ・ <?= h($commentItem['authorName']) ?> <?= h(display_datetime($commentItem['createdAt'])) ?>
            <?php if ($commentItem['replyCount'] > 0): ?> ・ 返信 <?= (int) $commentItem['replyCount'] ?><?php endif; ?>
          </span>
          <?php if ($commentItem['snippetText'] !== ''): ?><div class="comment-snippet"><?= h($commentItem['snippetText']) ?></div><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (count($commentItemList) >= 200): ?><p class="note"><?= h(msg('新しい 200 件まで出しています。')) ?></p><?php endif; ?>
  <?php endif; ?>
</section>
