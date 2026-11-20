<?php
/** @var array $req */
/** @var array $questions */
/** @var array $current */
/** @var array $errors */
/** @var array|null $response */
/** @var bool $open */
/** @var bool $isPreview 見本（課長が回答する人の画面を見る。送れない） */
?>
<section class="card">
  <?php if ($isPreview): ?>
    <p><a href="<?= h(url('/surveys/survey_edit.php?id=' . (int) $req['id'])) ?>">← 臨時の調査の編集</a></p>
    <div class="flash flash-info" role="status"><?= h_phrases(msg('回答する人に見える画面の見本です。入力して確かめられますが、「回答する」は押せず、何も保存されません。')) ?></div>
  <?php else: ?>
  <p><a href="<?= h(url('/index.php')) ?>">← ホーム</a></p>
  <?php endif; ?>
  <h1><?= h($req['title']) ?></h1>
  <ul class="list compact">
    <?php if ($req['requester'] !== ''): ?><li><span>依頼元</span><span><?= h($req['requester']) ?></span></li><?php endif; ?>
    <?php if ($req['due_at'] !== null): ?><li><span>回答期限</span><span><?= h(display_date($req['due_at'])) ?></span></li><?php endif; ?>
    <li><span>結果の扱い</span><span><?= $req['result_visibility'] === 'summary' ? '集計のみを依頼元に渡します（個人の回答は渡しません）' : '回答は課長が確認し、必要な範囲で依頼元に渡します' ?></span></li>
  </ul>
  <?php if ($req['purpose'] !== ''): ?><p>目的: <?= nl2br(h($req['purpose'])) ?></p><?php endif; ?>
  <?php if ($response !== null && $response['submitted_at'] !== null): ?>
    <p class="note">回答済み（<?= h(display_datetime($response['submitted_at'])) ?>）。<?= $open ? '期限までは直せます。' : '' ?></p>
  <?php endif; ?>
  <?php if ($errors): ?><div class="flash flash-error" role="alert">入力が必要な質問があります。</div><?php endif; ?>
  <?php if (!$open): ?><div class="flash flash-info"><?= h_phrases(msg('この調査は締め切られました。')) ?></div><?php endif; ?>

  <?php if ($isPreview): ?>
  <div class="form survey-preview">
  <?php else: ?>
  <form method="post" action="<?= h(url('/surveys/answer.php?id=' . (int) $req['id'])) ?>" class="form" data-leave-warn="1">
    <?= form_hidden() ?>
    <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
  <?php endif; ?>
    <?php foreach ($questions as $i => $q): $k = 'q_' . $q['id']; ?>
      <?= form_input_html(array_merge($q, ['label' => ($i + 1) . '. ' . $q['label']]), (string) ($current[$q['id']] ?? ''), 'q[' . $q['id'] . ']', $k, $errors[$k] ?? null) ?>
    <?php endforeach; ?>
    <?php if ($isPreview): ?>
      <div class="sticky-actions"><button type="button" class="primary" disabled>回答する（見本のため押せません）</button></div>
    <?php elseif ($open): ?>
      <div class="sticky-actions"><button type="submit" class="primary" data-warn-check="1">回答する</button></div>
    <?php endif; ?>
  <?= $isPreview ? '</div>' : '</form>' ?>
</section>
