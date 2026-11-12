<?php
/** @var array $rows */
/** @var int $count */
/** @var int $page */
/** @var array $q */
$kinds = ['' => 'すべて', 'view' => '閲覧', 'export' => '出力', 'admin' => '管理', 'auth' => 'ログイン'];
$qs = http_build_query($q);
?>
<section class="card wide">
  <h1>記録</h1>
  <nav class="tabs">
    <a href="<?= h(url('/admin/logs.php')) ?>" class="active">記録</a>
    <a href="<?= h(url('/admin/trash.php')) ?>">削除データ</a>
  </nav>
  <form method="get" action="<?= h(url('/admin/logs.php')) ?>" class="filters">
    <input type="date" name="from" value="<?= h($q['from']) ?>" aria-label="開始"> 〜 <input type="date" name="to" value="<?= h($q['to']) ?>" aria-label="終了">
    <select name="kind" aria-label="種類"><?php foreach ($kinds as $k => $l): ?><option value="<?= h($k) ?>" <?= $q['kind'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
    <input type="text" name="who" value="<?= h($q['who']) ?>" placeholder="利用者（ID の前方一致）">
    <input type="text" name="target" value="<?= h($q['target']) ?>" placeholder="対象（例: report:123）">
    <button type="submit">検索</button>
    <a class="button" href="<?= h(url('/admin/logs.php?' . $qs . '&csv=1')) ?>">この条件で CSV に出力</a>
  </form>
  <p class="note"><?= $count ?> 件。「この報告を見た人」は、対象に report:報告の番号 を入れて調べます。記録には報告の中身は入っていません。</p>
  <table class="table">
    <thead><tr><th>日時</th><th>利用者</th><th>種類</th><th>対象</th><th>IP アドレス</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= h($r['created_at']) ?></td><td><?= h((string) $r['login_id']) ?></td><td><?= h($kinds[$r['action']] ?? $r['action']) ?></td><td><?= h($r['target']) ?></td><td><?= h($r['ip_address']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($count > $page * 100): ?><a class="button" href="<?= h(url('/admin/logs.php?' . $qs . '&page=' . ($page + 1))) ?>">次の 100 件</a><?php endif; ?>
</section>
