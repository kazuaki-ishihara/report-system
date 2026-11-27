<?php
// G-11 調査の結果（一覧）。部長以上・依頼元の担当が見る全課共通の調査（K-067）
/** @var array $rows survey_viewable_all_departments_requests() */
$statusLabelByCode = ['open' => '公開中', 'closed' => '締め切り'];
?>
<section class="card wide">
  <h1>調査の結果</h1>
  <p class="note"><?= h_phrases('全課共通の臨時の調査の、全課の集計と課ごとのまとめを見られます。個人の回答と名前は出しません。') ?></p>
  <?php if (!$rows): ?>
    <p><?= h_phrases('見られる調査はまだありません。') ?></p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>題名</th><th>状態</th><th>期限</th><th>回答</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $requestRow): ?>
      <tr>
        <td><?= h($requestRow['title']) ?><?php if ($requestRow['requester'] !== ''): ?><br><span class="note">依頼元: <?= h($requestRow['requester']) ?></span><?php endif; ?></td>
        <td><?= h($statusLabelByCode[$requestRow['status']] ?? '') ?></td>
        <td><?= h($requestRow['due_at'] === null ? '—' : display_date((string) $requestRow['due_at'])) ?></td>
        <td><?= (int) $requestRow['answered'] ?>/<?= (int) $requestRow['targets'] ?></td>
        <td class="actions"><a href="<?= h(url('/surveys/result.php?id=' . (int) $requestRow['id'])) ?>">結果</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
