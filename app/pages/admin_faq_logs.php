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

// ---- メンテナンス用の CSV を取り込む（§2.19。読んで計画を作るだけ。計画はセッションに置き、「反映する」で使う） ----
$faqCsvPlan = null;
$faqCsvErrorTextList = [];
if (is_post() && post_str('action') === 'faq_csv_preview') {
    $uploadedFile = $_FILES['faq_csv'] ?? null;
    if (!is_array($uploadedFile) || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $uploadedFile['tmp_name'])) {
        $faqCsvErrorTextList[] = msg('CSV のファイルを選んでください');
    } elseif ((int) $uploadedFile['size'] > FAQ_CSV_MAX_BYTES) {
        $faqCsvErrorTextList[] = msg('ファイルが大きすぎます（2 MB まで）');
    } else {
        $uploadedCsvText = (string) file_get_contents((string) $uploadedFile['tmp_name']);
        if (preg_match('//u', $uploadedCsvText) !== 1) {   // UTF-8 として正しいか
            $faqCsvErrorTextList[] = msg('CSV は UTF-8 で保存してください（出力したファイルを Excel で直したときは「CSV UTF-8」で保存）');
        } else {
            $faqCsvPlan = faq_csv_plan($uploadedCsvText);
            $faqCsvPlan['token'] = random_token(16);
            $_SESSION['faq_csv_plan'] = ['token' => $faqCsvPlan['token'], 'createdAt' => time(), 'plan' => $faqCsvPlan];
        }
    }
} elseif (is_post() && post_str('action') === 'faq_csv_apply') {
    rk_begin('faq_csv.apply', (int) $user['id']);
    $storedPlan = $_SESSION['faq_csv_plan'] ?? null;
    if (!is_array($storedPlan) || !hash_equals((string) $storedPlan['token'], post_str('plan_token')) || time() - (int) $storedPlan['createdAt'] > 1800
        || $storedPlan['plan']['errorTextList']) {
        rk_cancel();
        flash('error', msg('取り込んだ内容が見つからないか、30 分を過ぎました。もう一度取り込んでください'));
        redirect('/admin/faq_logs.php#faq-csv');
    }
    $appliedCount = faq_csv_apply($storedPlan['plan'], (int) $user['id']);
    unset($_SESSION['faq_csv_plan']);
    if ($appliedCount === null) {
        rk_cancel();
        flash('error', msg('取り込んで確かめたあとに、画面で直された・消された質問があります。何も変えていません。もう一度 CSV を出して直してください'));
        redirect('/admin/faq_logs.php#faq-csv');
    }
    audit_log('admin', 'faq:csv_import;added=' . $appliedCount['added'] . ';updated=' . $appliedCount['updated'] . ';deleted=' . $appliedCount['deleted']);
    flash('success', msg('よくある質問を反映しました（足した {added} 件・直した {updated} 件・消した {deleted} 件）', $appliedCount));
    rk_done('/admin/faq_logs.php#faq-csv');
} elseif (is_post()) {
    // ---- 心の不調らしい文に「対応した」（§2.8） ----
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
$periodDays = (int) get_str('days', '30');
if (!in_array($periodDays, FAQ_CHAT_LOG_PERIOD_DAYS_LIST, true)) {
    $periodDays = 30;
}
$resultFilter = get_str('result');
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
$conversationFilter = preg_match('/\A[0-9a-f]{16}\z/', get_str('conversation')) === 1 ? get_str('conversation') : '';
if ($conversationFilter !== '') {
    $listedLogRowList = array_reverse(array_values(array_filter($logRowList, static function (array $logRow) use ($conversationFilter): bool {
        return (string) $logRow['conversation_id'] === $conversationFilter;
    })));
}

// ---- CSV で出す（§2.19）: 記録（?csv=log）と、メンテナンス用（?csv=maintenance。載っている質問・まだ載っていない標準の質問・答えられなかった質問） ----
$csvKind = get_str('csv');
if ($csvKind === 'log' || $csvKind === 'maintenance') {
    $csvTitleLines = ['社外秘', '出力者: ' . $user['login_id'] . '  出力日時: ' . date('Y-m-d H:i') . '  期間: 直近 ' . $periodDays . ' 日'];
    if ($csvKind === 'log') {
        audit_log('export', 'export:faq_chat_logs;days=' . $periodDays);
        $csvBody = export_csv(array_merge([$csvTitleLines[0], 'よくある質問の記録'], array_slice($csvTitleLines, 1)), FAQ_LOG_CSV_COLUMNS, faq_csv_log_rows($logRowList));
        $csvFileName = 'faq_chat_log_' . date('Ymd-Hi') . '.csv';
    } else {
        audit_log('export', 'export:faq_maintenance;days=' . $periodDays);
        $csvBody = export_csv(
            array_merge([$csvTitleLines[0], 'よくある質問のメンテナンス用（答えを書いた行を足し、変えた行を直し、「消す」に 1 を入れた行を消します。番号と直した日時は変えないでください）'], array_slice($csvTitleLines, 1)),
            FAQ_CSV_COLUMNS,
            faq_csv_maintenance_rows($groupByQuestion(array_filter($logRowList, static function (array $logRow): bool {
                return $logRow['is_unanswered'] && $logRow['result'] !== 'category';
            }), FAQ_CSV_UNANSWERED_MAX_COUNT), $feedbackCountByFaqId)
        );
        $csvFileName = 'faq_maintenance_' . date('Ymd-Hi') . '.csv';
    }
    send_security_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $csvFileName . '"');
    echo $csvBody;
    exit;
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
    'faqCsvPlan'           => $faqCsvPlan,
    'faqCsvErrorTextList'  => $faqCsvErrorTextList,
]);
