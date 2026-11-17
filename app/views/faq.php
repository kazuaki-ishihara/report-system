<?php
/** @var array<int, array<string, mixed>> $faqList よくある質問（並び順） */
/** @var array<int, array{code: string, label: string, count: int}> $categoryCountList 質問のある分野と件数 */
/** @var string|null $selectedCategory 選んだ分野（JavaScript が無いとき。null は選んでいない） */
/** @var array<int, array<string, mixed>> $categoryFaqList 選んだ分野の質問 */
/** @var string|null $screenCategory 「この画面の使い方」から来たときの、その画面の分野 */
/** @var bool $canEdit 管理者・補佐か（書く・直す・消す・並べ替える・取り込む） */
/** @var int $standardMissingCount 標準のよくある質問のうち、まだ載っていない数 */
/** @var int|null $editedFaqId */
/** @var int|null $sourceRequestId もとの要望・依頼（「質問」から載せるとき） */
/** @var bool $isFormOpen */
/** @var array<string, string> $typedValueByName */
/** @var string[] $errorTextList */
/** @var string $searchQueryText 探す文（空なら探していない） */
/** @var string $chatResultCode 入れた文への答えの種類（faq_answer_chat()。matched・no_match・opener・vague・unrelated・abusive。空は入れていない） */
/** @var bool $isIgnored 関係のない質問・不適切な表現が続いたので、返事をしないか */
/** @var string $distressLevel 心の不調らしい文の段階（urgent・concern。空はちがう） */
/** @var int $chatLogId チャットの記録の番号（質問として送ったときに、記録に残す） */
/** @var array<int, array<string, mixed>> $matchedFaqList 近い質問（近い順） */
/** @var bool $canAsk 見つからない質問を送れるか（課長・確認者） */
/** @var bool $isStaff 管理者・補佐か（質問を受ける側） */
/** @var string $askedQuestionText 送る質問の文 */
/** @var string[] $askErrorTextList 送るときの誤り */
/** @var string $linkedScreenCode 「この画面の使い方」から来たときの画面の番号 */
/** @var string $sourcePathText 「この画面の使い方」から来たときの開いていた画面 */

$askRole = $canAsk ? 'ask' : ($isStaff ? 'staff' : 'member');
$keptQueryValueByName = array_filter(['screen' => $linkedScreenCode, 'from' => $sourcePathText], static function (string $value): bool { return $value !== ''; });
$categoryUrl = static function (string $categoryCode) use ($keptQueryValueByName): string {
    return url('/faq.php?' . http_build_query(['category' => $categoryCode] + $keptQueryValueByName)) . '#faq-chat';
};
$hasScreenCategory = $screenCategory !== null && in_array($screenCategory, array_column($categoryCountList, 'code'), true);
// 分野のボタン（JavaScript があれば data-category で、その場で質問を出す。無ければリンクで開き直す）
$renderCategoryChoices = static function () use ($categoryCountList, $categoryUrl, $screenCategory): void {
    echo '<div class="chat-choices">';
    foreach ($categoryCountList as $categoryCount) {
        $isScreenCategory = $categoryCount['code'] === $screenCategory;
        echo '<a class="chat-choice' . ($isScreenCategory ? ' chat-choice-recommended' : '') . '" href="' . h($categoryUrl($categoryCount['code'])) . '" data-category="' . h($categoryCount['code']) . '">'
            . h($categoryCount['label']) . '（' . (int) $categoryCount['count'] . '）</a>';
    }
    echo '</div>';
};
?>
<section class="card">
  <p><a href="<?= h(url('/index.php')) ?>">← ホーム</a></p>
  <h1>よくある質問</h1>
  <p class="note">使い方でよく聞かれることと、その答えです。下のチャットに知りたいことを入れるか、分野を選んでください（自動の答えは、よくある質問に載っている文だけです）。聞いた質問は、よくある質問をよくするために記録します。</p>

  <div class="faq-chat" id="faq-chat" data-ask-role="<?= h($askRole) ?>">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>" class="faq-chat-csrf">
    <div class="faq-chat-log" aria-live="polite">
      <div class="chat-bubble chat-bot">こんにちは。知りたいことを入れてください。よくある質問から近い答えを探します。<br>（例: パスワードを忘れた、スマホを替えた、締め日を過ぎた）<?php if ($categoryCountList): ?><br>何を聞けばよいか分からないときは、分野を選んでください。<?php endif; ?></div>
      <?php if ($hasScreenCategory): ?>
        <div class="chat-bubble chat-bot">開いていた画面の質問は「<?= h(FAQ_CATEGORY_LABELS[$screenCategory]) ?>」にあります。</div>
      <?php endif; ?>
      <?php if ($categoryCountList): ?><?php $renderCategoryChoices(); ?><?php endif; ?>

      <?php if ($selectedCategory !== null): ?>
        <div class="chat-bubble chat-user"><?= h(FAQ_CATEGORY_LABELS[$selectedCategory]) ?></div>
        <?php if ($categoryFaqList): ?>
          <div class="chat-bubble chat-bot">
            <p>「<?= h(FAQ_CATEGORY_LABELS[$selectedCategory]) ?>」の質問です。近いものを開いてください。</p>
            <?php foreach ($categoryFaqList as $categoryFaqRow): ?>
              <details class="faq-item">
                <summary>Q. <?= h((string) $categoryFaqRow['question']) ?></summary>
                <div class="faq-answer">A. <?= h_lines((string) $categoryFaqRow['answer']) ?></div>
              </details>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="chat-bubble chat-bot" role="status">この分野の質問は、まだ載っていません。</div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($searchQueryText !== ''): ?>
        <div class="chat-bubble chat-user"><?= h($searchQueryText) ?></div>
        <?php if ($isIgnored): ?>
        <?php elseif ($chatResultCode === 'emergency'): ?>
          <div class="chat-bubble chat-bot chat-care chat-emergency" role="alert"><?= h_lines(msg('命や体に関わる、急いだほうがよい状態かもしれません。少しでも危ないと感じたら、迷わず 119 番に電話して救急車を呼んでください。近くに人がいれば、すぐに助けを求めてください。救急車を呼ぶか迷うときは #7119（救急安心センター。地域によってはつながりません）、子どものことは #8000（子ども医療電話相談）に相談できます。このチャットでは、手当てのしかたにはお答えできません。')) ?></div>
        <?php elseif ($chatResultCode === 'retirement'): ?>
          <div class="chat-bubble chat-bot chat-care" role="status"><?= h_lines(msg('大切なことを書いてくれて、ありがとうございます。退職のことは、一人で抱えたり決めたりせず、まず上司（課長）に相談してください。気持ちや事情を話すことで、ほかの道が見つかることもあります。')) ?></div>
        <?php elseif ($chatResultCode === 'distress'): ?>
          <div class="chat-bubble chat-bot chat-care" role="status"><?= h_lines($distressLevel === 'urgent'
              ? msg('つらい気持ちを書いてくれて、ありがとうございます。一人で抱えずに、上司やシステム管理者に相談してください。今すぐ危ないと感じるときは、ためらわずに周りの人に助けを求めてください。')
              : msg('おつかれさまです。無理をせず、つらいときは一人で抱えずに、上司やシステム管理者に相談してください。')) ?></div>
          <?php if ($matchedFaqList): ?>
            <div class="chat-bubble chat-bot">
              <p class="chat-question">Q. <?= h((string) $matchedFaqList[0]['question']) ?></p>
              <div class="chat-answer"><?= h_lines((string) $matchedFaqList[0]['answer']) ?></div>
            </div>
          <?php endif; ?>
        <?php elseif ($chatResultCode === 'sensitive'): ?>
          <div class="chat-bubble chat-bot chat-care" role="status"><?= h_lines(msg('大切なことを書いてくれて、ありがとうございます。体や家族のことなど、個人的な事情には、このチャットでは詳しくお答えできませんが、一人で抱えずに、上司や信頼できる人に相談してください。報告や締め日のことで困っているときは、課長に相談してください。')) ?></div>
          <?php if ($matchedFaqList): ?>
            <div class="chat-bubble chat-bot">
              <p class="chat-question">Q. <?= h((string) $matchedFaqList[0]['question']) ?></p>
              <div class="chat-answer"><?= h_lines((string) $matchedFaqList[0]['answer']) ?></div>
            </div>
          <?php endif; ?>
        <?php elseif ($chatResultCode === 'abusive'): ?>
          <div class="chat-bubble chat-bot" role="status"><?= h(msg('不適切な表現が含まれているため、返答できません。')) ?></div>
        <?php elseif ($chatResultCode === 'unrelated'): ?>
          <div class="chat-bubble chat-bot" role="status"><?= h(msg('その質問には返答できません。')) ?></div>
        <?php elseif ($chatResultCode === 'opener'): ?>
          <div class="chat-bubble chat-bot" role="status"><?= h(msg('どういったご用件でしょうか。')) ?></div>
        <?php elseif ($chatResultCode === 'vague'): ?>
          <div class="chat-bubble chat-bot" role="status">どのことについて知りたいですか？　分野を選ぶか、「パスワード」「提出」「コメント」のように、知りたいことの言葉を入れてください。</div>
          <?php $renderCategoryChoices(); ?>
        <?php elseif ($matchedFaqList): ?>
          <div class="chat-bubble chat-bot">
            <p class="chat-question">Q. <?= h((string) $matchedFaqList[0]['question']) ?></p>
            <div class="chat-answer"><?= h_lines((string) $matchedFaqList[0]['answer']) ?></div>
            <?php if (count($matchedFaqList) > 1): ?>
              <p class="chat-more-title">ほかに近い質問</p>
              <?php foreach (array_slice($matchedFaqList, 1) as $matchedFaqRow): ?>
                <details class="faq-item">
                  <summary>Q. <?= h((string) $matchedFaqRow['question']) ?></summary>
                  <div class="faq-answer">A. <?= h_lines((string) $matchedFaqRow['answer']) ?></div>
                </details>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="chat-bubble chat-bot" role="status">近い質問は見つかりませんでした。言葉を変えて入れるか、分野から選ぶか、下から質問してください。</div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="chat-bubble chat-bot faq-ask" id="faq-ask" <?= $askErrorTextList ? 'data-has-error="1"' : '' ?> <?= ($searchQueryText === '' && $selectedCategory === null && !$askErrorTextList) || in_array($chatResultCode, ['opener', 'vague', 'unrelated', 'abusive', 'distress', 'sensitive', 'emergency', 'retirement'], true) ? 'hidden' : '' ?>>
        <?php if ($canAsk): ?>
          <p>答えが見つからないときは、この質問を要望・依頼の「質問」としてシステム管理者に送れます。補足があれば書き足してください。返信はホームと「要望・依頼」に出ます。</p>
          <?php foreach ($askErrorTextList as $askErrorText): ?><div class="flash flash-error" role="alert"><?= h_lines($askErrorText) ?></div><?php endforeach; ?>
          <form method="post" action="<?= h(url('/faq.php')) ?>" class="form">
            <?= form_hidden() ?>
            <input type="hidden" name="action" value="ask">
            <input type="hidden" name="screen_code" value="<?= h($linkedScreenCode) ?>">
            <input type="hidden" name="source_path" value="<?= h($sourcePathText) ?>">
            <input type="hidden" name="chat_log_id" value="<?= $chatLogId > 0 ? (int) $chatLogId : '' ?>">
            <label for="faq_ask_question">質問（<?= SUPPORT_REQUEST_BODY_MAX_LENGTH ?> 文字まで。最初の行が件名になります）</label>
            <textarea id="faq_ask_question" name="question" rows="3" maxlength="<?= SUPPORT_REQUEST_BODY_MAX_LENGTH ?>" required><?= h($askedQuestionText) ?></textarea>
            <button type="submit" class="primary">質問として送る</button>
          </form>
        <?php elseif ($isStaff): ?>
          <p>システム管理者・補佐は質問を受ける側のため、ここからは送れません。答えを「＋ 質問を載せる」で足してください。</p>
        <?php else: ?>
          <p>答えが見つからないときは、課長・確認者（課長代理・リーダー）に聞いてください。課長・確認者が、この画面からシステム管理者に質問を送れます。</p>
        <?php endif; ?>
      </div>
    </div>

    <form method="get" action="<?= h(url('/faq.php')) ?>" class="faq-chat-input" role="search">
      <?php foreach ($keptQueryValueByName as $keptName => $keptValue): ?><input type="hidden" name="<?= h($keptName) ?>" value="<?= h($keptValue) ?>"><?php endforeach; ?>
      <label for="faq_search_query" class="visually-hidden">知りたいこと</label>
      <input id="faq_search_query" name="q" type="text" maxlength="<?= FAQ_SEARCH_QUERY_MAX_LENGTH ?>" placeholder="知りたいことを入れる" autocomplete="off" enterkeyhint="send">
      <button type="submit" class="primary">送る</button>
    </form>
  </div>

  <h2>すべての質問（<?= count($faqList) ?> 件）</h2>

  <?php if ($canEdit): ?>
    <div class="faq-standard-import">
      <?php if ($standardMissingCount > 0): ?>
        <form method="post" action="<?= h(url('/faq.php')) ?>" class="inline" data-confirm="<?= h(msg('標準のよくある質問のうち、まだ載っていない質問を足します。よろしいですか？')) ?>">
          <?= form_hidden() ?>
          <button type="submit" name="action" value="import_standard">標準のよくある質問を取り込む（まだ載っていない <?= (int) $standardMissingCount ?> 件）</button>
        </form>
        <p class="note">操作マニュアルの全章から想定される質問をまとめたものです。同じ質問の文がすでにあるものは足しません。取り込んだあとは、ここで直す・消すことができます。</p>
      <?php else: ?>
        <p class="note">標準のよくある質問は、すべて載っています。</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!$faqList): ?>
    <p class="note">まだ質問は載っていません。</p>
  <?php else: ?>
    <?php foreach ($categoryCountList as $categoryCount): ?>
      <h3 class="faq-category-title" id="faq-category-<?= h($categoryCount['code'] === '' ? 'other' : $categoryCount['code']) ?>"><?= h($categoryCount['label']) ?>（<?= (int) $categoryCount['count'] ?>）</h3>
      <div class="faq-list">
      <?php foreach (faq_in_category($faqList, $categoryCount['code']) as $faqRow): ?>
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
    <?php endforeach; ?>
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
      <label for="faq_category">分野（チャットで「分野を選ぶ」ときの分け方）</label>
      <select id="faq_category" name="category">
        <?php foreach (FAQ_CATEGORY_LABELS as $categoryCode => $categoryLabel): ?>
          <option value="<?= h((string) $categoryCode) ?>" <?= (string) $categoryCode === $typedValueByName['category'] ? 'selected' : '' ?>><?= h($categoryLabel) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="faq_question">質問（<?= FAQ_QUESTION_MAX_LENGTH ?> 文字まで）</label>
      <input id="faq_question" name="question" type="text" maxlength="<?= FAQ_QUESTION_MAX_LENGTH ?>" value="<?= h($typedValueByName['question']) ?>" required>
      <label for="faq_answer">答え（<?= FAQ_ANSWER_MAX_LENGTH ?> 文字まで）</label>
      <textarea id="faq_answer" name="answer" rows="5" maxlength="<?= FAQ_ANSWER_MAX_LENGTH ?>" required><?= h($typedValueByName['answer']) ?></textarea>
      <button type="submit" class="primary">保存</button>
    </form>
  </details>
  <?php endif; ?>
</section>
