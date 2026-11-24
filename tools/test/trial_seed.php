<?php

declare(strict_types=1);

// ====================================================================
// ローカル検証環境に「お試し」の状態を足す（本番では使わない）
//   php tools/test/dev_seed.php --yes --with-faq   （先にテストデータを作る）
//   php tools/test/trial_seed.php --yes
// デモ用のサイト（report-system_onion）のお試しと同じ形を作る:
//   - 課「お試し課1」。課員は小野 雄太（y.ono。役職は一般のまま、課の「課長」に設定）だけ
//   - 臨時の調査「AI利用状況」（依頼元: 人財管理部。公開中。集計のみ）と、y.ono の回答 1 件
// config.local.php に 'allow_dev_seed' => true があるときだけ動く。y.ono がもういれば何もしない。
// パスワードは dev_seed.php と同じ決まり（pass-<ID>-1234）。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../../app/bootstrap.php';

$localConfigPath = APP_DIR . '/config/config.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
if (empty($localConfig['allow_dev_seed']) || cfg('https') || cfg('base_path') !== '') {
    fwrite(STDERR, "ローカル検証環境でだけ使えます（config.local.php に allow_dev_seed => true）\n");
    exit(1);
}
if (!isset(getopt('', ['yes'])['yes'])) {
    fwrite(STDERR, "お試しの課・利用者・調査を足します。--yes を付けて実行してください\n");
    exit(1);
}
if (auth_find_user('y.ono') !== null) {
    fwrite(STDERR, "お試しの状態はもう入っています（作り直すときは、先に dev_seed.php --yes でテストデータを作り直す）\n");
    exit(1);
}

const TRIAL_DEPARTMENT_NAME = 'お試し課1';
const TRIAL_LOGIN_ID = 'y.ono';
const TRIAL_DISPLAY_NAME = '小野 雄太';

/** 選択肢の一覧を、調査の質問の形（code・label・active）にする */
function trial_option_list(array $labelList): array
{
    $optionList = [];
    foreach (array_values($labelList) as $optionIndex => $label) {
        $optionList[] = ['code' => 'o' . ($optionIndex + 1), 'label' => $label, 'active' => true];
    }
    return $optionList;
}

$nowText = now();
$trialPassword = 'pass-' . TRIAL_LOGIN_ID . '-1234';
$trialTotpSecret = base32_encode(substr(hash('sha1', 'dev-totp-' . TRIAL_LOGIN_ID, true), 0, 20));

// 質問: [文, 型, 選択肢, 回答（選択肢の番号。複数回答は番号の配列）]
$questionSpecList = [
    ['AIを業務で使っていますか？', 'radio', ['毎日使う', '時々使う', '使わない'], 1],
    ['AIは何を使っていますか？', 'multi_check', ['GPT', 'Gemini', 'Claude(code含む)', 'その他'], [1, 2, 3]],
    ['AI使ってみてどうですか。業務の仕方変わりましたか？', 'radio', ['激変', 'だいぶ変わった', '少し変わった', '何も変わっていない', 'むしろ不便になった'], 1],
];

$trialIds = tx(function () use ($nowText, $trialPassword, $trialTotpSecret, $questionSpecList): array {
    db_exec('INSERT INTO departments (name, sort_order, created_at, updated_at) VALUES (?, 30, ?, ?)', [TRIAL_DEPARTMENT_NAME, $nowText, $nowText]);
    $departmentId = (int) db()->lastInsertId();

    // 役職は「一般」のまま、課の「課長」（最終確認者）にする（お試しで課長の画面も試すため。権限は役職ではなく課長の設定で決まる）
    db_exec(
        'INSERT INTO users (username, password_hash, role_title, is_admin, created_at, login_id, display_name, department_id, totp_enabled_at, password_changed_at, updated_at)
         VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)',
        [TRIAL_LOGIN_ID, password_hash($trialPassword, PASSWORD_DEFAULT), '一般', $nowText, TRIAL_LOGIN_ID, TRIAL_DISPLAY_NAME, $departmentId, $nowText, $nowText, $nowText]
    );
    $userId = (int) db()->lastInsertId();
    db_exec('UPDATE users SET totp_secret_enc = ? WHERE id = ?', [enc($trialTotpSecret, 'totp:' . $userId), $userId]);
    db_exec('INSERT INTO department_reviewers (department_id, user_id, is_final, sort_order, team_id, does_check) VALUES (?, ?, 1, 0, NULL, 1)', [$departmentId, $userId]);
    $backupCodePlain = substr(str_replace(['i', 'l', 'o'], '', strtolower(base32_encode(hash('sha1', 'dev-backup-' . TRIAL_LOGIN_ID, true)))), 0, 10);
    db_exec('INSERT INTO user_backup_codes (user_id, code_hash, created_at) VALUES (?, ?, ?)', [$userId, password_hash($backupCodePlain, PASSWORD_DEFAULT), $nowText]);

    db_exec(
        "INSERT INTO adhoc_requests (department_id, title, requester, purpose, result_visibility, target_type, status, created_by, opened_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'summary', 'all', 'open', ?, ?, ?, ?)",
        [$departmentId, 'AI利用状況', '人財管理部', 'AI の業務での利用状況を知るため', $userId, $nowText, $nowText, $nowText]
    );
    $requestId = (int) db()->lastInsertId();
    db_exec('INSERT INTO adhoc_request_targets (request_id, user_id) VALUES (?, ?)', [$requestId, $userId]);
    $sortOrder = 10;
    foreach ($questionSpecList as [$questionLabel, $questionType, $optionLabelList]) {
        db_exec(
            "INSERT INTO adhoc_request_fields (request_id, label, field_type, confidentiality, is_required, help_text, settings_json, options_json, sort_order)
             VALUES (?, ?, ?, 'internal', 1, '', '{}', ?, ?)",
            [$requestId, $questionLabel, $questionType, json_encode(trial_option_list($optionLabelList), JSON_UNESCAPED_UNICODE), $sortOrder]
        );
        $sortOrder += 10;
    }

    // y.ono の回答（回答の画面と同じく、暗号化する型は暗号化して入れる）
    db_exec('INSERT INTO adhoc_responses (request_id, user_id, submitted_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$requestId, $userId, $nowText, $nowText, $nowText]);
    $responseId = (int) db()->lastInsertId();
    foreach (survey_fields($requestId) as $questionIndex => $question) {
        $answerNumber = $questionSpecList[$questionIndex][3];
        $valueText = is_array($answerNumber)
            ? json_encode(array_map(function (int $number): string { return 'o' . $number; }, $answerNumber))
            : 'o' . $answerNumber;
        $isEncrypted = form_is_encrypted($question);
        db_exec(
            'INSERT INTO adhoc_response_values (response_id, request_field_id, value_text, is_encrypted, has_warning) VALUES (?, ?, ?, ?, 0)',
            [$responseId, $question['id'], $isEncrypted ? enc($valueText, survey_aad($responseId, $question['id'])) : $valueText, $isEncrypted ? 1 : 0]
        );
    }
    return ['department_id' => $departmentId, 'user_id' => $userId, 'request_id' => $requestId];
});

echo 'お試しの状態を足しました: 課「' . TRIAL_DEPARTMENT_NAME . '」(id=' . $trialIds['department_id'] . ')・'
    . TRIAL_DISPLAY_NAME . '（' . TRIAL_LOGIN_ID . '。パスワード ' . $trialPassword . '）・臨時の調査「AI利用状況」(id=' . $trialIds['request_id'] . ")\n";
