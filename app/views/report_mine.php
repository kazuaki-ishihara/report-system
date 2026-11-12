<?php
/** @var array $rows */
/** @var int $page */
/** @var bool $hasMore */
?>
<section class="card">
  <p><a href="<?= h(url('/index.php')) ?>">← ホーム</a></p>
  <h1>自分の報告</h1>
  <?php if (!$rows): ?>
    <p>まだ報告がありません。</p>
    <a class="button primary" href="<?= h(url('/index.php')) ?>">今月の報告を作る</a>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($rows as $r):
          $editable = (int) $r['is_legacy'] === 0 && $r['status'] !== 'confirmed';
          $href = $editable && $r['status'] === 'draft' ? '/reports/edit.php?period=' . rawurlencode($r['period']) : '/reports/view.php?id=' . (int) $r['id']; ?>
        <li>
          <a href="<?= h(url($href)) ?>"><?= h(period_label($r['period'])) ?></a>
          <span>
            <span class="tag"><?= (int) $r['is_legacy'] === 1 ? '移行データ' : h(report_status_label($r['status'])) ?></span>
            <?php if ((int) $r['open_count'] > 0): ?><span class="tag tag-open">回答待ち <?= (int) $r['open_count'] ?></span><?php endif; ?>
            <?php if ($r['status'] !== 'draft' && (int) $r['reviewers'] > 0): ?><span class="note">チェック <?= (int) $r['checked'] ?>/<?= (int) $r['reviewers'] ?></span><?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($hasMore): ?>
      <a class="button" href="<?= h(url('/reports/mine.php?page=' . ($page + 1))) ?>">もっと見る</a>
    <?php endif; ?>
  <?php endif; ?>
</section>
