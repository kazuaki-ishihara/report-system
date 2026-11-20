<?php

declare(strict_types=1);

// ====================================================================
// よくある質問の記録（G-25 §2.7）。チャットで聞かれた質問と返した答えを見て、よくある質問の改善に使う。
// 見られるのはシステム管理者と補佐。聞いた人（名前）も、どちらにも出す（K-051。K-043 の例外）。
// ====================================================================

$user = page_start();
if (!faq_chat_log_can_view($user)) {
    error_page(403);
}
faq_ensure_category_column();
faq_chat_log_ensure_table();
$canSeeAskedUser = true;   // 管理者・補佐とも聞いた人を見られる（K-051）
// 知らせる質問: システム管理者はすべて、補佐は ID・パスワードを求める質問だけ（心の不調・退職は、補佐には欄にも一覧にも出さない。K-054・K-057）
$noticeLevels = faq_chat_log_notice_levels($user);
$canReceiveNotice = $noticeLevels !== [];

// ---- 心の不調らしい文に「対応した」（§2.8） ----
if (is_post()) {
    if (post_str('action') !== 'distress_handled') {
        error_page(400);
    }
    if (!$canReceiveNotice) {
        error_page(403);
    }
    rk_begin('faq_chat_log.distress_handled', (int) $user['id']);
    $handledLogId = input_id('log_id', 'POST');
    $handledLogRow = db_one('SELECT id, distress_level FROM faq_chat_logs WHERE id = ? AND distress_level IS NOT NULL', [$handledLogId]);
    if ($handledLogRow === null) {
        error_page(404);
    }
    if (!in_array($handledLogRow['distress_level'], $noticeLevels, true)) {
        error_page(403);   // 補佐は、心の不調・退職の記録には対応できない
    }
    db_exec('UPDATE faq_chat_logs SET handled_at = ?, handled_by = ? WHERE id = ? AND handled_at IS NULL', [now(), (int) $user['id'], $handledLogId]);
    audit_log('admin', 'faq_chat_log:' . $handledLogId . ':distress_handled');
    flash('success', msg('対応したことを記録しました'));
    rk_done('/admin/faq_logs.php#distress');
}

// ---- 絞り込み ----
const FAQ_CHAT_LOG_PERIOD_DAYS_LIST = [7, 30, 90, 365];
$periodDays = (int) ($_GET['days'] ?? 30);
if (!in_array($periodDays, FAQ_CHAT_LOG_PERIOD_DAYS_LIST, true)) {
    $periodDays = 30;
}
$resultFilter = is_string($_GET['result'] ?? null) ? (string) $_GET['result'] : '';
if ($resultFilter !== '' && $resultFilter !== 'unanswered' && !isset(FAQ_CHAT_LOG_RESULT_LABELS[$resultFilter])) {
    $resultFilter = '';
}

$logRowList = db_all(
    'SELECT l.*, u.display_name, u.login_id FROM faq_chat_logs l LEFT JOIN users u ON u.id = l.user_id
      WHERE l.asked_at >= ? ORDER BY l.id DESC LIMIT ' . FAQ_CHAT_LOG_READ_MAX_COUNT,
    [date('Y-m-d H:i:s', time() - $periodDays * 86400)]
);
$logRowList = array_values(array_filter($logRowList, static function (array $logRow) use ($noticeLevels): bool {
    return $logRow['distress_level'] === null || in_array($logRow['distress_level'], $noticeLevels, true);   // 見られない理由の記録は、一覧にも出さない
}));
foreach ($logRowList as $logIndex => $logRow) {
    $logRowList[$logIndex]['question_text'] = faq_chat_log_display_text($logRow);
    $logRowList[$logIndex]['is_hidden'] = faq_chat_log_is_hidden($logRow);
    $logRowList[$logIndex]['is_unanswered'] = $logRow['result'] === 'no_match' || in_array($logRow['feedback'], ['unsolved', 'asked'], true);
}
$faqQuestionById = array_column(db_all('SELECT id, question FROM faq_entries'), 'question', 'id');

// ---- 集計 ----
$countByResult = array_fill_keys(array_keys(FAQ_CHAT_LOG_RESULT_LABELS), 0);
$countByFeedback = array_fill_keys(array_keys(FAQ_CHAT_LOG_FEEDBACK_LABELS), 0);
$ignoredCount = 0;
foreach ($logRowList as $logRow) {
    $countByResult[$logRow['result']] = ($countByResult[$logRow['result']] ?? 0) + 1;
    $countByFeedback[$logRow['feedback']] = ($countByFeedback[$logRow['feedback']] ?? 0) + 1;
    $ignoredCount += (int) $logRow['is_ignored'];
}

/**
 * 同じ文（そろえた形）ごとにまとめ、多い順に並べる。
 *
 * @param array<int, array<string, int|float|string|null>> $targetLogRowList
 * @return array<int, array{text: string, count: int, last_at: string, log_id: int}>
 */
$groupByQuestion = static function (array $targetLogRowList, int $limit): array {
    $groupByKey = [];
    foreach ($targetLogRowList as $logRow) {
        if ($logRow['question_text'] === '' || $logRow['is_hidden']) {
            continue;   // 要配慮の内容は、まとめにも出さない
        }
        $groupKey = faq_normalize_text($logRow['question_text']);
        if (!isset($groupByKey[$groupKey])) {
            $groupByKey[$groupKey] = ['text' => $logRow['question_text'], 'count' => 0, 'last_at' => (string) $logRow['asked_at'], 'log_id' => (int) $logRow['id']];
        }
        $groupByKey[$groupKey]['count']++;
    }
    $groupList = array_values($groupByKey);
    usort($groupList, static function (array $leftGroup, array $rightGroup): int {
        return [$rightGroup['count'], $rightGroup['last_at']] <=> [$leftGroup['count'], $leftGroup['last_at']];
    });
    return array_slice($groupList, 0, $limit);
};

$unansweredGroupList = $groupByQuestion(array_filter($logRowList, static function (array $logRow): bool {
    return $logRow['is_unanswered'] && $logRow['result'] !== 'category';
}), 50);
$unrelatedGroupList = $groupByQuestion(array_filter($logRowList, static function (array $logRow): bool {
    return $logRow['result'] === 'unrelated';
}), 30);

// 答えごとの反応（解決した・解決しない）。解決しないが多い答えは、文を直す候補
$feedbackCountByFaqId = [];
foreach ($logRowList as $logRow) {
    if ($logRow['feedback_faq_id'] === null || !in_array($logRow['feedback'], ['solved', 'unsolved'], true)) {
        continue;
    }
    $feedbackFaqId = (int) $logRow['feedback_faq_id'];
    $feedbackCountByFaqId[$feedbackFaqId] = $feedbackCountByFaqId[$feedbackFaqId] ?? ['solved' => 0, 'unsolved' => 0];
    $feedbackCountByFaqId[$feedbackFaqId][$logRow['feedback']]++;
}
uasort($feedbackCountByFaqId, static function (array $leftCount, array $rightCount): int {
    return [$rightCount['unsolved'], $leftCount['solved']] <=> [$leftCount['unsolved'], $rightCount['solved']];
});

$abusiveLogRowList = array_values(array_filter($logRowList, static function (array $logRow): bool {
    return $logRow['result'] === 'abusive';
}));
// 知らせる質問（心の不調らしい文・退職に関わる質問）: 期間にかかわらず未対応のものと、期間の中の対応済みのもの（未対応 → 命に関わる言葉 → 新しい順）
$distressLogRowList = !$canReceiveNotice ? [] : db_all(
    "SELECT l.*, u.display_name, u.login_id, h.display_name AS handled_by_name FROM faq_chat_logs l
       LEFT JOIN users u ON u.id = l.user_id LEFT JOIN users h ON h.id = l.handled_by
      WHERE l.distress_level IN ('" . implode("', '", $noticeLevels) . "') AND (l.handled_at IS NULL OR l.asked_at >= ?)
      ORDER BY l.handled_at IS NOT NULL, l.distress_level <> 'urgent', l.id DESC LIMIT 200",
    [date('Y-m-d H:i:s', time() - $periodDays * 86400)]
);
foreach ($distressLogRowList as $distressIndex => $distressLogRow) {
    $distressLogRowList[$distressIndex]['question_text'] = faq_chat_log_display_text($distressLogRow);
}

// ---- 一覧（絞り込み） ----
$listedLogRowList = array_values(array_filter($logRowList, static function (array $logRow) use ($resultFilter): bool {
    if ($resultFilter === 'unanswered') {
        return $logRow['is_unanswered'];
    }
    return $resultFilter === '' || $logRow['result'] === $resultFilter;
}));
// 会話（G-25 §2.14）: 会話ごとの発言の数。?conversation=<番号> で、その会話の発言だけを古い順に出す（見られない理由の記録は、ここでも出さない）
$conversationCountById = array_count_values(array_filter(array_map('strval', array_column($logRowList, 'conversation_id')), 'strlen'));
$conversationFilter = is_string($_GET['conversation'] ?? null) && preg_match('/^[0-9a-f]{16}$/', (string) $_GET['conversation']) === 1 ? (string) $_GET['conversation'] : '';
if ($conversationFilter !== '') {
    $listedLogRowList = array_reverse(array_values(array_filter($logRowList, static function (array $logRow) use ($conversationFilter): bool {
        return (string) $logRow['conversation_id'] === $conversationFilter;
    })));
}

audit_log('admin', 'faq_chat_logs:view;days=' . $periodDays);
render('admin_faq_logs', [
    'title'                => 'よくある質問の記録',
    'user'                 => $user,
    'canSeeAskedUser'      => $canSeeAskedUser,
    'periodDays'           => $periodDays,
    'resultFilter'         => $resultFilter,
    'totalCount'           => count($logRowList),
    'isTruncated'          => count($logRowList) >= FAQ_CHAT_LOG_READ_MAX_COUNT,
    'countByResult'        => $countByResult,
    'countByFeedback'      => $countByFeedback,
    'ignoredCount'         => $ignoredCount,
    'unansweredGroupList'  => $unansweredGroupList,
    'unrelatedGroupList'   => $unrelatedGroupList,
    'feedbackCountByFaqId' => array_slice($feedbackCountByFaqId, 0, 30, true),
    'abusiveLogRowList'    => array_slice($abusiveLogRowList, 0, 100),
    'distressLogRowList'   => $distressLogRowList,
    'canReceiveNotice'     => $canReceiveNotice,
    'listedLogRowList'     => array_slice($listedLogRowList, 0, 300),
    'conversationCountById' => $conversationCountById,
    'conversationFilter'   => $conversationFilter,
    'faqQuestionById'      => $faqQuestionById,
]);
