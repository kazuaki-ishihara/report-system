<?php

declare(strict_types=1);

// ====================================================================
// 標準のよくある質問と分野の名前に、見直す前の言い方が残っていないか（2026-11-26 の文言の見直し。G-25 §2.4）
//   php tools/test/check_faq_wording.php
// 見直し: 部下 → 一般職、管理者 → システム管理者（「（管理者）」「管理者・補佐」）、班 → ライン、組織の改訂 → 組織の改編。
// 置き換えの行き過ぎ（「項目システム管理者」「システムシステム」）も見る。
// 「確認者」「リーダー」は、見直したあとも残した言い方なので見ない。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('APP_DIR', dirname(__DIR__, 2) . '/app');
require APP_DIR . '/lib/faq_standard.php';
$noticeSource = (string) file_get_contents(APP_DIR . '/lib/notice.php');

/** 見直す前の言い方（正規表現 => 理由）。「システム管理者・補佐」の「管理者・補佐」は見ない */
const OLD_WORDING_PATTERNS = [
    '/部下/u'                       => '「部下」は「一般職」にした',
    '/（管理者）/u'                  => '「（管理者）」は「（システム管理者）」にした',
    '/(?<!システム)管理者・補佐/u'    => '「管理者・補佐」は「システム管理者・補佐」にした',
    '/班/u'                         => '「班」は「ライン」にした',
    '/組織の改訂/u'                  => '「組織の改訂」は「組織の改編」にした',
    '/項目システム管理者/u'          => '置き換えの行き過ぎ（「項目管理者」が正しい）',
    '/システムシステム/u'            => '置き換えの行き過ぎ',
];

$problemList = [];
foreach (FAQ_STANDARD_ENTRIES as $entryIndex => [$categoryCode, $questionText, $answerText]) {
    foreach (['質問' => $questionText, '答え' => $answerText] as $partName => $partText) {
        foreach (OLD_WORDING_PATTERNS as $pattern => $reasonText) {
            if (preg_match($pattern, $partText, $matchList, PREG_OFFSET_CAPTURE) === 1) {
                $problemList[] = sprintf('NG  %d 件目（%s）の%s: %s（%s）', $entryIndex + 1, $questionText, $partName, $reasonText,
                    mb_substr($partText, max(0, mb_strlen(substr($partText, 0, $matchList[0][1])) - 10), 30));
            }
        }
    }
}
// 分野の名前（画面の分野のボタン・メンテナンス用の CSV の分野の列）
$categoryBlock = explode('];', explode('const FAQ_CATEGORY_LABELS = [', $noticeSource)[1] ?? '')[0];
foreach (['確認者・課長' => '分野「報告の確認・チェック」は「（課長・課長代理・主任）」にした', "'システムの管理（管理者・補佐）'" => '分野「システムの管理」は「（システム管理者・補佐）」にした'] as $oldLabelText => $reasonText) {
    if (strpos($categoryBlock, $oldLabelText) !== false) {
        $problemList[] = 'NG  分野の名前: ' . $reasonText;
    }
}
// 質問の文を変えたら、対応表に前の文がある（無いと、すでに動いているサイトの標準の取り込みで二重になる）
$standardQuestionSet = array_fill_keys(array_column(FAQ_STANDARD_ENTRIES, 1), true);
foreach (FAQ_STANDARD_RENAMED_QUESTIONS as $oldQuestion => $newQuestion) {
    $finalQuestion = $newQuestion;
    for ($step = 0; $step < 10 && isset(FAQ_STANDARD_RENAMED_QUESTIONS[$finalQuestion]); $step++) {
        $finalQuestion = FAQ_STANDARD_RENAMED_QUESTIONS[$finalQuestion];
    }
    if (!isset($standardQuestionSet[$finalQuestion])) {
        $problemList[] = 'NG  対応表の「' . $oldQuestion . '」の行き先が、標準の質問にない（' . $finalQuestion . '）';
    }
    if (isset($standardQuestionSet[$oldQuestion])) {
        $problemList[] = 'NG  対応表の前の文「' . $oldQuestion . '」が、標準の質問にまだある（二重になる）';
    }
}

foreach ($problemList as $problemText) {
    echo $problemText, PHP_EOL;
}
if ($problemList) {
    echo 'NG  標準のよくある質問の文言（', count($problemList), ' か所）', PHP_EOL;
    exit(1);
}
echo 'OK  標準のよくある質問の文言（', count(FAQ_STANDARD_ENTRIES), ' 件・対応表 ', count(FAQ_STANDARD_RENAMED_QUESTIONS), ' 件。見直す前の言い方は残っていない）', PHP_EOL;
