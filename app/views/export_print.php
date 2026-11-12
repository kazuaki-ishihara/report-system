<?php
/** @var array $headerLines */
/** @var array $header */
/** @var array $rows */
/** @var array $summaryRows */
?>
<section class="print-page">
  <div class="print-head">
    <p class="warn"><?= h($headerLines[0]) ?></p>
    <h1><?= h($headerLines[1]) ?></h1>
    <p class="note"><?= h($headerLines[2]) ?></p>
  </div>
  <p class="no-print"><button type="button" class="primary print-button">印刷する（PDF として保存）</button>
    <span class="note">印刷の画面で、送信先に「PDF に保存」（Firefox では「ファイルへ出力」）を選びます。</span></p>
  <?php if ($rows): ?>
  <table class="table print-table">
    <thead><tr><?php foreach ($header as $hd): ?><th><?= h($hd) ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr><?php foreach ($r as $c): ?><td><?= nl2br(h((string) $c)) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  <?php if ($summaryRows): ?>
  <table class="table print-table">
    <tbody><?php foreach ($summaryRows as $r): ?><tr><?php foreach ($r as $c): ?><td><?= nl2br(h((string) $c)) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
</section>
