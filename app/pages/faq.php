<?php

declare(strict_types=1);

// ====================================================================
// G-25 よくある質問（便利機能 J）。全員が見られる。管理者・補佐が質問と答えを書く。
// 要望・依頼の「質問」に答えたものから載せるときも、文は管理者側が書き直す（出した人の文や名前は出さない）。
// チャットの形で知りたいことを入れると近い質問を出し（AI は使わず文字の並びの一致。faq_search()）、見つからなければ、
// 課長・確認者はその文のまま要望・依頼の「質問」として送れる（G-25 §2.1・§2.2）。
// 「使い方がわからない」のような曖昧な質問は、分野を聞き返して質問を選んでもらう（§2.3）。
// 標準のよくある質問（faq_standard.php）を、管理者・補佐が取り込める（§2.4）。
// システムに関係のない質問には「その質問には返答できません。」、不適切な表現には「不適切な表現が含まれているため、返答できません。」と返し、
// 続けて 5 回になったら返事をしない（§2.6）。聞かれた質問と返した答えは、チャットの記録（faq_chat_logs）に残す（§2.7）。
// 心の不調らしい文には気づかいの言葉を返し、システム管理者・補佐に知らせる（ホーム・管理のトップ・記録の画面。§2.8）。
// ====================================================================

require_once APP_DIR . '/lib/faq_standard.php';

$user = page_start();
faq_ensure_category_column();
faq_chat_log_ensure_table();
$userId = (int) $user['id'];
$canEdit = can_manage_system($user);
$errorTextList = [];
$editedFaqId = isset($_GET['edit']) ? input_id('edit') : null;
$sourceRequestId = isset($_GET['request']) ? input_id('request') : null;
if (($editedFaqId !== null || $sourceRequestId !== null) && !$canEdit) {
    error_page(403);
}
$editedFaq = $editedFaqId === null ? null : db_one('SELECT * FROM faq_entries WHERE id = ?', [$editedFaqId]);
if ($editedFaqId !== null && $editedFaq === null) {
    error_page(404);
}
$typedValueByName = [
    'category' => (string) ($editedFaq['category'] ?? ''),
    'question' => (string) ($editedFaq['question'] ?? ''),
    'answer'   => (string) ($editedFaq['answer'] ?? ''),
];
// チャットの記録の質問から載せる（よくある質問の記録の画面の「よくある質問に載せる」。§2.7）
$draftLogId = isset($_GET['draft_log']) ? input_id('draft_log') : null;
if ($draftLogId !== null) {
    if (!$canEdit) {
        error_page(403);
    }
    $draftLogRow = db_one('SELECT * FROM faq_chat_logs WHERE id = ?', [$draftLogId]);
    if ($draftLogRow === null || faq_chat_log_is_hidden($draftLogRow)) {
        error_page(404);   // 要配慮の内容の記録からは載せない（文を画面に出さないため）
    }
    $draftQuestionText = trim((string) preg_replace('/\s+/u', ' ', faq_chat_log_question_text($draftLogRow)));
    $typedValueByName['question'] = implode('', array_slice(preg_split('//u', $draftQuestionText, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, FAQ_QUESTION_MAX_LENGTH));
    $typedValueByName['category'] = faq_is_category((string) ($draftLogRow['category'] ?? '')) ? (string) $draftLogRow['category'] : '';
}

// ---- 探す（GET の q）・分野（GET の category）と、各画面の「この画面の使い方」から来たときの画面 ----
$canAsk = support_request_can_submit($user);
$searchQueryText = is_string($_GET['q'] ?? null) && preg_match('//u', $_GET['q']) === 1 ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $_GET['q'])) : '';
if (str_len($searchQueryText) > FAQ_SEARCH_QUERY_MAX_LENGTH) {
    $searchQueryText = implode('', array_slice(preg_split('//u', $searchQueryText, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, FAQ_SEARCH_QUERY_MAX_LENGTH));
}
$selectedCategory = is_string($_GET['category'] ?? null) && faq_is_category($_GET['category']) ? (string) $_GET['category'] : null;
$linkedScreenCode = is_string($_GET['screen'] ?? null) && isset(SUPPORT_REQUEST_SCREEN_LABELS[$_GET['screen']]) ? (string) $_GET['screen'] : '';
$sourcePathText = support_request_clean_source_path(is_string($_GET['from'] ?? null) ? $_GET['from'] : '');
$askedQuestionText = $searchQueryText;
$askErrorTextList = [];

/**
 * 入れた文か選んだ分野に返す内容を決め、チャットの記録に残す（チャットと、JavaScript が無いときの画面で同じ）。
 * 判定の順: 分野 → 命・体の緊急の状態（§2.12。119 番を案内） → 心の不調らしい文 → 自分の退職（§2.10。寄り添って上司への相談を促す） → 要配慮個人情報に触れる文（§2.9。文は記録しない） → 不適切な表現 → 曖昧な質問 → 関係のない質問 → 近い質問を探す。
 * 不適切な表現と関係のない質問は続いた回数を数え、FAQ_UNRELATED_IGNORE_COUNT 回目から返事をしない。ほか（心の不調らしい文を含む）は 0 に戻す。
 * 心の不調らしい文は、心配な言葉の段階なら近い質問も探して一緒に返す（「助けて、ログインできない」など）。
 *
 * @param array<int, array<string, mixed>> $faqList
 * @return array{result: string, ignored: bool, distress_level: string, matches: array<int, array<string, mixed>>, log_id: int, context: string}
 *   context: 前の話題を補って答えたときの話題（G-25 §2.14。補っていなければ空）
 */
function faq_answer_chat(array $user, array $faqList, string $searchQueryText, ?string $selectedCategory, string $screenCode): array
{
    // 会話（G-25 §2.14）: 同じ人が続けて入れた発言を 1 つの会話として記録し、中身のない発言には前の話題を補う
    $conversation = faq_conversation_current();
    $contextTopic = '';
    $isHarassmentBySuperior = false;
    if ($selectedCategory !== null) {
        $resultCode = 'category';
        $matchedFaqList = faq_in_category($faqList, $selectedCategory);
        $distressLevel = '';
        $notifyLevel = '';
        faq_conversation_set_topic('');
    } else {
        // 入れた文の判定（判定の順は faq_classify_question()。会話の補いは faq_classify_in_conversation()。自動入力検証も同じ関数を使う）
        $conversationJudgement = faq_classify_in_conversation($searchQueryText, $conversation['topic'], $faqList);
        faq_conversation_set_topic($conversationJudgement['nextTopic']);
        $contextTopic = $conversationJudgement['contextTopic'];
        $classification = $conversationJudgement['classification'];
        $resultCode = $classification['code'];
        $isHarassmentBySuperior = (bool) ($classification['harassmentBySuperior'] ?? false);
        $matchedFaqList = $classification['matches'];
        $distressLevel = $classification['distressLevel'];
        $notifyLevel = $classification['noticeLevel'];
    }
    $isRefused = $resultCode === 'abusive' || $resultCode === 'unrelated';
    $refusedCount = faq_count_unrelated($isRefused);   // 関係のある質問・分野を選んだときは 0 に戻る
    $isIgnored = $isRefused && $refusedCount >= FAQ_UNRELATED_IGNORE_COUNT;
    // 要配慮個人情報に触れる文・命や体の緊急の状態も、文は記録する（暗号化）。画面には出さない（faq_chat_log_display_text()。K-053・K-055。2026-11-16 指示）
    $loggedQuestionText = $selectedCategory !== null ? '' : $searchQueryText;
    // 記録の表の結果の種類は変えない: 呼びかけだけの文は「あいまい」、人への暴力を書いた文は「不適切な表現」（知らせる理由 violence で見分ける）
    $loggedResultCode = ['opener' => 'vague', 'closing' => 'vague', 'violence' => 'abusive'][$resultCode] ?? $resultCode;
    $logId = faq_chat_log_add($user, $screenCode, $loggedQuestionText, $selectedCategory, $loggedResultCode,
        array_map('intval', array_column($matchedFaqList, 'id')), $isIgnored, $notifyLevel, $conversation['id']);
    return ['result' => $resultCode, 'ignored' => $isIgnored, 'distress_level' => $distressLevel, 'matches' => $matchedFaqList, 'log_id' => $logId,
            'context' => $contextTopic, 'harassment' => $isHarassmentBySuperior];
}

// ---- チャットの答え（JavaScript が ?format=json&q= または ?format=json&category= で聞く。G-25 §2.1・§2.3・§2.6） ----
if (($_GET['format'] ?? '') === 'json') {
    if ($searchQueryText === '' && $selectedCategory === null) {
        json_response(['ok' => false], 400);
    }
    // 見せてよい質問だけで答える（管理者・補佐だけの分野は、ほかの人には出さない）
    $chatAnswer = faq_answer_chat($user, faq_visible_entries(db_all('SELECT * FROM faq_entries ORDER BY sort_order, id'), $user), $searchQueryText, $selectedCategory, $linkedScreenCode);
    json_response([
        'ok'        => true,
        'log_id'    => $chatAnswer['log_id'],
        'result'    => $chatAnswer['result'],
        'vague'     => $chatAnswer['result'] === 'vague',
        'opener'    => $chatAnswer['result'] === 'opener',
        'closing'   => $chatAnswer['result'] === 'closing',
        'violence'  => $chatAnswer['result'] === 'violence',
        'context'   => $chatAnswer['context'],
        'harassment' => $chatAnswer['harassment'],
        'unrelated' => $chatAnswer['result'] === 'unrelated',
        'abusive'   => $chatAnswer['result'] === 'abusive',
        'distress'  => $chatAnswer['distress_level'],
        'sensitive' => $chatAnswer['result'] === 'sensitive',
        'emergency' => $chatAnswer['result'] === 'emergency',
        'retirement' => $chatAnswer['result'] === 'retirement',
        'ignored'   => $chatAnswer['ignored'],
        'matches'   => array_map(static function (array $matchedFaqRow): array {
            return ['id' => (int) $matchedFaqRow['id'], 'question' => (string) $matchedFaqRow['question'], 'answer' => (string) $matchedFaqRow['answer']];
        }, $chatAnswer['matches']),
    ]);
}

// ---- チャットの問い合わせの失敗（答えが読めなかった）を記録に残す（JavaScript が送る。§2.11） ----
if (is_post() && post_str('action') === 'chat_client_error') {
    $failureKind = post_str('kind');
    if (!in_array($failureKind, ['not_json', 'not_ok', 'network'], true)) {
        error_page(400);
    }
    $failureStatus = (int) post_str('status');
    app_log('WARN よくある質問のチャットの問い合わせに失敗: kind=' . $failureKind . ' status=' . $failureStatus . ' user=' . $userId);
    json_response(['ok' => true]);
}

// ---- チャットの反応（解決した・解決しない。JavaScript が送る。§2.7） ----
if (is_post() && post_str('action') === 'chat_feedback') {
    $feedbackLogId = input_id('log_id', 'POST');
    $feedbackCode = post_str('feedback');
    if (!in_array($feedbackCode, ['solved', 'unsolved'], true) || !faq_chat_log_is_own($feedbackLogId)) {
        error_page(400);
    }
    $feedbackFaqId = isset($_POST['faq_id']) && $_POST['faq_id'] !== '' ? input_id('faq_id', 'POST') : null;
    faq_chat_log_set_feedback($feedbackLogId, $feedbackCode, $feedbackFaqId);
    json_response(['ok' => true]);
}

// ---- 見つからなかった質問を、要望・依頼の「質問」として送る（課長・確認者） ----
if (is_post() && post_str('action') === 'ask') {
    if (!$canAsk) {
        error_page(403);
    }
    rk_begin('faq.ask', $userId);
    $askedQuestionText = trim(str_replace(["\r\n", "\r"], "\n", post_str('question', false)));
    $linkedScreenCode = post_str('screen_code');
    $sourcePathText = support_request_clean_source_path(post_str('source_path'));
    $searchQueryText = faq_question_title($askedQuestionText);
    if ($askedQuestionText === '') {
        $askErrorTextList[] = msg('質問を入れてください');
    } else {
        $requestValueByName = [
            'kind' => 'question', 'urgency' => 'normal', 'screen_code' => $linkedScreenCode,
            'title' => faq_question_title($askedQuestionText), 'body' => $askedQuestionText, 'source_path' => $sourcePathText,
        ];
        $askErrorTextList = support_request_validate($userId, $requestValueByName);
        if (!$askErrorTextList) {
            $createdRequestId = support_request_create($user, $requestValueByName);
            $askedLogId = isset($_POST['chat_log_id']) && $_POST['chat_log_id'] !== '' ? input_id('chat_log_id', 'POST') : null;
            if ($askedLogId !== null && faq_chat_log_is_own($askedLogId)) {
                faq_chat_log_set_feedback($askedLogId, 'asked', null, $createdRequestId);
            }
            flash('success', msg('質問をシステム管理者に送りました。返信があると、ホームに出ます'));
            rk_done('/requests/view.php?id=' . $createdRequestId);
        }
    }
    rk_cancel();
}

if (is_post() && !in_array(post_str('action'), ['ask', 'chat_feedback', 'chat_client_error'], true)) {
    if (!$canEdit) {
        error_page(403);
    }
    $action = post_str('action');
    if (!in_array($action, ['save', 'delete', 'move_up', 'move_down', 'import_standard'], true)) {
        error_page(400);
    }
    rk_begin('faq.' . $action, $userId);

    // ---- 標準のよくある質問を取り込む（まだ載っていない質問の文だけ足す。§2.4） ----
    if ($action === 'import_standard') {
        $addedCount = tx(function () use ($userId): int {
            $existingQuestionSet = array_fill_keys(array_column(db_all('SELECT question FROM faq_entries'), 'question'), true);
            $faqCount = (int) db_val('SELECT COUNT(*) FROM faq_entries');
            $nextSortOrder = (int) db_val('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM faq_entries');
            $insertedCount = 0;
            foreach (FAQ_STANDARD_ENTRIES as [$standardCategory, $standardQuestion, $standardAnswer]) {
                if (isset($existingQuestionSet[$standardQuestion]) || $faqCount >= FAQ_MAX_COUNT) {
                    continue;
                }
                db_exec('INSERT INTO faq_entries (category, question, answer, sort_order, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$standardCategory, $standardQuestion, $standardAnswer, $nextSortOrder, $userId, now(), now()]);
                $nextSortOrder += 10;
                $faqCount++;
                $insertedCount++;
            }
            return $insertedCount;
        });
        audit_log('admin', 'faq:import_standard;added=' . $addedCount);
        flash('success', $addedCount > 0
            ? msg('標準のよくある質問を {count} 件取り込みました', ['count' => $addedCount])
            : msg('取り込む質問はありませんでした（標準のよくある質問は、すべて載っています）'));
        rk_done('/faq.php');
    }

    $postedFaqId = isset($_POST['id']) && $_POST['id'] !== '' ? input_id('id', 'POST') : null;
    if ($postedFaqId !== null && db_one('SELECT id FROM faq_entries WHERE id = ?', [$postedFaqId]) === null) {
        error_page(404);
    }
    if ($action === 'delete' || $action === 'move_up' || $action === 'move_down') {
        if ($postedFaqId === null) {
            error_page(400);
        }
        if ($action === 'delete') {
            db_exec('DELETE FROM faq_entries WHERE id = ?', [$postedFaqId]);
            audit_log('admin', 'faq:' . $postedFaqId . ':delete');
            flash('success', msg('よくある質問を消しました'));
        } else {
            // 並べ替え: 同じ分野の中の隣と入れ替える（画面は分野ごとに並べて出すため）
            $orderedFaqRowList = db_all('SELECT id, category FROM faq_entries ORDER BY sort_order, id');
            $faqOrderList = array_map('intval', array_column($orderedFaqRowList, 'id'));
            $categoryByFaqId = array_column($orderedFaqRowList, 'category', 'id');
            $postedCategory = (string) $categoryByFaqId[$postedFaqId];
            $sameCategoryIdList = array_values(array_filter($faqOrderList, static function (int $faqId) use ($categoryByFaqId, $postedCategory): bool {
                return (string) $categoryByFaqId[$faqId] === $postedCategory;
            }));
            $categoryIndex = array_search($postedFaqId, $sameCategoryIdList, true);
            $neighborFaqId = $sameCategoryIdList[$action === 'move_up' ? $categoryIndex - 1 : $categoryIndex + 1] ?? null;
            if ($categoryIndex !== false && $neighborFaqId !== null) {
                $currentIndex = array_search($postedFaqId, $faqOrderList, true);
                $swapIndex = array_search($neighborFaqId, $faqOrderList, true);
                [$faqOrderList[$currentIndex], $faqOrderList[$swapIndex]] = [$faqOrderList[$swapIndex], $faqOrderList[$currentIndex]];
                tx(function () use ($faqOrderList): void {
                    foreach ($faqOrderList as $orderIndex => $faqId) {
                        db_exec('UPDATE faq_entries SET sort_order = ? WHERE id = ?', [($orderIndex + 1) * 10, $faqId]);
                    }
                });
            }
        }
        rk_done('/faq.php');
    }
    $typedValueByName = [
        'category' => post_str('category'),
        'question' => post_str('question'),
        'answer'   => trim(str_replace(["\r\n", "\r"], "\n", post_str('answer', false))),
    ];
    if (!faq_is_category($typedValueByName['category'])) {
        $errorTextList[] = msg('分野を選んでください');
    }
    validate_text($typedValueByName['question'], msg('質問'), FAQ_QUESTION_MAX_LENGTH, true, $errorTextList);
    validate_text($typedValueByName['answer'], msg('答え'), FAQ_ANSWER_MAX_LENGTH, true, $errorTextList, true);
    if ($postedFaqId === null && (int) db_val('SELECT COUNT(*) FROM faq_entries') >= FAQ_MAX_COUNT) {
        $errorTextList[] = msg('よくある質問は {max} 件までです', ['max' => FAQ_MAX_COUNT]);
    }
    $postedSourceRequestId = isset($_POST['source_request_id']) && $_POST['source_request_id'] !== '' ? input_id('source_request_id', 'POST') : null;
    if (!$errorTextList) {
        if ($postedFaqId === null) {
            $nextSortOrder = (int) db_val('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM faq_entries');
            db_exec('INSERT INTO faq_entries (category, question, answer, sort_order, source_request_id, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$typedValueByName['category'], $typedValueByName['question'], $typedValueByName['answer'], $nextSortOrder, $postedSourceRequestId, $userId, now(), now()]);
            $postedFaqId = (int) db()->lastInsertId();
            audit_log('admin', 'faq:' . $postedFaqId . ':create');
        } else {
            db_exec('UPDATE faq_entries SET category = ?, question = ?, answer = ?, updated_by = ?, updated_at = ? WHERE id = ?',
                [$typedValueByName['category'], $typedValueByName['question'], $typedValueByName['answer'], $userId, now(), $postedFaqId]);
            audit_log('admin', 'faq:' . $postedFaqId . ':update');
        }
        flash('success', msg('よくある質問を保存しました'));
        rk_done('/faq.php#faq-' . $postedFaqId);
    }
    rk_cancel();
    $editedFaqId = $postedFaqId;
    $sourceRequestId = $postedSourceRequestId;
}

$allFaqList = db_all('SELECT * FROM faq_entries ORDER BY sort_order, id');
$faqList = faq_visible_entries($allFaqList, $user);   // 管理者・補佐だけの分野は、ほかの人には出さない
// JavaScript が無いときの画面（?q= か ?category=）も、チャットと同じに答えを決めて記録する
$chatAnswer = !is_post() && ($searchQueryText !== '' || $selectedCategory !== null)
    ? faq_answer_chat($user, $faqList, $searchQueryText, $selectedCategory, $linkedScreenCode)
    : ['result' => '', 'ignored' => false, 'distress_level' => '', 'matches' => [], 'log_id' => 0];
// 分野と文の両方があるときは、分野で答えた（文は探さない）
$chatResultCode = $selectedCategory !== null ? '' : $chatAnswer['result'];
$existingQuestionSet = array_fill_keys(array_column($allFaqList, 'question'), true);
$standardMissingCount = count(array_filter(FAQ_STANDARD_ENTRIES, static function (array $standardEntry) use ($existingQuestionSet): bool {
    return !isset($existingQuestionSet[$standardEntry[1]]);
}));
render('faq', [
    'title'             => 'よくある質問',
    'user'              => $user,
    'faqList'           => $faqList,
    'categoryCountList' => faq_category_counts($faqList),
    'selectedCategory'  => $selectedCategory,
    'categoryFaqList'   => $selectedCategory === null ? [] : faq_in_category($faqList, $selectedCategory),
    'screenCategory'    => FAQ_CATEGORY_BY_SCREEN[$linkedScreenCode] ?? null,
    'searchQueryText'   => $selectedCategory !== null ? '' : $searchQueryText,
    'chatResultCode'    => $chatResultCode,
    'chatContextTopic'  => $selectedCategory !== null ? '' : (string) ($chatAnswer['context'] ?? ''),
    'chatHarassment'    => $selectedCategory === null && (bool) ($chatAnswer['harassment'] ?? false),
    'distressLevel'     => $chatAnswer['distress_level'],
    'isIgnored'         => $chatAnswer['ignored'],
    'chatLogId'         => $chatAnswer['log_id'],
    'matchedFaqList'    => in_array($chatResultCode, ['matched', 'distress', 'sensitive'], true) ? $chatAnswer['matches'] : [],
    'canAsk'            => $canAsk,
    'isStaff'           => $canEdit,
    'askedQuestionText' => $askedQuestionText,
    'askErrorTextList'  => $askErrorTextList,
    'linkedScreenCode'  => $linkedScreenCode,
    'sourcePathText'    => $sourcePathText,
    'canEdit'           => $canEdit,
    'standardMissingCount' => $standardMissingCount,
    'editedFaqId'       => $editedFaqId,
    'sourceRequestId'   => $sourceRequestId,
    'isFormOpen'        => $errorTextList !== [] || $editedFaqId !== null || $sourceRequestId !== null || $draftLogId !== null,
    'typedValueByName'  => $typedValueByName,
    'errorTextList'     => $errorTextList,
]);
