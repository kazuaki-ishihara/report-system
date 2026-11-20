<?php
/** @var string[] $sheetNameList 評価基準のシートの名前 */
/** @var int $selectedSheetIndex 出しているシート（0 から） */
/** @var array<int, array<int, array{0: string, 1: int, 2: int}>> $tableRowList 行ごとのセル（文字・rowspan・colspan） */
?>
<section class="card goal-criteria">
  <h1>コンピテンシー評価基準</h1>
  <p class="note"><?= h_lines(msg('コンピテンシー評価（行動特性評価）の参考の基準です。目標管理シートで本人評価・上長評価を付けるときに見てください。この画面は見るだけです。')) ?></p>
  <nav class="tabs">
    <?php foreach ($sheetNameList as $sheetIndex => $sheetName): ?>
      <a href="<?= h(url('/goals/criteria.php?sheet=' . ($sheetIndex + 1))) ?>" class="<?= $sheetIndex === $selectedSheetIndex ? 'active' : '' ?>"><?= h($sheetName) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="table-wrap">
    <table class="goal-criteria-table">
      <tbody>
        <?php foreach ($tableRowList as $cellList): ?>
          <tr>
            <?php foreach ($cellList as $cell): ?>
              <td<?= $cell[1] > 1 ? ' rowspan="' . (int) $cell[1] . '"' : '' ?><?= $cell[2] > 1 ? ' colspan="' . (int) $cell[2] . '"' : '' ?>><?= h_lines($cell[0]) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
