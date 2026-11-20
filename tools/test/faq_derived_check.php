<?php

declare(strict_types=1);

// ====================================================================
// よくある質問（G-25）のチャットの派生入力検証。
//
// ほかの端末から受け取ったチャットの記録（3_記録を書き出す.bat の CSV。他端末検証/ に置く）の質問を種にして、
// 意味を変えない書き換え（派生）を作り、元の質問と同じ判定になるかを確かめる。
//
//   php tools/test/faq_derived_check.php                     他端末検証/ の CSV をすべて使う
//   php tools/test/faq_derived_check.php <CSV か フォルダ か zip> …  使う記録を指定する（例: 他端末検証/202611171700.zip）
//   受け取った記録は <日時>.zip のまま置いてよい（zip の中の CSV を Phar で読む。PHP の zip 拡張は要らない）
//   php tools/test/faq_derived_check.php --all               言い回しの派生の食い違い（参考）も 1 件ずつ出す
//
// 派生の種類:
//   表記（食い違えば NG）  : 句点の付け外し・疑問符・感嘆符・読点を外す・読点を空白に・前後の空白・英数字の全角半角・半角カナ
//   言い回し（参考。NG にしない）: 前置き（すみません、・あの、）・後ろに「よろしくお願いします。」
// 判定はチャットと同じ関数（faq_classify_in_conversation()）。会話の列がある記録は、元の発言で会話をたどり、
// 同じ前の話題のもとで派生を判定する。よくある質問は標準のもの（faq_standard.php）を、聞いた人の役割に見える範囲にして使う。
// DB・サーバーは使わない。
//
// 要配慮・緊急の文（だれにも見せない決まり。G-25 §2.9）は表示を伏せ、記録の番号だけを出す。
// 結果の一式は tools/test/output/faq_derived/<日時>/ に書く（Git 管理外）:
//   derived_cases.tsv  前の話題のない元の質問と、元と同じ判定になった派生を、今の判定（ふつうの利用者に見える範囲）を期待にして faq_input_cases.tsv の形で書いたもの
//                      （php tools/test/faq_input_check.php --cases <このファイル> で、のちの変更で判定が変わっていないかを確かめられる。
//                        今の判定の写しなので、判定が正しいかは別に確かめる）。要配慮・緊急の文は書かない
//   result.txt         画面に出したものと同じ内容
// ====================================================================

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_DIR . '/lib/faq_standard.php';

$isAllShown = in_array('--all', $argv, true);
$sourcePathList = array_values(array_filter(array_slice($argv, 1), static fn (string $argumentText): bool => strpos($argumentText, '--') !== 0));
if ($sourcePathList === []) {
    $sourcePathList = [dirname(__DIR__, 2) . '/他端末検証'];
}

// ---- 記録の CSV を集める ----
/**
 * zip の中のチャットの記録の CSV（phar:// のパス。fopen でそのまま読める）。DB・鍵だけの zip（app.zip）は 0 件
 *
 * @return string[]
 */
function faq_derived_csv_paths_in_zip(string $zipPath): array
{
    $csvPathList = [];
    $zipIterator = new RecursiveIteratorIterator(new PharData(str_replace('\\', '/', $zipPath)));
    foreach ($zipIterator as $zipEntry) {
        if (preg_match('/^faq_chat_log_.*\.csv$/', $zipEntry->getFilename()) === 1) {
            $csvPathList[] = $zipEntry->getPathname();
        }
    }
    return $csvPathList;
}
$csvPathList = [];
foreach ($sourcePathList as $sourcePath) {
    if (is_dir($sourcePath)) {
        $directoryIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS));
        foreach ($directoryIterator as $fileInfo) {
            if (preg_match('/^faq_chat_log_.*\.csv$/', $fileInfo->getFilename()) === 1) {
                $csvPathList[] = $fileInfo->getPathname();
            } elseif (preg_match('/\.zip$/i', $fileInfo->getFilename()) === 1) {
                array_push($csvPathList, ...faq_derived_csv_paths_in_zip($fileInfo->getPathname()));
            }
        }
    } elseif (is_file($sourcePath) && preg_match('/\.zip$/i', $sourcePath) === 1) {
        array_push($csvPathList, ...faq_derived_csv_paths_in_zip($sourcePath));
    } elseif (is_file($sourcePath)) {
        $csvPathList[] = $sourcePath;
    } else {
        fwrite(STDERR, "記録のファイル・フォルダがありません: $sourcePath\n");
        exit(2);
    }
}
sort($csvPathList);
if ($csvPathList === []) {
    // 他端末検証/ は Git 管理外。記録が無い端末では確かめるものが無い
    echo "OK  よくある質問の派生入力検証（記録の CSV がないため 0 件）\n";
    exit(0);
}

// ---- 役割ごとに見えるよくある質問 ----
$staffFaqList = [];
$ordinaryFaqList = [];
foreach (FAQ_STANDARD_ENTRIES as $entryIndex => [$standardCategory, $standardQuestion, $standardAnswer]) {
    $faqRow = ['id' => $entryIndex + 1, 'category' => $standardCategory, 'question' => $standardQuestion, 'answer' => $standardAnswer];
    $staffFaqList[] = $faqRow;
    if (!in_array($standardCategory, FAQ_STAFF_ONLY_CATEGORIES, true)) {
        $ordinaryFaqList[] = $faqRow;
    }
}

/**
 * 派生を作る。意味を変えない書き換えだけ。元と同じ文になるものは作らない。
 *
 * @return array<int, array{kind: string, strict: bool, text: string}> strict: 食い違えば NG にするか
 */
function faq_derived_variants(string $questionText): array
{
    $trimmedText = trim($questionText);
    $variantList = [];
    $hasEndingMark = preg_match('/[。．.！!？?…]$/u', $trimmedText) === 1;
    // 表記
    $variantList[] = ['kind' => '句点の付け外し', 'strict' => true,
        'text' => $hasEndingMark ? (string) preg_replace('/[。．.！!？?…]+$/u', '', $trimmedText) : $trimmedText . '。'];
    if (!$hasEndingMark) {
        $variantList[] = ['kind' => '疑問符を付ける', 'strict' => true, 'text' => $trimmedText . '？'];
        $variantList[] = ['kind' => '感嘆符を付ける', 'strict' => true, 'text' => $trimmedText . '！！'];
    }
    $variantList[] = ['kind' => '読点を外す', 'strict' => true, 'text' => (string) preg_replace('/[、,，]/u', '', $trimmedText)];
    $variantList[] = ['kind' => '読点を空白に', 'strict' => true, 'text' => (string) preg_replace('/[、,，]/u', '　', $trimmedText)];
    $variantList[] = ['kind' => '前後の空白', 'strict' => true, 'text' => '　' . $trimmedText . ' '];
    $variantList[] = ['kind' => '英数字を全角に', 'strict' => true, 'text' => (string) preg_replace_callback('/[!-~]/', static fn (array $characterMatch): string => mb_chr(ord($characterMatch[0]) + 0xFEE0, 'UTF-8'), $trimmedText)];
    $variantList[] = ['kind' => '英数字を半角に', 'strict' => true, 'text' => (string) preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', static fn (array $characterMatch): string => chr(mb_ord($characterMatch[0], 'UTF-8') - 0xFEE0), $trimmedText)];
    $variantList[] = ['kind' => '半角カナ', 'strict' => true, 'text' => mb_convert_kana($trimmedText, 'k', 'UTF-8')];
    // 言い回し（参考）
    $variantList[] = ['kind' => '前置き「すみません、」', 'strict' => false, 'text' => 'すみません、' . $trimmedText];
    $variantList[] = ['kind' => '前置き「あの、」', 'strict' => false, 'text' => 'あの、' . $trimmedText];
    $variantList[] = ['kind' => '後ろに「よろしくお願いします。」', 'strict' => false, 'text' => $trimmedText . ($hasEndingMark ? '' : '。') . 'よろしくお願いします。'];
    $uniqueVariantList = [];
    $seenTextList = [$trimmedText];
    foreach ($variantList as $variant) {
        if ($variant['text'] === '' || in_array($variant['text'], $seenTextList, true)) {
            continue;
        }
        $seenTextList[] = $variant['text'];
        $uniqueVariantList[] = $variant;
    }
    return $uniqueVariantList;
}

/** 判定を比べられる形にする（結果・いちばんの答え・心の不調の段階・知らせ・上司からのハラスメント） */
function faq_derived_signature(array $classification): string
{
    $signatureText = $classification['code'];
    if ($classification['matches'] !== [] && in_array($classification['code'], ['matched', 'distress', 'sensitive'], true)) {
        $signatureText .= '（' . $classification['matches'][0]['question'] . '）';
    }
    if ($classification['distressLevel'] !== '') {
        $signatureText .= ' 段階: ' . $classification['distressLevel'];
    }
    if ($classification['noticeLevel'] !== '') {
        $signatureText .= ' [知らせ: ' . $classification['noticeLevel'] . ']';
    }
    if (!empty($classification['harassmentBySuperior'])) {
        $signatureText .= ' [上司からのハラスメント]';
    }
    return $signatureText;
}

/** 表示してよい文か（要配慮・緊急の文は、だれにも見せない） */
function faq_derived_is_hidden(string $questionText): bool
{
    return faq_is_emergency($questionText) || faq_is_sensitive($questionText);
}

/** 今の判定を、faq_input_cases.tsv の区分と期待の欄にする */
function faq_derived_case_columns(array $classification): array
{
    $resultCode = $classification['code'];
    if ($resultCode === 'matched') {
        $expectationText = 'matched=' . $classification['matches'][0]['question'];
        $groupName = '寄る';
    } elseif (in_array($resultCode, ['unrelated', 'abusive', 'emergency', 'distress', 'retirement', 'sensitive', 'violence', 'sabotage'], true)) {
        $expectationText = $resultCode;
        $groupName = 'アウト';
    } else {
        $expectationText = $resultCode;
        $groupName = 'グレー';
    }
    if ($classification['noticeLevel'] !== '' && $classification['noticeLevel'] !== $classification['distressLevel']) {
        $expectationText .= ' +' . $classification['noticeLevel'];
    }
    return [$groupName, $expectationText];
}

// ---- 記録を読み、会話をたどって派生を判定する ----
$outputLineList = [];
$derivedCaseLineList = [];
$checkedKeyList = [];
$seedCount = 0;
$countByKind = [];
$strictNgList = [];
$looseDiffList = [];
foreach ($csvPathList as $csvPath) {
    $csvHandle = fopen($csvPath, 'r');
    if (fread($csvHandle, 3) !== "\xEF\xBB\xBF") {
        rewind($csvHandle);
    }
    $headerRow = fgetcsv($csvHandle, 0, ',', '"', '') ?: [];
    $topicByConversationId = [];
    $csvLabel = basename(dirname($csvPath)) . '/' . basename($csvPath);
    while (($csvRow = fgetcsv($csvHandle, 0, ',', '"', '')) !== false) {
        if (count($csvRow) !== count($headerRow)) {
            continue;
        }
        $logRow = array_combine($headerRow, $csvRow);
        $conversationId = (string) ($logRow['会話'] ?? '');
        $questionText = (string) $logRow['質問・選んだ分野'];
        if (strpos($questionText, '分野:') === 0) {
            $topicByConversationId[$conversationId] = '';
            continue;
        }
        if (trim($questionText) === '' || strpos($questionText, '（要配慮の内容') === 0) {
            continue;
        }
        $visibleFaqList = (string) $logRow['役割'] === '管理者・補佐' ? $staffFaqList : $ordinaryFaqList;
        $previousTopic = $conversationId !== '' ? ($topicByConversationId[$conversationId] ?? '') : '';
        $baseJudgement = faq_classify_in_conversation($questionText, $previousTopic, $visibleFaqList);
        if ($conversationId !== '') {
            $topicByConversationId[$conversationId] = $baseJudgement['nextTopic'];
        }
        // 同じ前の話題・同じ見える範囲の同じ質問は 1 回だけ確かめる
        $checkedKey = $previousTopic . "\t" . count($visibleFaqList) . "\t" . trim($questionText);
        if (isset($checkedKeyList[$checkedKey])) {
            continue;
        }
        $checkedKeyList[$checkedKey] = true;
        $seedCount++;
        $baseSignature = faq_derived_signature($baseJudgement['classification']);
        $isSeedHidden = faq_derived_is_hidden($questionText);
        $seedLabel = $csvLabel . ' #' . $logRow['番号'];
        $seedShownText = $isSeedHidden ? '（要配慮の内容のため表示しません）' : $questionText;
        // derived_cases.tsv は faq_input_check.php と同じく、ふつうの利用者に見える範囲で判定した結果を期待にする
        if (!$isSeedHidden && $previousTopic === '') {
            [$groupName, $expectationText] = faq_derived_case_columns(faq_classify_question($questionText, $ordinaryFaqList));
            $derivedCaseLineList[] = "$groupName\t$expectationText\t" . trim($questionText) . "\t（元: {$seedLabel}）";
        }
        foreach (faq_derived_variants($questionText) as $variant) {
            $variantJudgement = faq_classify_in_conversation($variant['text'], $previousTopic, $visibleFaqList);
            $variantSignature = faq_derived_signature($variantJudgement['classification']);
            $kindKey = ($variant['strict'] ? '表記: ' : '言い回し: ') . $variant['kind'];
            $countByKind[$kindKey]['all'] = ($countByKind[$kindKey]['all'] ?? 0) + 1;
            $isVariantHidden = $isSeedHidden || faq_derived_is_hidden($variant['text']);
            if ($variantSignature === $baseSignature) {
                if (!$isVariantHidden && $previousTopic === '') {
                    [$groupName, $expectationText] = faq_derived_case_columns(faq_classify_question($variant['text'], $ordinaryFaqList));
                    $derivedCaseLineList[] = "$groupName\t$expectationText\t" . trim($variant['text']) . "\t（元: {$seedLabel}。{$variant['kind']}）";
                }
                continue;
            }
            $countByKind[$kindKey]['diff'] = ($countByKind[$kindKey]['diff'] ?? 0) + 1;
            $diffText = sprintf("%s  %s\n    元  : %s => %s\n    派生: %s => %s%s",
                $seedLabel, $kindKey, $seedShownText, $baseSignature,
                $isVariantHidden ? '（要配慮の内容のため表示しません）' : $variant['text'], $variantSignature,
                $previousTopic !== '' ? "\n    （前の話題あり）" : '');
            if ($variant['strict']) {
                $strictNgList[] = $diffText;
            } else {
                $looseDiffList[] = $diffText;
            }
        }
    }
    fclose($csvHandle);
}

// ---- 出す ----
foreach ($strictNgList as $diffText) {
    $outputLineList[] = 'NG  ' . $diffText;
}
if ($isAllShown) {
    foreach ($looseDiffList as $diffText) {
        $outputLineList[] = '参考 ' . $diffText;
    }
}
$outputLineList[] = '';
$outputLineList[] = '使った記録: ' . count($csvPathList) . ' ファイル。種にした質問: ' . $seedCount . ' 件（同じ前の話題の同じ質問は 1 件に数える）';
ksort($countByKind);
foreach ($countByKind as $kindKey => $kindCount) {
    $outputLineList[] = sprintf('  %s: %d 件中 %d 件 食い違い', $kindKey, $kindCount['all'], $kindCount['diff'] ?? 0);
}
if (!$isAllShown && $looseDiffList) {
    $outputLineList[] = '言い回しの派生の食い違い（参考）は --all で 1 件ずつ出す';
}
$totalVariantCount = array_sum(array_column($countByKind, 'all'));
$outputLineList[] = (count($strictNgList) === 0 ? 'OK  ' : 'NG  ') . 'よくある質問の派生入力検証（派生 ' . $totalVariantCount . ' 件。表記の食い違い ' . count($strictNgList) . ' 件、言い回しの食い違い（参考） ' . count($looseDiffList) . ' 件）';

$outputDirectory = __DIR__ . '/output/faq_derived/' . date('Ymd_His');
if (!is_dir($outputDirectory)) {
    mkdir($outputDirectory, 0777, true);
}
$caseFileHeader = "# よくある質問の派生入力（tools/test/faq_derived_check.php が書いた。期待は書いた時点の判定の写し）\n"
    . "# php tools/test/faq_input_check.php --cases <このファイル> で、のちの変更で判定が変わっていないかを確かめる\n";
file_put_contents($outputDirectory . '/derived_cases.tsv', $caseFileHeader . implode("\n", $derivedCaseLineList) . "\n");
file_put_contents($outputDirectory . '/result.txt', implode("\n", $outputLineList) . "\n");
$outputLineList[] = '結果の一式: ' . str_replace('\\', '/', $outputDirectory) . '/（derived_cases.tsv ' . count($derivedCaseLineList) . ' 件・result.txt）';
echo implode("\n", $outputLineList), "\n";
exit(count($strictNgList) === 0 ? 0 : 1);
