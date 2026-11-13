<?php
/** @var array<int, array> $reviewableDepartmentById 見られる課（課 ID => departments の行） */
/** @var array<int, array> $choiceFieldById 選択式の条件に使える項目（項目 ID => 定義） */
/** @var string $searchScope 'department'（見られる課）か 'mine'（自分の報告） */
/** @var string $periodFrom */
/** @var string $periodTo */
/** @var string $selectedDepartmentText 課 ID の文字列。空ならすべての課 */
/** @var string $choiceTargetText "<項目 ID>:<コード>" または空 */
/** @var string $keywordText */
/** @var string[] $errorTextList */
/** @var array|null $searchResult search_reports() の戻り値。探していなければ null */
/** @var int $decryptLimit キーワードで探せる報告の上限 */
?>
<section class="card">
  <p><a href="<?= h(url('/index.php')) ?>">← ホーム</a></p>
  <h1>検索</h1>
  <p class="note"><?= $reviewableDepartmentById ? '担当の課の提出済み・確認完了の報告か、自分の報告（下書きを含む）を探します。' : '自分の報告（下書きを含む）を探します。' ?>キーワードは、項目マスタで「検索の対象」にした項目と、案件のコード・呼び名から探します。</p>
  <form method="get" action="<?= h(url('/reports/search.php')) ?>" class="form search-form">
    <input type="hidden" name="search" value="1">
    <label>対象月</label>
    <div class="filters period-range">
      <input type="month" name="from" value="<?= h($periodFrom) ?>" aria-label="対象月の始め"> 〜
      <input type="month" name="to" value="<?= h($periodTo) ?>" aria-label="対象月の終わり">
    </div>
    <?php if ($reviewableDepartmentById): ?>
      <label>探す範囲</label>
      <div class="choices">
        <label class="choice"><input type="radio" name="scope" value="department" <?= $searchScope === 'department' ? 'checked' : '' ?>> 担当の課の報告</label>
        <label class="choice"><input type="radio" name="scope" value="mine" <?= $searchScope === 'mine' ? 'checked' : '' ?>> 自分の報告</label>
      </div>
    <?php else: ?>
      <input type="hidden" name="scope" value="mine">
    <?php endif; ?>
    <?php if ($reviewableDepartmentById): ?>
    <label for="search-dept">課（担当の課の報告を探すとき）</label>
    <select id="search-dept" name="dept">
      <option value="">担当のすべての課</option>
      <?php foreach ($reviewableDepartmentById as $departmentId => $departmentRow): ?>
        <option value="<?= (int) $departmentId ?>" <?= (string) $departmentId === $selectedDepartmentText ? 'selected' : '' ?>><?= h($departmentRow['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <label for="search-choice">選択式の値</label>
    <select id="search-choice" name="choice">
      <option value="">指定しない</option>
      <?php foreach ($choiceFieldById as $choiceFieldId => $choiceField): ?>
        <optgroup label="<?= h($choiceField['label']) ?>">
          <?php if ($choiceField['type'] === 'checkbox'): ?>
            <option value="<?= (int) $choiceFieldId ?>:1" <?= $choiceTargetText === $choiceFieldId . ':1' ? 'selected' : '' ?>><?= h($choiceField['label']) ?>: はい</option>
          <?php else: ?>
            <?php foreach ($choiceField['options'] as $choiceOption): if (!$choiceOption['active']) { continue; } ?>
              <option value="<?= (int) $choiceFieldId ?>:<?= h($choiceOption['code']) ?>" <?= $choiceTargetText === $choiceFieldId . ':' . $choiceOption['code'] ? 'selected' : '' ?>><?= h($choiceField['label']) ?>: <?= h($choiceOption['label']) ?></option>
            <?php endforeach; ?>
          <?php endif; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
    <label for="search-keyword">キーワード（50 文字まで）</label>
    <input type="text" id="search-keyword" name="q" value="<?= h($keywordText) ?>" maxlength="50" placeholder="例: 在庫">
    <button type="submit" class="primary">探す</button>
  </form>
</section>

<?php foreach ($errorTextList as $errorText): ?>
  <div class="flash flash-error" role="alert"><?= h_lines($errorText) ?></div>
<?php endforeach; ?>

<?php if ($searchResult !== null): ?>
<section class="card">
  <?php if ($searchResult['isTooMany']): ?>
    <p class="flash flash-error"><?= h_lines(msg('キーワードで探す報告が {count} 件あり、上限（{limit} 件）を超えています。対象月・課・選択式の値で絞ってください。', ['count' => $searchResult['candidateCount'], 'limit' => $decryptLimit])) ?></p>
  <?php elseif (!$searchResult['resultList']): ?>
    <p><?= h(msg('該当する報告はありません。')) ?></p>
  <?php else: ?>
    <h2><?= h(msg('{count} 件', ['count' => count($searchResult['resultList'])])) ?><?= count($searchResult['resultList']) >= 200 ? ' ' . h(msg('（200 件まで出しています。条件を足して絞ってください）')) : '' ?></h2>
    <ul class="list search-result-list">
      <?php foreach ($searchResult['resultList'] as $resultItem): ?>
        <li>
          <a href="<?= h(url('/reports/view.php?id=' . $resultItem['reportId'])) ?>"><?= h($resultItem['ownerName']) ?>さん <?= h($resultItem['periodLabel']) ?></a>
          <span class="tag"><?= h($resultItem['statusLabel']) ?></span>
          <?php foreach ($resultItem['matchList'] as $matchItem): ?>
            <div class="comment-snippet"><span class="note"><?= h($matchItem['label']) ?>:</span> <?= h($matchItem['snippetText']) ?></div>
          <?php endforeach; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>
