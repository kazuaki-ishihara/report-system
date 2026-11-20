<?php
/** @var bool $canSeeAskedUser 聞いた人を出すか（管理者・補佐とも出す。K-051） */
/** @var int $periodDays 見る期間（日） */
/** @var string $resultFilter 一覧の絞り込み（結果のコード、unanswered は答えられなかったもの、空はすべて） */
/** @var int $totalCount 期間の中の記録の数 */
/** @var bool $isTruncated 読む上限に達したか */
/** @var array<string, int> $countByResult */
/** @var array<string, int> $countByFeedback */
/** @var int $ignoredCount 返事をしなかった数 */
/** @var array<int, array{text: string, count: int, last_at: string, log_id: int}> $unansweredGroupList 答えられなかった質問（多い順） */
/** @var array<int, array{text: string, count: int, last_at: string, log_id: int}> $unrelatedGroupList 関係のない質問とした文（多い順） */
/** @var array<int, array{solved: int, unsolved: int}> $feedbackCountByFaqId 答えごとの反応（解決しないが多い順） */
/** @var array<int, array<string, int|float|string|null>> $abusiveLogRowList 不適切な表現（新しい順） */
/** @var array<int, array<string, int|float|string|null>> $distressLogRowList 心の不調らしい文（未対応 → 命に関わる言葉 → 新しい順） */
/** @var bool $canReceiveNotice 心の不調・退職に関わる質問を見られるか（システム管理者だけ） */
/** @var array<int, array<string, int|float|string|null>> $listedLogRowList 一覧（新しい順。会話を選んだときは、その会話の発言を古い順） */
/** @var array<string, int> $conversationCountById 会話ごとの発言の数（G-25 §2.14） */
/** @var string $conversationFilter 選んだ会話の番号（選んでいなければ空） */
/** @var array<int|string, string> $faqQuestionById */

$askedUserText = static function (array $logRow) use ($canSeeAskedUser): string {
    if (!$canSeeAskedUser) {
        return FAQ_CHAT_LOG_ROLE_LABELS[$logRow['role']] ?? '';
    }
    $nameText = $logRow['display_name'] !== null ? $logRow['display_name'] . '（' . $logRow['login_id'] . '）' : '（消したユーザー）';
    return $nameText . ' ' . (FAQ_CHAT_LOG_ROLE_LABELS[$logRow['role']] ?? '');
};
$faqQuestionText = static function (?int $faqId) use ($faqQuestionById): string {
    if ($faqId === null) {
        return '';
    }
    return $faqQuestionById[$faqId] ?? '（消した質問 #' . $faqId . '）';
};
?>
<section class="card wide">
  <h1>よくある質問の記録</h1>
  <p class="note"><?= h_phrases('よくある質問のチャットで聞かれた質問と、返した答え・反応です。答えられなかった質問は「よくある質問に載せる」で載せ、解決しないが多い答えは文を直してください。記録は 1 年で消えます。聞いた人は、不適切な表現への対応のためだけに使ってください。') ?></p>

  <form method="get" action="<?= h(url('/admin/faq_logs.php')) ?>" class="filters">
    <select name="days" aria-label="期間">
      <?php foreach (FAQ_CHAT_LOG_PERIOD_DAYS_LIST as $periodOption): ?>
        <option value="<?= (int) $periodOption ?>" <?= $periodDays === $periodOption ? 'selected' : '' ?>>直近 <?= (int) $periodOption ?> 日</option>
      <?php endforeach; ?>
    </select>
    <select name="result" aria-label="結果">
      <option value="">結果: すべて</option>
      <option value="unanswered" <?= $resultFilter === 'unanswered' ? 'selected' : '' ?>>答えられなかったもの（見つからない・解決しない・送った）</option>
      <?php foreach (FAQ_CHAT_LOG_RESULT_LABELS as $resultCode => $resultLabel): ?>
        <option value="<?= h($resultCode) ?>" <?= $resultFilter === $resultCode ? 'selected' : '' ?>><?= h($resultLabel) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">表示</button>
  </form>

  <h2>集計（<?= (int) $totalCount ?> 件<?= $isTruncated ? '。新しい ' . FAQ_CHAT_LOG_READ_MAX_COUNT . ' 件だけ' : '' ?>）</h2>
  <div class="faq-log-summary">
    <?php foreach (FAQ_CHAT_LOG_RESULT_LABELS as $resultCode => $resultLabel): ?>
      <span class="tag<?= in_array($resultCode, ['no_match', 'abusive'], true) && $countByResult[$resultCode] > 0 ? ' tag-open' : '' ?>"><?= h($resultLabel) ?> <?= (int) $countByResult[$resultCode] ?></span>
    <?php endforeach; ?>
    <span class="tag">返事をしない <?= (int) $ignoredCount ?></span>
    <?php foreach (['solved', 'unsolved', 'asked'] as $feedbackCode): ?>
      <span class="tag<?= $feedbackCode === 'unsolved' && $countByFeedback[$feedbackCode] > 0 ? ' tag-changed' : '' ?>"><?= h(FAQ_CHAT_LOG_FEEDBACK_LABELS[$feedbackCode]) ?> <?= (int) $countByFeedback[$feedbackCode] ?></span>
    <?php endforeach; ?>
  </div>

  <?php if ($canReceiveNotice): ?>
  <h2 id="distress">知らせる質問（<?= is_admin($user) ? '心の不調・退職・人への暴力・ID やパスワードを求める質問' : '人への暴力・ID やパスワードを求める質問' ?>）</h2>
  <p class="note"><?= h_phrases('ID やパスワードを求める質問は、なりすましで聞き出そうとしているおそれがあります。聞いた人に心当たりがあるか確かめ、パスワードは教えないでください。') ?></p>
  <p class="note"><?= h_phrases('チャットに、命に関わる言葉や心配な言葉（つらい・限界・眠れない など）が入った文と、退職に関わる質問です。心の不調らしい文には、気づかいの言葉と「上司やシステム管理者に相談してください」を返しています。自分の退職の質問には、寄り添う言葉と「まず上司（課長）に相談してください」を返しています（手続きは示しません。部下の退職の手続きを聞く質問には、手続きの答えを返しています）。言葉だけで見分けているので、軽い言い方も入ります。内容を確かめ、必要なら本人の上司などと連携して対応し、「対応した」を押してください。健康に関わる情報なので、対応に必要な人の間だけで扱ってください。') ?></p>
  <?php if (!$distressLogRowList): ?>
    <p class="note">ありません。</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>日時</th><th>聞いた人</th><th>段階</th><th>文</th><th>対応</th></tr></thead>
      <tbody>
      <?php foreach ($distressLogRowList as $logRow): ?>
        <tr>
          <td><?= h(display_datetime((string) $logRow['asked_at'])) ?></td>
          <td><?= h($askedUserText($logRow)) ?></td>
          <td><span class="tag<?= in_array($logRow['distress_level'], ['urgent', 'credential', 'violence', 'sabotage'], true) ? ' tag-open' : ($logRow['distress_level'] === 'concern' ? ' tag-changed' : '') ?>"><?= h(FAQ_CHAT_LOG_DISTRESS_LABELS[(string) $logRow['distress_level']] ?? '') ?></span></td>
          <td><?= h($logRow['question_text']) ?></td>
          <td>
            <?php if ($logRow['handled_at'] === null): ?>
              <form method="post" action="<?= h(url('/admin/faq_logs.php')) ?>" class="inline" data-confirm="<?= h(msg('この文に対応したことを記録します。よろしいですか？')) ?>">
                <?= form_hidden() ?><input type="hidden" name="log_id" value="<?= (int) $logRow['id'] ?>">
                <button type="submit" name="action" value="distress_handled" class="small">対応した</button>
              </form>
            <?php else: ?>
              対応済み（<?= h(display_datetime((string) $logRow['handled_at'])) ?> <?= h((string) ($logRow['handled_by_name'] ?? '')) ?>）
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php endif; ?>

  <h2>答えられなかった質問（多い順）</h2>
  <p class="note"><?= h_phrases('見つからなかった質問と、「解決しない」「質問として送った」になった質問です。同じ文はまとめています。') ?></p>
  <?php if (!$unansweredGroupList): ?>
    <p class="note">ありません。</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>質問</th><th>回数</th><th>最後</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($unansweredGroupList as $questionGroup): ?>
        <tr>
          <td><?= h($questionGroup['text']) ?></td>
          <td><?= (int) $questionGroup['count'] ?></td>
          <td><?= h(display_datetime($questionGroup['last_at'])) ?></td>
          <td><a href="<?= h(url('/faq.php?draft_log=' . (int) $questionGroup['log_id'] . '#faq-form')) ?>">よくある質問に載せる</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h2>「解決しない」が多い答え</h2>
  <p class="note"><?= h_phrases('答えを読んで「解決した」「解決しない」を押した数です。解決しないが多い答えは、文を直す候補です。') ?></p>
  <?php if (!$feedbackCountByFaqId): ?>
    <p class="note">ありません。</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>よくある質問</th><th>解決しない</th><th>解決した</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($feedbackCountByFaqId as $feedbackFaqId => $feedbackCount): ?>
        <tr>
          <td><?= h($faqQuestionText((int) $feedbackFaqId)) ?></td>
          <td><?= (int) $feedbackCount['unsolved'] ?></td>
          <td><?= (int) $feedbackCount['solved'] ?></td>
          <td><?php if (isset($faqQuestionById[$feedbackFaqId])): ?><a href="<?= h(url('/faq.php?edit=' . (int) $feedbackFaqId . '#faq-form')) ?>">直す</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h2>関係のない質問とした文（多い順）</h2>
  <p class="note"><?= h_phrases('システムの質問なのに、ここに出ているものがあれば、言葉が足りていません（開発担当にシステムの言葉の表へ足すよう伝えてください）。') ?></p>
  <?php if (!$unrelatedGroupList): ?>
    <p class="note">ありません。</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>文</th><th>回数</th><th>最後</th></tr></thead>
      <tbody>
      <?php foreach ($unrelatedGroupList as $questionGroup): ?>
        <tr><td><?= h($questionGroup['text']) ?></td><td><?= (int) $questionGroup['count'] ?></td><td><?= h(display_datetime($questionGroup['last_at'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h2 id="abusive">不適切な表現</h2>
  <?php if (!$abusiveLogRowList): ?>
    <p class="note">ありません。</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>日時</th><th>聞いた人</th><th>文</th><th>返事</th></tr></thead>
      <tbody>
      <?php foreach ($abusiveLogRowList as $logRow): ?>
        <tr>
          <td><?= h(display_datetime((string) $logRow['asked_at'])) ?></td>
          <td><?= h($askedUserText($logRow)) ?></td>
          <td><?= h($logRow['question_text']) ?></td>
          <td><?= (int) $logRow['is_ignored'] === 1 ? '返事をしない' : '返答できない' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($conversationFilter !== ''): ?>
  <h2 id="conversation">この会話の発言（古い順。<?= count($listedLogRowList) ?> 件）</h2>
  <p><a href="<?= h(url('/admin/faq_logs.php?days=' . (int) $periodDays)) ?>">← 新しい順の一覧に戻る</a></p>
  <?php else: ?>
  <h2>新しい順（<?= count($listedLogRowList) ?> 件<?= count($listedLogRowList) >= 300 ? '。最大 300 件' : '' ?>）</h2>
  <p class="note"><?= h_phrases('「会話」は、同じ人が 10 分以内に続けて入れた発言のまとまりです。押すと、その会話の発言を順に出します。') ?></p>
  <?php endif; ?>
  <?php if (!$listedLogRowList): ?>
    <p class="note">ありません。</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>日時</th><th>聞いた人</th><th>画面</th><th>質問・分野</th><th>結果</th><th>出した答え</th><th>反応</th><th>会話</th></tr></thead>
      <tbody>
      <?php foreach ($listedLogRowList as $logRow): ?>
        <?php $shownFaqIdList = array_values(array_filter(array_map('intval', explode(',', (string) $logRow['shown_faq_ids'])))); ?>
        <tr>
          <td><?= h(display_datetime((string) $logRow['asked_at'])) ?></td>
          <td><?= h($askedUserText($logRow)) ?></td>
          <td><?= h((string) $logRow['screen_code']) ?></td>
          <td><?= $logRow['result'] === 'category' ? '分野: ' . h(FAQ_CATEGORY_LABELS[(string) $logRow['category']] ?? '') : h($logRow['question_text']) ?></td>
          <td><?= h(FAQ_CHAT_LOG_RESULT_LABELS[$logRow['result']] ?? $logRow['result']) ?><?= (int) $logRow['is_ignored'] === 1 ? '（返事をしない）' : '' ?></td>
          <td><?= $logRow['result'] === 'matched' && $shownFaqIdList ? h($faqQuestionText($shownFaqIdList[0])) . (count($shownFaqIdList) > 1 ? ' ほか ' . (count($shownFaqIdList) - 1) . ' 件' : '') : ($logRow['result'] === 'category' ? count($shownFaqIdList) . ' 件の質問' : '') ?></td>
          <td>
            <?= h(FAQ_CHAT_LOG_FEEDBACK_LABELS[$logRow['feedback']] ?? '') ?>
            <?php if ($logRow['feedback'] !== '' && $logRow['feedback_faq_id'] !== null): ?>（<?= h($faqQuestionText((int) $logRow['feedback_faq_id'])) ?>）<?php endif; ?>
            <?php if ($logRow['request_id'] !== null): ?> <a href="<?= h(url('/requests/view.php?id=' . (int) $logRow['request_id'])) ?>">#<?= (int) $logRow['request_id'] ?></a><?php endif; ?>
          </td>
          <td>
            <?php $conversationId = (string) ($logRow['conversation_id'] ?? ''); ?>
            <?php if ($conversationFilter === '' && $conversationId !== '' && ($conversationCountById[$conversationId] ?? 0) > 1): ?>
              <a href="<?= h(url('/admin/faq_logs.php?days=' . (int) $periodDays . '&conversation=' . $conversationId . '#conversation')) ?>">会話（<?= (int) $conversationCountById[$conversationId] ?> 件）</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
