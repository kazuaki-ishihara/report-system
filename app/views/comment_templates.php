<?php
/** @var string $templateScope 'department'（課の定型文）か 'mine'（自分の定型文） */
/** @var bool $isReviewer 確認者か（自分の定型文のタブを出す） */
/** @var array<int, array> $manageableDepartmentById 課長として管理できる課（課 ID => departments の行） */
/** @var int $selectedDepartmentId */
/** @var array<int, array{id: int|string, body: string, sort_order: int|string}> $templateList */
/** @var int $maxTemplateCount */
/** @var string[] $errorTextList */
/** @var string $typedBodyText 追加できなかったときに戻す入力 */
/** @var string $selfPath */
$lastTemplateIndex = count($templateList) - 1;
?>
<section class="card">
  <h1>コメントの定型文</h1>
  <nav class="tabs">
    <?php if ($manageableDepartmentById): ?><a href="<?= h(url('/admin/comment_templates.php?scope=department')) ?>" class="<?= $templateScope === 'department' ? 'active' : '' ?>">課の定型文</a><?php endif; ?>
    <?php if ($isReviewer): ?><a href="<?= h(url('/admin/comment_templates.php?scope=mine')) ?>" class="<?= $templateScope === 'mine' ? 'active' : '' ?>">自分の定型文</a><?php endif; ?>
  </nav>
  <p class="note"><?= $templateScope === 'department'
      ? '課の確認者が報告にコメントするとき、ここで登録した文から選んで入れられます。'
      : '自分だけが使う定型文です。報告にコメントするとき、課の定型文と並べて選べます。' ?><?= (int) $maxTemplateCount ?> 件まで。</p>
  <?php if ($templateScope === 'mine'): ?>
  <?php elseif (count($manageableDepartmentById) > 1): ?>
    <form method="get" action="<?= h(url('/admin/comment_templates.php')) ?>" class="filters">
      <input type="hidden" name="scope" value="department">
      <select name="dept" aria-label="課">
        <?php foreach ($manageableDepartmentById as $departmentId => $departmentRow): ?>
          <option value="<?= (int) $departmentId ?>" <?= (int) $departmentId === $selectedDepartmentId ? 'selected' : '' ?>><?= h($departmentRow['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit">表示</button>
    </form>
  <?php else: ?>
    <p><?= h(reset($manageableDepartmentById)['name']) ?></p>
  <?php endif; ?>

  <?php if (!$templateList): ?>
    <p><?= h(msg('定型文はまだありません。')) ?></p>
  <?php endif; ?>
  <ul class="message-list">
    <?php foreach ($templateList as $templateIndex => $template): ?>
      <li class="message-item" id="template-<?= (int) $template['id'] ?>">
        <form method="post" action="<?= h(url($selfPath)) ?>" class="message-form">
          <?= form_hidden() ?>
          <input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>">
          <textarea name="body" maxlength="300" rows="2" aria-label="定型文 <?= $templateIndex + 1 ?>"><?= h($template['body']) ?></textarea>
          <button type="submit" name="action" value="update" class="small">保存</button>
          <button type="submit" name="action" value="move_up" class="small" <?= $templateIndex === 0 ? 'disabled' : '' ?>>上へ</button>
          <button type="submit" name="action" value="move_down" class="small" <?= $templateIndex === $lastTemplateIndex ? 'disabled' : '' ?>>下へ</button>
          <button type="submit" name="action" value="delete" class="small danger" data-confirm="<?= h(msg('この定型文を削除します。よろしいですか？')) ?>">削除</button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php if (count($templateList) < $maxTemplateCount): ?>
    <h2>定型文を追加</h2>
    <?php foreach ($errorTextList as $errorText): ?><div class="flash flash-error" role="alert"><?= h_lines($errorText) ?></div><?php endforeach; ?>
    <form method="post" action="<?= h(url($selfPath)) ?>" class="form" data-leave-warn="1">
      <?= form_hidden() ?>
      <label for="new-template-body">定型文（300 文字まで）</label>
      <textarea id="new-template-body" name="body" maxlength="300" rows="3" placeholder="例: 完了の見込みを教えてください"><?= h($typedBodyText) ?></textarea>
      <button type="submit" name="action" value="add" class="primary">追加</button>
    </form>
  <?php endif; ?>
</section>
