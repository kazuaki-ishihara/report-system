<?php

declare(strict_types=1);

// ====================================================================
// よくある質問の CSV（G-25 §2.19。K-076）
//   ・記録の CSV: チャットの記録を 1 回の質問ごとに 1 行で出す（聞いた人の名前と、知らせる質問は入れない）
//   ・メンテナンス用の CSV: 載っている質問・まだ載っていない標準の質問・答えられなかった質問を 1 つのファイルに出す。
//     直して取り込むと、変わる内容を確かめたうえで、足す・直す・消すをまとめて反映する
// ====================================================================

/** 記録の CSV の列 */
const FAQ_LOG_CSV_COLUMNS = ['日時', '役割', '開いていた画面', '質問の文', '分野', '結果', '返事をしなかった', '出した質問の番号', '反応', '反応した質問の番号', '会話'];

/** メンテナンス用の CSV の列（取り込みで読むのは 番号・分野・質問・答え・消す・直した日時。ほかは見るための列） */
const FAQ_CSV_COLUMNS = ['種類', '番号', '分野', '質問', '答え', '消す', '直した日時', '回数', '解決した', '解決しない'];

/** メンテナンス用の CSV の「種類」 */
const FAQ_CSV_KIND_ENTRY = '載っている質問';
const FAQ_CSV_KIND_STANDARD = '標準（まだ載っていない）';
const FAQ_CSV_KIND_UNANSWERED = '答えられなかった質問';

/** メンテナンス用の CSV に出す、答えられなかった質問の数（多い順） */
const FAQ_CSV_UNANSWERED_MAX_COUNT = 200;

/** 取り込む CSV の大きさの上限（バイト） */
const FAQ_CSV_MAX_BYTES = 2 * 1024 * 1024;

/**
 * 記録の CSV の行（新しい順）。知らせる質問（distress_level のある記録）は入れない（よくある質問の改善には使わないため）。
 *
 * @param array<int, array<string, mixed>> $logRowList faq_chat_logs の行（question_text に画面に出す文を入れたもの）
 * @return array<int, array<int, string>>
 */
function faq_csv_log_rows(array $logRowList): array
{
    $csvRowList = [];
    foreach ($logRowList as $logRow) {
        if ($logRow['distress_level'] !== null) {
            continue;
        }
        $categoryCode = $logRow['category'] === null ? null : (string) $logRow['category'];
        $csvRowList[] = [
            (string) $logRow['asked_at'],
            FAQ_CHAT_LOG_ROLE_LABELS[(string) $logRow['role']] ?? (string) $logRow['role'],
            (string) $logRow['screen_code'],
            (string) $logRow['question_text'],
            $categoryCode === null ? '' : (FAQ_CATEGORY_LABELS[$categoryCode] ?? $categoryCode),
            FAQ_CHAT_LOG_RESULT_LABELS[(string) $logRow['result']] ?? (string) $logRow['result'],
            (int) $logRow['is_ignored'] === 1 ? 'はい' : '',
            str_replace(',', ' ', (string) $logRow['shown_faq_ids']),
            $logRow['feedback'] === '' ? '' : (FAQ_CHAT_LOG_FEEDBACK_LABELS[(string) $logRow['feedback']] ?? (string) $logRow['feedback']),
            $logRow['feedback_faq_id'] === null ? '' : (string) $logRow['feedback_faq_id'],
            (string) $logRow['conversation_id'],
        ];
    }
    return $csvRowList;
}

/**
 * メンテナンス用の CSV の行。載っている質問（並び順）→ まだ載っていない標準の質問 → 答えられなかった質問（多い順）。
 *
 * @param array<int, array{text: string, count: int}> $unansweredGroupList 答えられなかった質問（同じ文をまとめたもの。多い順）
 * @param array<int, array{solved: int, unsolved: int}> $feedbackCountByFaqId 質問ごとの反応の数
 * @return array<int, array<int, string>>
 */
function faq_csv_maintenance_rows(array $unansweredGroupList, array $feedbackCountByFaqId): array
{
    require_once APP_DIR . '/lib/faq_standard.php';   // 標準のよくある質問（よくある質問の画面だけが読むので、ここでも読む）
    $csvRowList = [];
    $faqEntryRowList = db_all('SELECT id, category, question, answer, updated_at FROM faq_entries ORDER BY sort_order, id');
    foreach ($faqEntryRowList as $faqEntryRow) {
        $faqId = (int) $faqEntryRow['id'];
        $csvRowList[] = [
            FAQ_CSV_KIND_ENTRY, (string) $faqId, FAQ_CATEGORY_LABELS[(string) $faqEntryRow['category']] ?? FAQ_CATEGORY_LABELS[''],
            (string) $faqEntryRow['question'], (string) $faqEntryRow['answer'], '', (string) $faqEntryRow['updated_at'], '',
            (string) ($feedbackCountByFaqId[$faqId]['solved'] ?? 0), (string) ($feedbackCountByFaqId[$faqId]['unsolved'] ?? 0),
        ];
    }
    $existingQuestionSet = array_fill_keys(array_column($faqEntryRowList, 'question'), true);
    foreach (FAQ_STANDARD_ENTRIES as [$standardCategory, $standardQuestion, $standardAnswer]) {
        if (!isset($existingQuestionSet[$standardQuestion])) {
            $csvRowList[] = [FAQ_CSV_KIND_STANDARD, '', FAQ_CATEGORY_LABELS[$standardCategory] ?? FAQ_CATEGORY_LABELS[''], $standardQuestion, $standardAnswer, '', '', '', '', ''];
        }
    }
    foreach (array_slice($unansweredGroupList, 0, FAQ_CSV_UNANSWERED_MAX_COUNT) as $unansweredGroup) {
        $csvRowList[] = [FAQ_CSV_KIND_UNANSWERED, '', FAQ_CATEGORY_LABELS[''], (string) $unansweredGroup['text'], '', '', '', (string) $unansweredGroup['count'], '', ''];
    }
    return $csvRowList;
}

/**
 * 取り込む CSV を読んで、足す・直す・消すの計画を作る（DB は変えない）。
 * 決まり: 番号のある行は、消すに印があれば消す、分野・質問・答えが変わっていれば直す。番号の無い行は、答えがあれば足し、答えが空なら飛ばす。
 * CSV に無い質問は消さない。CSV を出したあとに画面で直された質問（直した日時が違う）は、直す・消すにしない（誤り）。
 *
 * @return array{errorTextList: array<int, string>, addList: array<int, array<string, mixed>>, updateList: array<int, array<string, mixed>>,
 *               deleteList: array<int, array<string, mixed>>, skippedCount: int, unchangedCount: int}
 */
function faq_csv_plan(string $csvText): array
{
    $plan = ['errorTextList' => [], 'addList' => [], 'updateList' => [], 'deleteList' => [], 'skippedCount' => 0, 'unchangedCount' => 0];
    $csvText = (string) preg_replace('/\A\xEF\xBB\xBF/', '', $csvText);
    $csvStream = fopen('php://memory', 'r+');
    fwrite($csvStream, $csvText);
    rewind($csvStream);
    $isHeaderFound = false;
    $parsedRowList = [];
    $lineNumber = 0;
    while (($csvCells = fgetcsv($csvStream, 0, ',', '"', '')) !== false) {
        $lineNumber++;
        if ($csvCells === [null] || $csvCells === ['']) {
            continue;
        }
        $csvCells = array_map(static function ($cellValue): string { return (string) $cellValue; }, $csvCells);
        if (!$isHeaderFound) {
            if (array_map('trim', $csvCells) === FAQ_CSV_COLUMNS) {
                $isHeaderFound = true;
            }
            continue;   // 見出しの前の行（「社外秘」など）は読み飛ばす
        }
        if (count($csvCells) !== count(FAQ_CSV_COLUMNS)) {
            $plan['errorTextList'][] = msg('{line} 行目: 列の数が違います', ['line' => $lineNumber]);
            continue;
        }
        $parsedRowList[] = ['lineNumber' => $lineNumber, 'cells' => array_combine(FAQ_CSV_COLUMNS, $csvCells)];
    }
    fclose($csvStream);
    if (!$isHeaderFound) {
        $plan['errorTextList'][] = msg('見出しの行（{columns}）がありません。「メンテナンス用の CSV を出す」で出したファイルを直して使ってください', ['columns' => implode(',', FAQ_CSV_COLUMNS)]);
        return $plan;
    }

    $categoryCodeByLabel = array_flip(FAQ_CATEGORY_LABELS);
    $faqEntryById = [];
    foreach (db_all('SELECT id, category, question, answer, updated_at FROM faq_entries') as $faqEntryRow) {
        $faqEntryById[(int) $faqEntryRow['id']] = $faqEntryRow;
    }
    $seenFaqIdSet = [];
    $questionLineByKey = [];   // 同じ質問の文が 2 回出ていないか（取り込んだあとの質問どうし）
    $remainingCount = count($faqEntryById);
    foreach ($parsedRowList as $parsedRow) {
        $lineNumber = $parsedRow['lineNumber'];
        $cells = $parsedRow['cells'];
        $faqIdText = trim($cells['番号']);
        $categoryLabel = trim($cells['分野']);
        $questionText = trim($cells['質問']);
        $answerText = faq_csv_normalize_answer($cells['答え']);
        $deleteMark = trim($cells['消す']);
        $isDelete = $deleteMark !== '';
        $rowErrorList = [];
        if ($isDelete && !in_array($deleteMark, ['1', '○', '〇', 'はい'], true)) {
            $rowErrorList[] = msg('{line} 行目: 「消す」には 1・○・はい のどれかを入れてください（消さない行は空にします）', ['line' => $lineNumber]);
        }
        $categoryCode = $categoryLabel === '' ? '' : ($categoryCodeByLabel[$categoryLabel] ?? null);
        if ($categoryCode === null) {
            $rowErrorList[] = msg('{line} 行目: 分野「{category}」はありません', ['line' => $lineNumber, 'category' => $categoryLabel]);
        }

        if ($faqIdText !== '') {
            // ---- 載っている質問: 消す・直す・そのまま ----
            if (preg_match('/\A[1-9][0-9]{0,9}\z/', $faqIdText) !== 1 || !isset($faqEntryById[(int) $faqIdText])) {
                $plan['errorTextList'][] = msg('{line} 行目: 番号 {id} の質問はありません（新しく足す行は、番号を空にします）', ['line' => $lineNumber, 'id' => $faqIdText]);
                continue;
            }
            $faqId = (int) $faqIdText;
            $currentRow = $faqEntryById[$faqId];
            if (isset($seenFaqIdSet[$faqId])) {
                $plan['errorTextList'][] = msg('{line} 行目: 番号 {id} が 2 回出ています', ['line' => $lineNumber, 'id' => $faqId]);
                continue;
            }
            $seenFaqIdSet[$faqId] = true;
            // 改行の形（CRLF・LF）と前後の空白の違いは、変えたことにしない（CSV に出して読み直すとそろうため）
            $isChanged = $categoryCode !== (string) $currentRow['category'] || $questionText !== trim((string) $currentRow['question'])
                || $answerText !== faq_csv_normalize_answer((string) $currentRow['answer']);
            if (($isDelete || $isChanged) && trim($cells['直した日時']) !== (string) $currentRow['updated_at']) {
                $rowErrorList[] = msg('{line} 行目: 番号 {id} の質問は、CSV を出したあとに画面で直されています。もう一度 CSV を出して直してください', ['line' => $lineNumber, 'id' => $faqId]);
            }
            if ($isDelete) {
                if (!$rowErrorList) {
                    $plan['deleteList'][] = ['id' => $faqId, 'question' => (string) $currentRow['question'], 'lineNumber' => $lineNumber, 'updatedAt' => (string) $currentRow['updated_at']];
                    $remainingCount--;
                }
                $plan['errorTextList'] = array_merge($plan['errorTextList'], $rowErrorList);
                continue;
            }
            if ($isChanged) {
                faq_csv_validate_text($lineNumber, $questionText, $answerText, $rowErrorList);
            }
            faq_csv_check_duplicate($lineNumber, $questionText, $questionLineByKey, $rowErrorList);
            if (!$rowErrorList && $isChanged) {
                $plan['updateList'][] = ['id' => $faqId, 'category' => $categoryCode, 'question' => $questionText, 'answer' => $answerText,
                    'beforeQuestion' => (string) $currentRow['question'], 'lineNumber' => $lineNumber, 'updatedAt' => (string) $currentRow['updated_at']];
            } elseif (!$rowErrorList) {
                $plan['unchangedCount']++;
            }
            $plan['errorTextList'] = array_merge($plan['errorTextList'], $rowErrorList);
            continue;
        }

        // ---- 番号の無い行: 答えがあれば足す。答えが空（答えられなかった質問・使わない標準の質問）は飛ばす ----
        if ($isDelete) {
            $rowErrorList[] = msg('{line} 行目: 番号の無い行は消せません（足さない行は、答えを空にします）', ['line' => $lineNumber]);
        }
        if ($answerText === '' && !$isDelete) {
            $plan['skippedCount']++;
            continue;
        }
        faq_csv_validate_text($lineNumber, $questionText, $answerText, $rowErrorList);
        faq_csv_check_duplicate($lineNumber, $questionText, $questionLineByKey, $rowErrorList);
        if (!$rowErrorList) {
            $plan['addList'][] = ['category' => $categoryCode, 'question' => $questionText, 'answer' => $answerText, 'lineNumber' => $lineNumber];
        }
        $plan['errorTextList'] = array_merge($plan['errorTextList'], $rowErrorList);
    }
    // CSV に無い質問（そのまま残る）とも、質問の文が重ならないようにする
    foreach ($faqEntryById as $faqId => $faqEntryRow) {
        if (!isset($seenFaqIdSet[$faqId]) && isset($questionLineByKey[faq_csv_question_key((string) $faqEntryRow['question'])])) {
            $plan['errorTextList'][] = msg('{line} 行目: 同じ質問（番号 {id}）がもう載っています', ['line' => $questionLineByKey[faq_csv_question_key((string) $faqEntryRow['question'])], 'id' => $faqId]);
        }
    }
    if ($remainingCount + count($plan['addList']) > FAQ_MAX_COUNT) {
        $plan['errorTextList'][] = msg('よくある質問は {max} 件までです（取り込むと {count} 件になります）', ['max' => FAQ_MAX_COUNT, 'count' => $remainingCount + count($plan['addList'])]);
    }
    return $plan;
}

/** 答えをそろえた形（改行を LF にし、前後の空白を除く。画面で保存するときと同じ） */
function faq_csv_normalize_answer(string $answerText): string
{
    return trim(str_replace(["\r\n", "\r"], "\n", $answerText));
}

/** 質問の文を比べるための形（前後の空白と、全角・半角の違いをそろえる） */
function faq_csv_question_key(string $questionText): string
{
    return function_exists('mb_convert_kana') ? mb_convert_kana(trim($questionText), 'asKV', 'UTF-8') : trim($questionText);
}

/**
 * 質問・答えの長さと必須を確かめる（画面で載せるときと同じ決まり）
 *
 * @param array<int, string> $rowErrorList
 */
function faq_csv_validate_text(int $lineNumber, string $questionText, string $answerText, array &$rowErrorList): void
{
    $fieldErrorList = [];
    validate_text($questionText, msg('質問'), FAQ_QUESTION_MAX_LENGTH, true, $fieldErrorList);
    validate_text($answerText, msg('答え'), FAQ_ANSWER_MAX_LENGTH, true, $fieldErrorList, true);
    foreach ($fieldErrorList as $fieldErrorText) {
        $rowErrorList[] = msg('{line} 行目: {error}', ['line' => $lineNumber, 'error' => $fieldErrorText]);
    }
}

/**
 * 取り込んだあとの質問どうしで、同じ質問の文が無いか
 *
 * @param array<string, int> $questionLineByKey
 * @param array<int, string> $rowErrorList
 */
function faq_csv_check_duplicate(int $lineNumber, string $questionText, array &$questionLineByKey, array &$rowErrorList): void
{
    $questionKey = faq_csv_question_key($questionText);
    if ($questionKey === '') {
        return;
    }
    if (isset($questionLineByKey[$questionKey])) {
        $rowErrorList[] = msg('{line} 行目: 同じ質問が {other} 行目にもあります', ['line' => $lineNumber, 'other' => $questionLineByKey[$questionKey]]);
        return;
    }
    $questionLineByKey[$questionKey] = $lineNumber;
}

/**
 * 計画を反映する（1 つのトランザクション）。確かめたあとに画面で直された・消された質問があれば、何も変えずに null を返す。
 *
 * @param array<string, mixed> $plan faq_csv_plan() の戻り値（誤りが無いもの）
 * @return array{added: int, updated: int, deleted: int}|null
 */
function faq_csv_apply(array $plan, int $userId): ?array
{
    require_once APP_DIR . '/lib/faq_standard.php';   // 標準のよくある質問（よくある質問の画面だけが読むので、ここでも読む）
    return tx(function () use ($plan, $userId): ?array {
        foreach (array_merge($plan['updateList'], $plan['deleteList']) as $plannedRow) {
            if ((string) db_val('SELECT updated_at FROM faq_entries WHERE id = ?', [$plannedRow['id']]) !== $plannedRow['updatedAt']) {
                return null;
            }
        }
        foreach ($plan['deleteList'] as $deleteRow) {
            db_exec('DELETE FROM faq_entries WHERE id = ?', [$deleteRow['id']]);
        }
        foreach ($plan['updateList'] as $updateRow) {
            db_exec('UPDATE faq_entries SET category = ?, question = ?, answer = ?, updated_by = ?, updated_at = ? WHERE id = ?',
                [$updateRow['category'], $updateRow['question'], $updateRow['answer'], $userId, now(), $updateRow['id']]);
        }
        $nextSortOrder = (int) db_val('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM faq_entries');
        $standardAnswerByQuestion = [];
        foreach (FAQ_STANDARD_ENTRIES as [$standardCategory, $standardQuestion, $standardAnswer]) {
            $standardAnswerByQuestion[$standardQuestion] = [$standardCategory, $standardAnswer];
        }
        foreach ($plan['addList'] as $addRow) {
            // 標準の質問をそのまま足したときは、作った日時と直した日時を同じにする（標準の取り込みと同じ。次の版で答えを新しくできる）。
            // 書き換えて足したときは、作った日時を 1 秒前にする（管理者が直した答えとして、標準の取り込みで上書きしない。§2.4）
            $isStandardAsIs = ($standardAnswerByQuestion[$addRow['question']] ?? null) === [$addRow['category'], $addRow['answer']];
            $updatedAt = now();
            $createdAt = $isStandardAsIs ? $updatedAt : date('Y-m-d H:i:s', strtotime($updatedAt) - 1);
            db_exec('INSERT INTO faq_entries (category, question, answer, sort_order, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$addRow['category'], $addRow['question'], $addRow['answer'], $nextSortOrder, $userId, $createdAt, $updatedAt]);
            $nextSortOrder += 10;
        }
        return ['added' => count($plan['addList']), 'updated' => count($plan['updateList']), 'deleted' => count($plan['deleteList'])];
    });
}
