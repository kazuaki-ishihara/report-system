<?php

declare(strict_types=1);

// ====================================================================
// よくある質問（G-25）のチャットの組み合わせ入力検証。
//
//   php tools/test/faq_combo_check.php            食い違いだけを出す（自動検証で使う）
//   php tools/test/faq_combo_check.php --all      食い違いを、まとめずにすべて出す
//   php tools/test/faq_combo_check.php --cases <ファイル>   部品のファイルを変える（既定は tools/test/faq_combo_cases.txt）
//
// 記録に出てきた文と、その表記ゆれ（派生入力検証）だけでは、まだ誰も入れていない言い方を確かめられない。
// そこで、文を部品（前置き・たとえ・中心の言葉・語尾など）に分け、部品のすべての組み合わせを作って、
// チャットと同じ判定（faq_classify_question()）が想定の結果になるかを確かめる（2026-11-18）。
//
// 部品のファイルの書き方（faq_combo_cases.txt）:
//   === グループの名前          グループの始まり
//   期待: distress|emergency     許す結果（faq_input_check.php の期待と同じ書き方。matched=質問の一部 も使える）
//   部品: |最近、|もう           1 つの部品の言い方を「|」で区切る。空の言い方（その部品を入れない）は、先頭の「|」で書く
//   上限: 3000                   組み合わせがこれを超えたら、決まった間隔で間引く（既定 5000）
// 判定に使うよくある質問は、標準のよくある質問を、ふつうの利用者に見える範囲にしたもの。DB・サーバーは使わない。
// ====================================================================

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_DIR . '/lib/faq_standard.php';

const FAQ_COMBO_RESULT_CODES = ['matched', 'no_match', 'opener', 'closing', 'vague', 'unrelated', 'abusive', 'emergency', 'distress', 'retirement', 'sensitive', 'violence', 'sabotage'];
const FAQ_COMBO_DEFAULT_LIMIT = 5000;
/** 食い違いを、部品の言い方ごとに何件まで例として出すか */
const FAQ_COMBO_EXAMPLE_COUNT = 3;

$isAllShown = in_array('--all', $argv, true);
$casesPath = __DIR__ . '/faq_combo_cases.txt';
$casesOptionIndex = array_search('--cases', $argv, true);
if ($casesOptionIndex !== false) {
    $casesPath = (string) ($argv[$casesOptionIndex + 1] ?? '');
}
if (!is_file($casesPath)) {
    fwrite(STDERR, "部品のファイルがありません: $casesPath\n");
    exit(2);
}

$visibleFaqList = [];
foreach (FAQ_STANDARD_ENTRIES as $entryIndex => [$standardCategory, $standardQuestion, $standardAnswer]) {
    if (in_array($standardCategory, FAQ_STAFF_ONLY_CATEGORIES, true)) {
        continue;
    }
    $visibleFaqList[] = ['id' => $entryIndex + 1, 'category' => $standardCategory, 'question' => $standardQuestion, 'answer' => $standardAnswer];
}

// ---- 部品のファイルを読む ----
$groupList = [];   // [['name' => string, 'codes' => string[], 'matchedParts' => string[], 'parts' => string[][], 'limit' => int], ...]
$formatErrorList = [];
foreach (file($casesPath, FILE_IGNORE_NEW_LINES) ?: [] as $lineIndex => $lineText) {
    $lineText = rtrim(preg_replace('/^\xEF\xBB\xBF/', '', $lineText) ?? $lineText, "\r");
    $lineNumber = $lineIndex + 1;
    if (trim($lineText) === '' || strpos(ltrim($lineText), '#') === 0) {
        continue;
    }
    if (strpos($lineText, '=== ') === 0) {
        $groupList[] = ['name' => trim(substr($lineText, 4)), 'codes' => [], 'matchedParts' => [], 'parts' => [], 'limit' => FAQ_COMBO_DEFAULT_LIMIT, 'line' => $lineNumber];
        continue;
    }
    if ($groupList === []) {
        $formatErrorList[] = "$lineNumber 行目: グループ（=== 名前）の前に書かれています";
        continue;
    }
    $currentGroupIndex = count($groupList) - 1;
    if (strpos($lineText, '期待:') === 0) {
        foreach (explode('|', trim(substr($lineText, strlen('期待:')))) as $expectationPart) {
            $expectationPart = trim($expectationPart);
            if (strpos($expectationPart, 'matched=') === 0) {
                $groupList[$currentGroupIndex]['matchedParts'][] = substr($expectationPart, strlen('matched='));
                $expectationPart = 'matched';
            }
            if (!in_array($expectationPart, FAQ_COMBO_RESULT_CODES, true)) {
                $formatErrorList[] = "$lineNumber 行目: 期待の結果が正しくありません（$expectationPart）";
                continue;
            }
            $groupList[$currentGroupIndex]['codes'][] = $expectationPart;
        }
    } elseif (strpos($lineText, '部品:') === 0) {
        $groupList[$currentGroupIndex]['parts'][] = array_map('trim', explode('|', substr($lineText, strlen('部品:'))));
    } elseif (strpos($lineText, '上限:') === 0) {
        $groupList[$currentGroupIndex]['limit'] = max(1, (int) trim(substr($lineText, strlen('上限:'))));
    } else {
        $formatErrorList[] = "$lineNumber 行目: 書き方が正しくありません";
    }
}
foreach ($groupList as $group) {
    if ($group['codes'] === [] || $group['parts'] === []) {
        $formatErrorList[] = $group['line'] . ' 行目: 「' . $group['name'] . '」に期待か部品がありません';
    }
}
if ($formatErrorList) {
    fwrite(STDERR, implode("\n", $formatErrorList) . "\n");
    exit(2);
}

/**
 * 部品のすべての組み合わせ（上限を超えたら、決まった間隔で間引く。毎回同じものになる）
 *
 * @param string[][] $partList
 * @return array<int, string[]> 組み合わせごとの、部品ごとの言い方
 */
function faq_combo_products(array $partList, int $limit): array
{
    $totalCount = 1;
    foreach ($partList as $partAlternativeList) {
        $totalCount *= count($partAlternativeList);
    }
    $step = $totalCount > $limit ? (int) ceil($totalCount / $limit) : 1;
    $productList = [];
    for ($productIndex = 0; $productIndex < $totalCount; $productIndex += $step) {
        $remainingIndex = $productIndex;
        $chosenList = [];
        for ($partIndex = count($partList) - 1; $partIndex >= 0; $partIndex--) {
            $alternativeCount = count($partList[$partIndex]);
            $chosenList[$partIndex] = $partList[$partIndex][$remainingIndex % $alternativeCount];
            $remainingIndex = intdiv($remainingIndex, $alternativeCount);
        }
        ksort($chosenList);
        $productList[] = $chosenList;
    }
    return $productList;
}

$resultLabels = ['matched' => '答えた', 'no_match' => '見つからない', 'opener' => '呼びかけ', 'closing' => '用件なし', 'vague' => 'あいまい', 'unrelated' => '関係のない質問',
    'abusive' => '不適切な表現', 'emergency' => '緊急', 'distress' => '心の不調', 'retirement' => '自分の退職', 'sensitive' => '要配慮', 'violence' => '人への暴力', 'sabotage' => 'システム・データを壊す'];

// ---- 組み合わせを判定する ----
$totalCheckedCount = 0;
$totalMismatchCount = 0;
$outputLineList = [];
foreach ($groupList as $group) {
    $mismatchList = [];
    $productList = faq_combo_products($group['parts'], $group['limit']);
    foreach ($productList as $chosenList) {
        $questionText = implode('', $chosenList);
        if (trim($questionText) === '') {
            continue;
        }
        $totalCheckedCount++;
        $classification = faq_classify_question($questionText, $visibleFaqList);
        $isExpected = in_array($classification['code'], $group['codes'], true);
        if ($isExpected && $classification['code'] === 'matched' && $group['matchedParts']) {
            $firstQuestion = (string) ($classification['matches'][0]['question'] ?? '');
            $isExpected = false;
            foreach ($group['matchedParts'] as $matchedPart) {
                $isExpected = $isExpected || strpos($firstQuestion, $matchedPart) !== false;
            }
        }
        if (!$isExpected) {
            $resultText = $resultLabels[$classification['code']] ?? $classification['code'];
            if ($classification['code'] === 'matched') {
                $resultText .= ' → ' . (string) ($classification['matches'][0]['question'] ?? '');
            }
            $mismatchList[] = ['text' => $questionText, 'result' => $resultText, 'chosen' => $chosenList];
        }
    }
    $totalMismatchCount += count($mismatchList);
    $checkedInGroup = count($productList);
    $outputLineList[] = ($mismatchList ? 'NG  ' : 'OK  ') . '[' . $group['name'] . '] ' . $checkedInGroup . ' 件中 ' . count($mismatchList) . ' 件が想定と違う（想定: '
        . implode('・', array_map(static function (string $code) use ($resultLabels): string { return $resultLabels[$code] ?? $code; }, $group['codes'])) . '）';
    if (!$mismatchList) {
        continue;
    }
    if ($isAllShown) {
        foreach ($mismatchList as $mismatch) {
            $outputLineList[] = '      ' . $mismatch['text'] . ' => ' . $mismatch['result'];
        }
        continue;
    }
    // 部品の言い方ごとに、食い違いの数を数える（どの言い方が取りこぼされているかを見つけやすくする）
    $mismatchCountByPartChoice = [];
    $exampleByPartChoice = [];
    foreach ($mismatchList as $mismatch) {
        foreach ($mismatch['chosen'] as $partIndex => $chosenText) {
            if (count($group['parts'][$partIndex]) <= 1) {
                continue;
            }
            $partChoiceKey = ($partIndex + 1) . ':' . ($chosenText === '' ? '（なし）' : $chosenText);
            $mismatchCountByPartChoice[$partChoiceKey] = ($mismatchCountByPartChoice[$partChoiceKey] ?? 0) + 1;
            if (count($exampleByPartChoice[$partChoiceKey] ?? []) < FAQ_COMBO_EXAMPLE_COUNT) {
                $exampleByPartChoice[$partChoiceKey][] = $mismatch['text'] . ' => ' . $mismatch['result'];
            }
        }
    }
    // 部品ごとに、同じ部品のほかの言い方より食い違いが多い言い方（取りこぼしの原因らしいもの）を出す
    $productCountPerChoice = [];
    foreach ($group['parts'] as $partIndex => $partAlternativeList) {
        $productCountPerChoice[$partIndex] = intdiv($checkedInGroup, max(1, count($partAlternativeList)));
    }
    arsort($mismatchCountByPartChoice);
    foreach ($mismatchCountByPartChoice as $partChoiceKey => $mismatchCount) {
        [$partNumberText] = explode(':', $partChoiceKey, 2);
        $choiceProductCount = max(1, $productCountPerChoice[(int) $partNumberText - 1]);
        if ($mismatchCount * 2 < $choiceProductCount && count($mismatchCountByPartChoice) > 8) {
            continue;   // 半分未満しか食い違わない言い方は、原因ではなさそうなので省く（数が多いとき）
        }
        $outputLineList[] = '      部品 ' . $partChoiceKey . ' … ' . $mismatchCount . ' 件';
        foreach ($exampleByPartChoice[$partChoiceKey] as $exampleText) {
            $outputLineList[] = '          例: ' . $exampleText;
        }
    }
}
echo implode("\n", $outputLineList) . "\n";
echo ($totalMismatchCount === 0 ? 'OK' : 'NG') . "  よくある質問の組み合わせ入力検証（" . count($groupList) . ' グループ・' . $totalCheckedCount . ' 件。想定と違う ' . $totalMismatchCount . " 件）\n";
exit($totalMismatchCount === 0 ? 0 : 1);
