<?php
/** @var array<int, array<string, mixed>> $mySheetList 自分のシート（年度の新しい順） */
/** @var int[] $newYearList まだ作っていない、作れる年度 */
/** @var int $selectedYear 課長・課長代理の一覧で見る年度 */
/** @var int[] $yearOptionList */
/** @var array<int, array{department_id: int, department_name: string, is_boss: bool, members: array<int, array<string, mixed>>}> $memberOverview */
/** @var bool $isMobile */
$progressLabel = function (?array $progressFlags): string {
    if ($progressFlags === null) {
        return '';
    }
    $doneLabelList = [];
    foreach (['mid_self' => '中間・本人', 'mid_boss' => '中間・上長', 'final_self' => '最終・本人', 'final_boss' => '最終・上長'] as $flagKey => $flagLabel) {
        $doneLabelList[] = ($progressFlags[$flagKey] ? '✓ ' : '－ ') . $flagLabel;
    }
    return implode('　', $doneLabelList);
};
?>
<section class="card">
  <p><a href="<?= h(url('/index.php')) ?>">← ホーム</a></p>
  <h1>目標管理シート</h1>
  <p class="note"><?= h_lines(msg('目標管理評価シートを書いて、会社の様式の Excel（.xlsx）で受け取ります。見られるのは本人と、その課の課長・課長代理だけです（課長代理は一般・主任の人の分）。評価期間は 10 月 1 日〜翌 9 月 30 日です。')) ?></p>

  <p><a href="<?= h(url('/goals/criteria.php')) ?>" target="_blank" rel="noopener" class="goal-criteria-link">コンピテンシー評価基準を見る（別のタブで開く）</a></p>

  <h2>自分のシート</h2>
  <?php foreach ($newYearList as $newYear): ?>
    <p><a class="button primary" href="<?= h(url('/goals/edit.php?year=' . $newYear)) ?>">＋ <?= (int) $newYear ?> 年度のシートを作る</a>
      <span class="note"><?= (int) $newYear ?>/10/1〜<?= (int) $newYear + 1 ?>/9/30</span></p>
  <?php endforeach; ?>
  <?php if (!$mySheetList): ?>
    <p><?= h_lines(msg('目標管理シートはまだありません。')) ?></p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="table goal-list-table">
      <thead><tr><th>年度</th><th>様式</th><th>評価の進み具合</th><th>最後に保存</th></tr></thead>
      <tbody>
        <?php foreach ($mySheetList as $mySheet): ?>
          <tr>
            <td><a href="<?= h(url('/goals/edit.php?id=' . $mySheet['id'])) ?>"><?= (int) $mySheet['fiscal_year'] ?> 年度</a></td>
            <td><?= h(GOAL_TEMPLATE_KINDS[$mySheet['kind']]['label']) ?></td>
            <td><?= h($progressLabel($mySheet['progress'])) ?></td>
            <td><?= h(display_datetime($mySheet['updated_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <?php if ($memberOverview): ?>
    <h2 id="members">課の人のシート</h2>
    <form method="get" action="<?= h(url('/goals/index.php')) ?>" class="filters">
      <select name="year" aria-label="年度">
        <?php foreach ($yearOptionList as $yearOption): ?><option value="<?= (int) $yearOption ?>" <?= $yearOption === $selectedYear ? 'selected' : '' ?>><?= (int) $yearOption ?> 年度</option><?php endforeach; ?>
      </select>
      <button type="submit">表示</button>
    </form>
    <?php foreach ($memberOverview as $departmentOverview): ?>
      <h3><?= h($departmentOverview['department_name']) ?>（<?= (int) $selectedYear ?> 年度）</h3>
      <?php $canDownloadMembers = $departmentOverview['is_boss'] && !$isMobile; ?>
      <?php if ($canDownloadMembers && array_filter(array_column($departmentOverview['members'], 'sheet'))): ?>
        <p><a class="button goal-download-all" href="<?= h(url('/goals/download_all.php?year=' . $selectedYear . '&dept=' . $departmentOverview['department_id'])) ?>">この課のシートをまとめて受け取る（zip）</a>
          <span class="note"><?= h(msg('課の人の、この年度のシートを、会社の様式の Excel（1 人 1 ファイル）にして zip でまとめます。')) ?></span></p>
      <?php endif; ?>
      <?php if (!$departmentOverview['members']): ?>
        <p class="note"><?= h(msg('見られる人がいません。')) ?></p>
      <?php else: ?>
        <div class="table-wrap">
        <table class="table goal-list-table">
          <thead><tr><th>氏名</th><th>役職</th><th>様式</th><th>評価の進み具合</th><th>最後に保存</th><?php if ($canDownloadMembers): ?><th>Excel</th><?php endif; ?></tr></thead>
          <tbody>
            <?php foreach ($departmentOverview['members'] as $memberItem): ?>
              <tr>
                <td><?php if ($memberItem['sheet'] !== null): ?><a href="<?= h(url('/goals/edit.php?id=' . $memberItem['sheet']['id'])) ?>"><?= h($memberItem['name']) ?></a><?php else: ?><?= h($memberItem['name']) ?><?php endif; ?></td>
                <td><?= h($memberItem['role']) ?></td>
                <?php if ($memberItem['sheet'] === null): ?>
                  <td colspan="<?= $canDownloadMembers ? 4 : 3 ?>"><span class="badge">未作成</span></td>
                <?php else: ?>
                  <td><?= h(GOAL_TEMPLATE_KINDS[$memberItem['sheet']['kind']]['label']) ?></td>
                  <td><?= h($progressLabel($memberItem['progress'])) ?></td>
                  <td><?= h(display_datetime($memberItem['sheet']['updated_at'])) ?></td>
                  <?php if ($canDownloadMembers): ?><td><a class="button small goal-download-one" href="<?= h(url('/goals/download.php?id=' . $memberItem['sheet']['id'])) ?>">Excel</a></td><?php endif; ?>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
