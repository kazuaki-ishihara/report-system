<?php
/** @var array<int, array<string, mixed>> $faqList よくある質問（並び順） */
/** @var bool $canEdit 管理者・補佐か（書く・直す・消す・並べ替える） */
/** @var int|null $editedFaqId */
/** @var int|null $sourceRequestId もとの要望・依頼（「質問」から載せるとき） */
/** @var bool $isFormOpen */
/** @var array<string, string> $typedValueByName */
/** @var string[] $errorTextList */
?>
<section class="card">
  <p><a href="<?= h(url('/index.php')) ?>">← ホーム</a></p>
  <h1>よくある質問</h1>
  <p class="note">使い方でよく聞かれることと、その答えです。見つからないときは、課長・確認者を通して「要望・依頼」で質問してください。</p>
  <?php if (!$faqList): ?>
    <p class="note">まだ質問は載っていません。</p>
  <?php else: ?>
    <div class="faq-list">
    <?php foreach ($faqList as $faqIndex => $faqRow): ?>
      <details class="faq-item" id="faq-<?= (int) $faqRow['id'] ?>">
        <summary>Q. <?= h((string) $faqRow['question']) ?></summary>
        <div class="faq-answer">A. <?= h_lines((string) $faqRow['answer']) ?></div>
        <?php if ($canEdit): ?>
          <div class="actions">
            <a href="<?= h(url('/faq.php?edit=' . (int) $faqRow['id'] . '#faq-form')) ?>">直す</a>
            <?php foreach (['move_up' => '↑', 'move_down' => '↓'] as $moveAction => $moveLabel): ?>
              <form method="post" action="<?= h(url('/faq.php')) ?>" class="inline"><?= form_hidden() ?><input type="hidden" name="id" value="<?= (int) $faqRow['id'] ?>"><button type="submit" name="action" value="<?= h($moveAction) ?>" class="small" aria-label="<?= h($moveLabel) ?>"><?= h($moveLabel) ?></button></form>
            <?php endforeach; ?>
            <form method="post" action="<?= h(url('/faq.php')) ?>" class="inline" data-confirm="<?= h(msg('この質問を消します。よろしいですか？')) ?>"><?= form_hidden() ?><input type="hidden" name="id" value="<?= (int) $faqRow['id'] ?>"><button type="submit" name="action" value="delete" class="small danger">消す</button></form>
          </div>
        <?php endif; ?>
      </details>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($canEdit): ?>
  <details class="faq-form" id="faq-form" <?= $isFormOpen ? 'open' : '' ?>>
    <summary><?= $editedFaqId === null ? '＋ 質問を載せる' : '質問を直す' ?></summary>
    <?php foreach ($errorTextList as $errorText): ?><div class="flash flash-error" role="alert"><?= h_lines($errorText) ?></div><?php endforeach; ?>
    <form method="post" action="<?= h(url('/faq.php')) ?>" class="form">
      <?= form_hidden() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editedFaqId === null ? '' : (int) $editedFaqId ?>">
      <input type="hidden" name="source_request_id" value="<?= $sourceRequestId === null ? '' : (int) $sourceRequestId ?>">
      <?php if ($sourceRequestId !== null): ?><p class="note">要望・依頼 #<?= (int) $sourceRequestId ?> の質問から載せます。質問と答えは、だれにでも分かる文に書き直してください（出した人の文や名前は出さない）。</p><?php endif; ?>
      <label for="faq_question">質問（<?= FAQ_QUESTION_MAX_LENGTH ?> 文字まで）</label>
      <input id="faq_question" name="question" type="text" maxlength="<?= FAQ_QUESTION_MAX_LENGTH ?>" value="<?= h($typedValueByName['question']) ?>" required>
      <label for="faq_answer">答え（<?= FAQ_ANSWER_MAX_LENGTH ?> 文字まで）</label>
      <textarea id="faq_answer" name="answer" rows="5" maxlength="<?= FAQ_ANSWER_MAX_LENGTH ?>" required><?= h($typedValueByName['answer']) ?></textarea>
      <button type="submit" class="primary">保存</button>
    </form>
  </details>
  <?php endif; ?>
</section>
