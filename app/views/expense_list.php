<?php
/** @var array<int, array{id: int, updated_at: string, data: array<string, mixed>, totals: array{subtotal: int, advance: int, total: int}}> $sheetList */
/** @var bool $isMobile */
?>
<section class="card">
  <p><a href="<?= h(url('/index.php')) ?>">← ホーム</a></p>
  <h1>通勤定期代の精算</h1>
  <p class="note"><?= h_phrases(msg('通勤定期代の精算書を作って、総務の様式の Excel（.xlsx）で受け取ります。精算書を見られるのは自分だけです。月次報告とは別の機能で、承認の流れはありません。')) ?></p>
  <p class="flash flash-info"><?= h_phrases(msg('このシステムで受け付けるのは通勤定期だけです。通勤実費・業務交通費などは、別のシステムで申請してください。')) ?></p>
  <p><a class="button primary" href="<?= h(url('/expenses/edit.php')) ?>">＋ 新しく作る</a></p>
  <?php if (!$sheetList): ?>
    <p><?= h_phrases(msg('精算書はまだありません。')) ?></p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="table expense-list-table">
      <thead><tr><th>提出日</th><th>精算方法</th><th>明細</th><th>合計</th><th>最後に保存</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($sheetList as $sheetItem): ?>
          <tr>
            <?php if (isset($sheetItem['data']['unreadable'])): ?>
              <td colspan="5"><?= h_phrases(msg('この精算書は読めません（暗号の鍵が変わった可能性があります）。システム管理者に連絡してください。')) ?></td>
            <?php else: ?>
              <td><a href="<?= h(url('/expenses/edit.php?id=' . $sheetItem['id'])) ?>"><?= h($sheetItem['data']['submitted_on']) ?></a></td>
              <td><?= h(EXPENSE_PAYMENT_METHODS[$sheetItem['data']['payment_method']] ?? '') ?></td>
              <td><?= count($sheetItem['data']['rows']) ?> 行</td>
              <td class="number"><?= h(number_format($sheetItem['totals']['total'])) ?> 円</td>
            <?php endif; ?>
            <?php if (!isset($sheetItem['data']['unreadable'])): ?><td><?= h(display_datetime($sheetItem['updated_at'])) ?></td><?php endif; ?>
            <td class="actions">
              <a class="button small" href="<?= h(url('/expenses/edit.php?id=' . $sheetItem['id'])) ?>">開く</a>
              <?php if (!$isMobile): ?><a class="button small" href="<?= h(url('/expenses/download.php?id=' . $sheetItem['id'])) ?>">Excel</a><?php endif; ?>
              <a class="button small" href="<?= h(url('/expenses/edit.php?copy=' . $sheetItem['id'])) ?>">写して新しく作る</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  <?php if ($isMobile): ?><p class="note"><?= h_phrases(msg('精算書のファイルは PC で受け取ってください。')) ?></p><?php endif; ?>
</section>
