<?php

declare(strict_types=1);

// ====================================================================
// よくある質問（G-25）のチャットの自動入力検証。
//
//   php tools/test/faq_input_check.php            食い違いだけを出す（自動検証で使う）
//   php tools/test/faq_input_check.php --all      すべての入力の判定を出す
//   php tools/test/faq_input_check.php --cases <ファイル>   入力のファイルを変える（既定は tools/test/faq_input_cases.tsv）
//
// 入力（faq_input_cases.tsv）を 3 つに分けて、チャットと同じ判定（faq_classify_question()）にかける。
//   寄る  : システムにはっきり寄る質問。答えること（決めた答えであること）
//   グレー: グレーゾーン。決めた範囲（答える・聞き返す・見つからない・断る）に入っていること
//   アウト: 明確にアウト。関係のない質問・不適切な表現・緊急・心の不調・退職・要配慮として扱い、ふつうの答えを出さないこと
//   会話  : 続けて入れた発言（「／」で区切る）。最後の発言の判定を確かめる。中身のない発言に前の話題を補う（faq_classify_in_conversation()。G-25 §2.14）
// 判定に使うよくある質問は、標準のよくある質問（faq_standard.php）を、ふつうの利用者に見える範囲にしたもの。
// DB・サーバーは使わない（チャットの記録・数えるセッションに触れない）。
// ====================================================================

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_DIR . '/lib/faq_standard.php';

$isAllShown = in_array('--all', $argv, true);
$casesPath = __DIR__ . '/faq_input_cases.tsv';
$casesOptionIndex = array_search('--cases', $argv, true);
if ($casesOptionIndex !== false) {
    $casesPath = (string) ($argv[$casesOptionIndex + 1] ?? '');
}
if (!is_file($casesPath)) {
    fwrite(STDERR, "入力のファイルがありません: $casesPath\n");
    exit(2);
}

// ふつうの利用者に見える標準のよくある質問（管理者・補佐だけの分野を除く）
$visibleFaqList = [];
foreach (FAQ_STANDARD_ENTRIES as $entryIndex => [$standardCategory, $standardQuestion, $standardAnswer]) {
    if (in_array($standardCategory, FAQ_STAFF_ONLY_CATEGORIES, true)) {
        continue;
    }
    $visibleFaqList[] = ['id' => $entryIndex + 1, 'category' => $standardCategory, 'question' => $standardQuestion, 'answer' => $standardAnswer];
}

const FAQ_INPUT_GROUP_NAMES = ['寄る', 'グレー', 'アウト', '会話'];
const FAQ_INPUT_RESULT_CODES = ['matched', 'no_match', 'opener', 'closing', 'vague', 'unrelated', 'abusive', 'emergency', 'distress', 'retirement', 'sensitive', 'violence'];

/**
 * 期待の欄を読む。「matched=ログインできない|no_match +credential」→ 許す結果・答えの質問の一部・知らせの理由
 *
 * @return array{codes: string[], matchedQuestionParts: string[], noticeLevel: string}|null 書き方が正しくなければ null
 */
function faq_input_parse_expectation(string $expectationText): ?array
{
    $noticeLevel = '';
    if (preg_match('/\s\+(\w+)$/u', $expectationText, $noticeMatch) === 1) {
        $noticeLevel = $noticeMatch[1];
        $expectationText = trim(substr($expectationText, 0, -strlen($noticeMatch[0])));
    }
    $allowedCodeList = [];
    $matchedQuestionPartList = [];
    foreach (explode('|', $expectationText) as $expectationPart) {
        $expectationPart = trim($expectationPart);
        if (strpos($expectationPart, 'matched=') === 0) {
            $matchedQuestionPartList[] = substr($expectationPart, strlen('matched='));
            $expectationPart = 'matched';
        }
        if (!in_array($expectationPart, FAQ_INPUT_RESULT_CODES, true)) {
            return null;
        }
        if (!in_array($expectationPart, $allowedCodeList, true)) {
            $allowedCodeList[] = $expectationPart;
        }
    }
    return ['codes' => $allowedCodeList, 'matchedQuestionParts' => $matchedQuestionPartList, 'noticeLevel' => $noticeLevel];
}

$caseList = [];
$formatErrorList = [];
foreach (file($casesPath, FILE_IGNORE_NEW_LINES) ?: [] as $lineIndex => $lineText) {
    $lineText = rtrim(preg_replace('/^\xEF\xBB\xBF/', '', $lineText) ?? $lineText, "\r");
    if (trim($lineText) === '' || strpos(ltrim($lineText), '#') === 0) {
        continue;
    }
    $columnList = explode("\t", $lineText);
    $expectation = count($columnList) >= 3 ? faq_input_parse_expectation($columnList[1]) : null;
    if (count($columnList) < 3 || !in_array($columnList[0], FAQ_INPUT_GROUP_NAMES, true) || $expectation === null || trim($columnList[2]) === '') {
        $formatErrorList[] = '書き方が正しくない（' . ($lineIndex + 1) . ' 行目）: ' . $lineText;
        continue;
    }
    $caseList[] = ['line' => $lineIndex + 1, 'group' => $columnList[0], 'expectation' => $expectation, 'question' => $columnList[2]];
}

$resultLabelByCode = [
    'matched' => '答えた', 'no_match' => '見つからない', 'opener' => '呼びかけ（ご用件を聞く）', 'closing' => '用件なし（受け止める）', 'vague' => 'あいまい（分野を選ぶ）', 'unrelated' => '関係のない質問',
    'abusive' => '不適切な表現', 'emergency' => '緊急（119 番）', 'distress' => '心の不調', 'retirement' => '自分の退職', 'sensitive' => '要配慮（寄り添う返事）', 'violence' => '人への暴力（カウンセリングをすすめる）',
];
$countByGroup = [];
$mismatchList = [];
$grayCountByCode = [];
foreach ($caseList as $case) {
    // 会話は「／」で区切った発言を順に入れ、最後の発言の判定を見る（前の話題の補いを含む）。ほかは 1 つの発言として、前の話題なしで判定する
    $utteranceList = $case['group'] === '会話' ? array_values(array_filter(array_map('trim', explode('／', $case['question'])), 'strlen')) : [$case['question']];
    $conversationTopic = '';
    $conversationJudgement = null;
    foreach ($utteranceList as $utteranceText) {
        $conversationJudgement = faq_classify_in_conversation($utteranceText, $conversationTopic, $visibleFaqList);
        $conversationTopic = $conversationJudgement['nextTopic'];
    }
    $classification = $conversationJudgement['classification'];
    $contextTopicText = $conversationJudgement['contextTopic'];
    $resultCode = $classification['code'];
    $topQuestion = $classification['matches'] ? (string) $classification['matches'][0]['question'] : '';
    $expectation = $case['expectation'];
    $reasonList = [];
    if (!in_array($resultCode, $expectation['codes'], true)) {
        $reasonList[] = '結果が「' . implode('・', array_map(static fn (string $code): string => $resultLabelByCode[$code], $expectation['codes'])) . '」でない';
    } elseif ($resultCode === 'matched' && $expectation['matchedQuestionParts'] !== []
        && !array_filter($expectation['matchedQuestionParts'], static fn (string $questionPart): bool => strpos($topQuestion, $questionPart) !== false)) {
        $reasonList[] = 'いちばんの答えが「' . implode('」か「', $expectation['matchedQuestionParts']) . '」の質問でない';
    }
    if ($expectation['noticeLevel'] === 'harassment') {
        // 「+harassment」: 上司からのハラスメントとして、ほかの管理職・相談窓口への相談をすすめる返事にするか
        if (empty($classification['harassmentBySuperior'])) {
            $reasonList[] = '上司からのハラスメントとして扱っていない';
        }
    } elseif ($expectation['noticeLevel'] !== '' && $classification['noticeLevel'] !== $expectation['noticeLevel']) {
        $reasonList[] = '管理者への知らせが「' . $expectation['noticeLevel'] . '」でない（' . ($classification['noticeLevel'] === '' ? 'なし' : $classification['noticeLevel']) . '）';
    }
    $countByGroup[$case['group']]['all'] = ($countByGroup[$case['group']]['all'] ?? 0) + 1;
    if ($reasonList) {
        $countByGroup[$case['group']]['ng'] = ($countByGroup[$case['group']]['ng'] ?? 0) + 1;
    }
    if ($case['group'] === 'グレー') {
        $grayCountByCode[$resultCode] = ($grayCountByCode[$resultCode] ?? 0) + 1;
    }
    $resultText = ($contextTopicText !== '' ? '［前の話題「' . $contextTopicText . '」を補った］' : '') . $resultLabelByCode[$resultCode] . ($topQuestion !== '' ? '（' . $topQuestion . '）' : '')
        . ($classification['noticeLevel'] !== '' ? ' [知らせ: ' . $classification['noticeLevel'] . ']' : '')
        . (!empty($classification['harassmentBySuperior']) ? ' [上司からのハラスメント: ほかの管理職へ]' : '');
    // 緊急・要配慮の文は、記録と同じく画面には出さない決まりだが、この入力は作り物の例文なのでそのまま出す
    if ($reasonList) {
        $mismatchList[] = sprintf('NG  [%s] %s => %s ／ %s（%d 行目）', $case['group'], $case['question'], $resultText, implode('、', $reasonList), $case['line']);
    } elseif ($isAllShown) {
        echo sprintf("OK  [%s] %s => %s\n", $case['group'], $case['question'], $resultText);
    }
}

foreach ($formatErrorList as $formatErrorText) {
    echo 'NG  ', $formatErrorText, "\n";
}
foreach ($mismatchList as $mismatchText) {
    echo $mismatchText, "\n";
}
echo "\n";
foreach (FAQ_INPUT_GROUP_NAMES as $groupName) {
    $groupAllCount = $countByGroup[$groupName]['all'] ?? 0;
    $groupNgCount = $countByGroup[$groupName]['ng'] ?? 0;
    echo sprintf("%-4s %d 件中 %d 件 OK\n", $groupName, $groupAllCount, $groupAllCount - $groupNgCount);
}
if ($grayCountByCode) {
    arsort($grayCountByCode);
    echo 'グレーの内訳: ', implode('、', array_map(static fn (string $code, int $count): string => $resultLabelByCode[$code] . ' ' . $count, array_keys($grayCountByCode), $grayCountByCode)), "\n";
}
// 自動検証（verify_all.py）が数える形でも出す
$totalNgCount = count($mismatchList) + count($formatErrorList);
echo ($totalNgCount === 0 ? 'OK  ' : 'NG  '), 'よくある質問の自動入力検証（', count($caseList), " 件。食い違い {$totalNgCount} 件）\n";
exit($totalNgCount === 0 ? 0 : 1);
