<?php
/** @var string[] $sheetNameList 評価基準のシートの名前 */
/** @var int $selectedSheetIndex 出しているシート（0 から） */
/** @var array<int, array<int, array<int, array{0: string, 1: int, 2: int}>>> $tableList 表の並び（部門の表が横に並んだシートは部門ごと）。表は行の並び、行はセル（文字・rowspan・colspan）の並び */
?>
<section class="card goal-criteria">
  <h1>コンピテンシー評価基準</h1>
  <p class="note"><?= h_lines(msg('コンピテンシー評価（行動特性評価）の参考の基準です。目標管理シートで本人評価・上長評価を付けるときに見てください。この画面は見るだけです。')) ?></p>
  <nav class="tabs">
    <?php foreach ($sheetNameList as $sheetIndex => $sheetName): ?>
      <a href="<?= h(url('/goals/criteria.php?sheet=' . ($sheetIndex + 1))) ?>" class="<?= $sheetIndex === $selectedSheetIndex ? 'active' : '' ?>"><?= h($sheetName) ?></a>
    <?php endforeach; ?>
  </nav>
  <p class="note"><?= h(msg('表が画面に入りきらないときは、表の上のつまみ（横スライド）か ◀ ▶、または表の下のスクロールバーで左右に動かします（Shift を押しながらマウスのホイールでも動きます）。')) ?></p>
  <?php foreach ($tableList as $tableRowList): ?>
    <div class="goal-criteria-block">
      <div class="goal-criteria-slider" hidden>
        <button type="button" class="small" data-criteria-step="-1" aria-label="左へ">◀</button>
        <input type="range" min="0" max="1000" value="0" aria-label="表を左右に動かす">
        <button type="button" class="small" data-criteria-step="1" aria-label="右へ">▶</button>
      </div>
      <div class="goal-criteria-scroll">
        <table class="goal-criteria-table">
          <tbody>
            <?php foreach ($tableRowList as $cellList): ?>
              <tr>
                <?php foreach ($cellList as $cell): ?>
                  <?php $cellLength = function_exists('mb_strlen') ? mb_strlen($cell[0]) : strlen($cell[0]); $cellClass = $cellLength > 24 ? 'criteria-long' : ($cellLength > 0 && $cellLength <= 12 && strpos($cell[0], "\n") === false ? 'criteria-short' : ''); ?>
                  <td<?= $cellClass !== '' ? ' class="' . $cellClass . '"' : '' ?><?= $cell[1] > 1 ? ' rowspan="' . (int) $cell[1] . '"' : '' ?><?= $cell[2] > 1 ? ' colspan="' . (int) $cell[2] . '"' : '' ?>><?= h_lines($cell[0]) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
</section>
