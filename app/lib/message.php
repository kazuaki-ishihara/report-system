<?php

declare(strict_types=1);

// ====================================================================
// メッセージ（画面に出す文言）の管理（詳細設計書 G17_messages.md）
// 文言は既定の文（コードに書いた文）を鍵にし、課長が画面で変えたものだけを ui_messages に持つ。
// 差し込みは {name} の形で書き、msg('…{count}…', ['count' => 3]) で値を入れる。
// G-01・G-02（ログイン・パスワードの再設定）の文言は msg() を通さない（固定）。
// ====================================================================

/**
 * 画面に出す文言を返す。課長が変えていれば変えた文言、なければ既定の文言。差し込みの値を入れる。
 *
 * @param string $defaultText          コードに書いた既定の文言（鍵を兼ねる）
 * @param array<string, scalar> $placeholderValues 差し込みの名前 => 入れる値（例: ['count' => 3]）
 * @return string 画面に出す文言
 */
function msg(string $defaultText, array $placeholderValues = []): string
{
    $overrideTextByKey = msg_overrides();
    $displayText = $overrideTextByKey[$defaultText] ?? $defaultText;
    if ($placeholderValues) {
        $replacementByPlaceholder = [];
        foreach ($placeholderValues as $placeholderName => $value) {
            $replacementByPlaceholder['{' . $placeholderName . '}'] = (string) $value;
        }
        $displayText = strtr($displayText, $replacementByPlaceholder);
    }
    return $displayText;
}

/**
 * 課長が変えた文言の一覧。1 回の要求で 1 度だけ DB から読む。DB に届かないときは空（既定の文言を使う）。
 *
 * @param bool $forceReload true なら読み直す（保存した直後など）
 * @return array<string, string> 既定の文言 => 変えた文言
 */
function msg_overrides(bool $forceReload = false): array
{
    if (!$forceReload && isset($GLOBALS['MSG_OVERRIDE_TEXT_BY_KEY'])) {
        return $GLOBALS['MSG_OVERRIDE_TEXT_BY_KEY'];
    }
    $overrideTextByKey = [];
    try {
        foreach (db_all('SELECT msg_key, body FROM ui_messages') as $overrideRow) {
            $overrideTextByKey[(string) $overrideRow['msg_key']] = (string) $overrideRow['body'];
        }
    } catch (Throwable $dbError) {
        $overrideTextByKey = [];
    }
    $GLOBALS['MSG_OVERRIDE_TEXT_BY_KEY'] = $overrideTextByKey;
    return $overrideTextByKey;
}

/**
 * 変えられる文言の一覧（tools/test/msg_catalog.py が作る messages_catalog.php）。
 *
 * @return array<string, array{screens: string[]}> 既定の文言 => 使っている画面（例: ['G-04 報告の作成・編集']）
 */
function msg_catalog(): array
{
    static $catalogByDefaultText = null;
    if ($catalogByDefaultText === null) {
        $catalogByDefaultText = require APP_DIR . '/lib/messages_catalog.php';
    }
    return $catalogByDefaultText;
}

/**
 * 文言の中の差し込みの名前を、並べ替えて返す。
 *
 * @return string[] 例: '{count} 件を {name} に' → ['count', 'name']
 */
function msg_placeholders(string $text): array
{
    preg_match_all('/\{([a-z_]+)\}/', $text, $matchGroups);
    $placeholderNames = array_values(array_unique($matchGroups[1]));
    sort($placeholderNames);
    return $placeholderNames;
}

/**
 * メッセージを変えられるか。管理担当の課の課長（is_final = 1 の確認者）だけ。管理者でも課長でなければ不可。
 */
function msg_can_edit(array $user): bool
{
    // システムの管理担当の課（組織・ユーザーの画面で指定。システム 2 部システム 2 課）の課長だけ（K-006）
    // システム管理者補佐は代行として変えられる（K-043）
    if (is_admin_deputy($user)) {
        return true;
    }
    $managementDepartmentId = management_department_id();
    return $managementDepartmentId !== null && db_val(
        'SELECT 1 FROM department_reviewers r JOIN departments d ON d.id = r.department_id
          WHERE r.user_id = ? AND r.is_final = 1 AND d.is_active = 1 AND d.id = ? LIMIT 1',
        [(int) $user['id'], $managementDepartmentId]
    ) !== null;
}

/**
 * 変える文言を確かめる。
 *
 * @param string $defaultText 既定の文言（鍵）
 * @param string $newText     課長が入れた文言
 * @return string|null 誤りの文言。正しければ null
 */
function msg_validate(string $defaultText, string $newText): ?string
{
    if ($newText === '') {
        return msg('文言を入れてください（元に戻すときは「既定に戻す」）');
    }
    if (str_len($newText) > 500) {
        return msg('文言は 500 文字以内にしてください');
    }
    if (has_control_chars($newText, true)) {
        return msg('文言に使えない文字が入っています');
    }
    $requiredPlaceholderNames = msg_placeholders($defaultText);
    if ($requiredPlaceholderNames !== msg_placeholders($newText)) {
        $placeholderListText = $requiredPlaceholderNames
            ? implode('・', array_map(function (string $placeholderName): string { return '{' . $placeholderName . '}'; }, $requiredPlaceholderNames))
            : 'なし';
        return msg('差し込み（{names}）は、そのまま残してください。ほかの差し込みは足せません', ['names' => $placeholderListText]);
    }
    return null;
}

/**
 * JavaScript で使う文言（layout.php が body の data-msgs で渡す）。
 *
 * @return array<string, string> 既定の文言 => 画面に出す文言
 */
function msg_js_map(): array
{
    $displayTextByDefaultText = [];
    foreach (JS_MESSAGES as $defaultText) {
        $displayTextByDefaultText[$defaultText] = msg($defaultText);
    }
    return $displayTextByDefaultText;
}

/** JavaScript（public/js/app.js の M()）で使う文言の既定の文 */
const JS_MESSAGES = [
    '自動保存しました {time}',
    '自動保存できませんでした（入力を確かめてください）',
    '通信が切れたため自動保存できませんでした。入力は残っています',
    '同じ月の報告が別の画面で作られたため、自動保存を止めました。「下書き保存」を押してください',
    '客先名や個人名の注意が出ている欄が {count} か所あります。このまま送りますか？',
    '{text}。よろしいですか？',
    '表示できません',
    '通信エラー',
    '「{code}」の内容を写しました。追加する案件を選んでください（保存するまで反映されません）',
    '（案件なし）',
];
