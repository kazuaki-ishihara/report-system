<?php

declare(strict_types=1);

// ====================================================================
// よくある質問（G-25）の答え方を確かめる道具（ローカル検証環境専用）。
//
//   php tools/lib/faq_check.php self                   標準のよくある質問が、自分の質問の文で自分を見つけられるか
//   php tools/lib/faq_check.php ask "質問の文" [ID]     1 つの質問の判定（探す言葉・近い答え・関係のない質問か）
//   php tools/lib/faq_check.php logs [最初の番号]       この端末のチャットの記録を、今の作りで判定し直す
//   php tools/lib/faq_check.php csv <ファイル>          ほかの端末から受け取った記録（3_記録を書き出す.bat の CSV）を判定し直す
//   php tools/lib/faq_check.php sync                   この端末の DB に、標準のよくある質問の追加・答えの変更を写す
//
// logs・csv は、聞いた人に見える範囲（管理者・補佐だけの分野を除く）で判定する。
// 要配慮・緊急の文（だれにも見せない決まり。G-25 §2.9）は、判定には使うが、表示は伏せる。
// config.local.php の allow_dev_seed が true で、https でない環境（ローカル）だけで動く。
// ====================================================================

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_DIR . '/lib/faq_standard.php';

$localConfigPath = APP_DIR . '/config/config.local.php';
$localConfig = is_file($localConfigPath) ? (array) require $localConfigPath : [];
if (empty($localConfig['allow_dev_seed']) || cfg('https')) {
    fwrite(STDERR, "ローカル検証環境（config.local.php の allow_dev_seed が true）でだけ動きます。\n");
    exit(1);
}

$commandName = $argv[1] ?? '';

/**
 * 今の作りで、その質問をどう扱うか（faq_answer_chat() と同じ順。記録は残さない）。
 *
 * @param array<int, array<string, mixed>> $visibleFaqList 聞いた人に見えるよくある質問
 */
function faq_check_judge(string $questionText, array $visibleFaqList): string
{
    // 判定はチャットと同じ関数（faq_classify_question()）。ここでは結果を読みやすい文にするだけ
    $classification = faq_classify_question($questionText, $visibleFaqList);
    $matchedFaqList = $classification['matches'];
    switch ($classification['code']) {
        case 'emergency':
            return '緊急（119 番）';
        case 'distress':
            return '心の不調（' . $classification['distressLevel'] . '）' . ($matchedFaqList ? ' + ' . $matchedFaqList[0]['question'] : '');
        case 'retirement':
            return '自分の退職（相談を促す）';
        case 'sensitive':
            return '要配慮（寄り添う返事）';
        case 'violence':
            return '人への暴力（カウンセリングをすすめる）';
        case 'abusive':
            return '不適切な表現';
        case 'opener':
            return '呼びかけ（ご用件を聞く）';
        case 'vague':
            return 'あいまい（分野を選ぶ）';
        case 'unrelated':
            return '関係のない質問';
        case 'no_match':
            return '見つからない';
    }
    $answerText = '答え: ' . $matchedFaqList[0]['question'];
    if (count($matchedFaqList) > 1) {
        $answerText .= ' ／ 2 番目: ' . $matchedFaqList[1]['question'];
    }
    return $answerText . (faq_mentions_death($questionText) ? '（お悔やみを添える）' : '');
}

/** 判定のあとに付ける、知らせの印 */
function faq_check_notice_mark(string $questionText): string
{
    if (faq_is_credential_request($questionText)) {
        return ' [知らせ: ID・パスワード]';
    }
    if (faq_is_retirement($questionText)) {
        return ' [知らせ: 退職]';
    }
    return '';
}

/** 聞いた人（ログイン ID）に見えるよくある質問。この端末に同じ ID がいなければ、すべて */
function faq_check_visible_list(array $allFaqList, string $loginId): array
{
    $askedUser = $loginId !== '' ? db_one('SELECT * FROM users WHERE login_id = ?', [$loginId]) : null;
    return $askedUser !== null ? faq_visible_entries($allFaqList, $askedUser) : $allFaqList;
}

/** 表示してよい文か（要配慮・緊急の文は、だれにも見せない） */
function faq_check_shown_text(string $questionText): string
{
    $isHidden = faq_is_emergency($questionText) || faq_is_sensitive($questionText);
    return $isHidden ? '（要配慮の内容のため表示しません）' : $questionText;
}

$allFaqList = db_all('SELECT * FROM faq_entries ORDER BY sort_order, id');

if ($commandName === 'self') {
    // ---- 標準のよくある質問が、自分の質問の文で自分を見つけられるか（DB を使わず、標準の一覧だけで確かめる） ----
    $standardFaqList = [];
    foreach (FAQ_STANDARD_ENTRIES as $entryIndex => [$standardCategory, $standardQuestion, $standardAnswer]) {
        $standardFaqList[] = ['id' => $entryIndex + 1, 'category' => $standardCategory, 'question' => $standardQuestion, 'answer' => $standardAnswer];
        if (faq_is_abusive($standardQuestion) || faq_is_abusive($standardAnswer)) {
            echo "不適切な表現と取り違える: $standardQuestion\n";
        }
    }
    $selfMissCount = 0;
    foreach ($standardFaqList as $standardFaqRow) {
        $matchedFaqList = faq_search($standardFaqRow['question'], $standardFaqList);
        $isSelfFound = $matchedFaqList !== [] && $matchedFaqList[0]['id'] === $standardFaqRow['id']
            && !faq_is_vague($standardFaqRow['question']) && !faq_is_unrelated($standardFaqRow['question'], $standardFaqList);
        if (!$isSelfFound) {
            $selfMissCount++;
            echo '自分を見つけられない: ', $standardFaqRow['question'], ' => ', $matchedFaqList ? $matchedFaqList[0]['question'] : '-', "\n";
        }
    }
    echo '標準のよくある質問: ', count($standardFaqList), " 件。自分を見つけられないもの: $selfMissCount 件\n";
    exit($selfMissCount === 0 ? 0 : 1);
}

if ($commandName === 'ask') {
    // ---- 1 つの質問の判定 ----
    $questionText = (string) ($argv[2] ?? '');
    if ($questionText === '') {
        fwrite(STDERR, "使い方: php tools/lib/faq_check.php ask \"質問の文\" [聞いた人のログイン ID]\n");
        exit(1);
    }
    $visibleFaqList = faq_check_visible_list($allFaqList, (string) ($argv[3] ?? ''));
    echo '探す言葉: ', implode(' | ', faq_search_terms($questionText)), "\n";
    foreach (array_slice(faq_search($questionText, $visibleFaqList), 0, 3) as $matchedFaqRow) {
        echo '近い答え: ', round((float) $matchedFaqRow['match_ratio'], 2), ' ', $matchedFaqRow['question'], "\n";
    }
    echo '判定: ', faq_check_judge($questionText, $visibleFaqList), faq_check_notice_mark($questionText), "\n";
    exit(0);
}

if ($commandName === 'logs') {
    // ---- この端末のチャットの記録を、今の作りで判定し直す ----
    $firstLogId = (int) ($argv[2] ?? 1);
    $faqQuestionById = array_column($allFaqList, 'question', 'id');
    $logRowList = db_all('SELECT l.*, u.login_id FROM faq_chat_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.id >= ? ORDER BY l.id', [$firstLogId]);
    foreach ($logRowList as $logRow) {
        $shownFaqIdList = array_values(array_filter(array_map('intval', explode(',', (string) $logRow['shown_faq_ids']))));
        $loggedAnswerText = $logRow['result'] === 'matched' && $shownFaqIdList ? ' → ' . ($faqQuestionById[$shownFaqIdList[0]] ?? '?') : '';
        $feedbackText = (string) $logRow['feedback'] !== '' ? ' [' . $logRow['feedback'] . ']' : '';
        echo "#{$logRow['id']} {$logRow['asked_at']} {$logRow['login_id']}\n";
        if ($logRow['result'] === 'category') {
            echo '  分野を選んだ: ', FAQ_CATEGORY_LABELS[(string) $logRow['category']] ?? (string) $logRow['category'], "\n";
            continue;
        }
        $questionText = faq_chat_log_question_text($logRow);
        echo '  質問: ', faq_check_shown_text($questionText), "\n";
        echo '  記録: ', $logRow['result'], $loggedAnswerText, $feedbackText, "\n";
        echo '  今　: ', faq_check_judge($questionText, faq_check_visible_list($allFaqList, (string) $logRow['login_id'])), faq_check_notice_mark($questionText), "\n";
    }
    echo count($logRowList), " 件\n";
    exit(0);
}

if ($commandName === 'csv') {
    // ---- ほかの端末から受け取った記録（CSV）を、今の作りで判定し直す ----
    $csvPath = (string) ($argv[2] ?? '');
    if ($csvPath === '' || !is_file($csvPath)) {
        fwrite(STDERR, "使い方: php tools/lib/faq_check.php csv <faq_chat_log_*.csv>\n");
        exit(1);
    }
    $csvHandle = fopen($csvPath, 'r');
    if (fread($csvHandle, 3) !== "\xEF\xBB\xBF") {
        rewind($csvHandle);   // BOM が無いときは先頭から
    }
    $headerRow = fgetcsv($csvHandle);
    $rowCount = 0;
    while (($csvRow = fgetcsv($csvHandle)) !== false) {
        if (count($csvRow) !== count($headerRow)) {
            continue;
        }
        $logRow = array_combine($headerRow, $csvRow);
        $questionText = (string) $logRow['質問・選んだ分野'];
        $loggedAnswerText = (string) $logRow['出した答え（いちばん近い質問）'] !== '' ? ' → ' . $logRow['出した答え（いちばん近い質問）'] : '';
        $feedbackText = !in_array((string) $logRow['反応'], ['', '（なし）'], true) ? ' [' . $logRow['反応'] . ']' : '';
        echo "#{$logRow['番号']} {$logRow['日時']} {$logRow['ログイン ID']}（{$logRow['役割']}）\n";
        echo '  質問: ', faq_check_shown_text($questionText), "\n";
        echo '  記録: ', $logRow['結果'], $loggedAnswerText, $feedbackText, "\n";
        $isNotJudgeable = strpos($questionText, '分野:') === 0 || strpos($questionText, '（要配慮の内容') === 0;
        echo '  今　: ', $isNotJudgeable ? '（判定しない）' : faq_check_judge($questionText, faq_check_visible_list($allFaqList, (string) $logRow['ログイン ID'])) . faq_check_notice_mark($questionText), "\n";
        $rowCount++;
    }
    echo "$rowCount 件\n";
    exit(0);
}

if ($commandName === 'sync') {
    // ---- この端末の DB に、標準のよくある質問の追加と答えの変更を写す（画面の「標準のよくある質問を取り込む」と同じ足し方） ----
    faq_ensure_category_column();
    $existingFaqByQuestion = array_column($allFaqList, null, 'question');
    $nextSortOrder = (int) db_val('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM faq_entries');
    $addedCount = 0;
    $updatedCount = 0;
    tx(function () use ($existingFaqByQuestion, &$nextSortOrder, &$addedCount, &$updatedCount): void {
        foreach (FAQ_STANDARD_ENTRIES as [$standardCategory, $standardQuestion, $standardAnswer]) {
            $existingFaqRow = $existingFaqByQuestion[$standardQuestion] ?? null;
            if ($existingFaqRow === null) {
                db_exec('INSERT INTO faq_entries (category, question, answer, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$standardCategory, $standardQuestion, $standardAnswer, $nextSortOrder, now(), now()]);
                $nextSortOrder += 10;
                $addedCount++;
                echo "足した: $standardQuestion\n";
            } elseif ($existingFaqRow['answer'] !== $standardAnswer || (string) $existingFaqRow['category'] !== $standardCategory) {
                db_exec('UPDATE faq_entries SET category = ?, answer = ?, updated_at = ? WHERE id = ?', [$standardCategory, $standardAnswer, now(), (int) $existingFaqRow['id']]);
                $updatedCount++;
                echo "答えを直した: $standardQuestion\n";
            }
        }
    });
    echo "足した $addedCount 件、答えを直した $updatedCount 件\n";
    exit(0);
}

fwrite(STDERR, "使い方: php tools/lib/faq_check.php self | ask \"質問\" [ID] | logs [番号] | csv <ファイル> | sync\n");
exit(1);
