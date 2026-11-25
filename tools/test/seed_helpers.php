<?php

declare(strict_types=1);

// ====================================================================
// ローカル検証環境のテストデータを作る道具の共通の部品（trial_seed.php・org_seed.php が使う。本番では使わない）
// 利用者・臨時の調査・回答を、画面と同じ形（暗号化を含む）で DB に入れる。
// パスワード・2 段階認証の秘密の値は dev_seed.php と同じ決まり（pass-<ID>-1234、dev-totp-<ID>）。
// ====================================================================

/** ローカル検証環境でだけ動かす（config.local.php に allow_dev_seed => true、http、base_path が空） */
function seed_require_local_environment(): void
{
    $localConfigPath = APP_DIR . '/config/config.local.php';
    $localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
    if (empty($localConfig['allow_dev_seed']) || cfg('https') || cfg('base_path') !== '') {
        fwrite(STDERR, "ローカル検証環境でだけ使えます（config.local.php に allow_dev_seed => true）\n");
        exit(1);
    }
}

/** テスト用のパスワード（dev_seed.php と同じ決まり） */
function seed_password(string $loginId): string
{
    return 'pass-' . $loginId . '-1234';
}

/**
 * 利用者を作る。2 段階認証は設定済み、予備のコードも 1 つ持たせる（dev_seed.php と同じ）。
 * $reviewerRole: null（確認者でない）・'final'（課長）・'reviewer'（課長代理・主任）
 */
function seed_create_user(string $loginId, string $displayName, string $roleTitle, ?int $departmentId, ?int $teamId = null, ?string $reviewerRole = null, ?int $reviewerTeamId = null): int
{
    $nowText = now();
    db_exec(
        'INSERT INTO users (username, password_hash, role_title, is_admin, created_at, login_id, display_name, department_id, team_id, totp_enabled_at, password_changed_at, updated_at)
         VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$loginId, password_hash(seed_password($loginId), PASSWORD_DEFAULT), $roleTitle, $nowText, $loginId, $displayName, $departmentId, $teamId, $nowText, $nowText, $nowText]
    );
    $userId = (int) db()->lastInsertId();
    $totpSecret = base32_encode(substr(hash('sha1', 'dev-totp-' . $loginId, true), 0, 20));
    db_exec('UPDATE users SET totp_secret_enc = ? WHERE id = ?', [enc($totpSecret, 'totp:' . $userId), $userId]);
    $backupCodePlain = substr(str_replace(['i', 'l', 'o'], '', strtolower(base32_encode(hash('sha1', 'dev-backup-' . $loginId, true)))), 0, 10);
    db_exec('INSERT INTO user_backup_codes (user_id, code_hash, created_at) VALUES (?, ?, ?)', [$userId, password_hash($backupCodePlain, PASSWORD_DEFAULT), $nowText]);
    if ($reviewerRole !== null && $departmentId !== null) {
        $isFinal = $reviewerRole === 'final';
        db_exec(
            'INSERT INTO department_reviewers (department_id, user_id, is_final, sort_order, team_id, does_check) VALUES (?, ?, ?, ?, ?, ?)',
            [$departmentId, $userId, $isFinal ? 1 : 0, $isFinal ? 0 : $userId * 10, $isFinal ? null : $reviewerTeamId, $isFinal || $roleTitle === '課長代理' ? 1 : 0]
        );
    }
    return $userId;
}

/** 選択肢の一覧を、調査の質問の形（code・label・active）にする。コードは o1, o2, … */
function seed_option_list(array $labelList): array
{
    $optionList = [];
    foreach (array_values($labelList) as $optionIndex => $label) {
        $optionList[] = ['code' => 'o' . ($optionIndex + 1), 'label' => $label, 'active' => true];
    }
    return $optionList;
}

/**
 * 公開中の臨時の調査を作る。$departmentId が null なら全課共通（K-066）。対象は $targetUserIdList。
 * $questionSpecList: [[文, 型, 選択肢の表示名の配列], …]
 * @return int 依頼の ID
 */
function seed_create_open_survey(?int $departmentId, string $title, string $requester, string $purpose, int $createdByUserId, array $questionSpecList, array $targetUserIdList): int
{
    $nowText = now();
    db_exec(
        "INSERT INTO adhoc_requests (department_id, title, requester, purpose, result_visibility, target_type, status, due_at, created_by, opened_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'summary', 'all', 'open', ?, ?, ?, ?, ?)",
        [$departmentId, $title, $requester, $purpose, date('Y-m-d', strtotime('+14 days')), $createdByUserId, $nowText, $nowText, $nowText]
    );
    $requestId = (int) db()->lastInsertId();
    $sortOrder = 10;
    foreach ($questionSpecList as [$questionLabel, $questionType, $optionLabelList]) {
        db_exec(
            "INSERT INTO adhoc_request_fields (request_id, label, field_type, confidentiality, is_required, help_text, settings_json, options_json, sort_order)
             VALUES (?, ?, ?, 'internal', ?, '', '{}', ?, ?)",
            [$requestId, $questionLabel, $questionType, $questionType === 'radio' ? 1 : 0, json_encode(seed_option_list($optionLabelList), JSON_UNESCAPED_UNICODE), $sortOrder]
        );
        $sortOrder += 10;
    }
    foreach (array_unique($targetUserIdList) as $targetUserId) {
        db_exec('INSERT INTO adhoc_request_targets (request_id, user_id) VALUES (?, ?)', [$requestId, $targetUserId]);
    }
    return $requestId;
}

/**
 * 回答を入れる（回答の画面と同じく、暗号化する型は暗号化する）。
 * $answerByQuestionIndex: 質問の順番 => 選択肢の番号（1 から）。複数回答は番号の配列（空の配列は未回答）。checkbox は true/false
 */
function seed_answer_survey(int $requestId, int $userId, array $answerByQuestionIndex): void
{
    $nowText = now();
    db_exec('INSERT INTO adhoc_responses (request_id, user_id, submitted_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$requestId, $userId, $nowText, $nowText, $nowText]);
    $responseId = (int) db()->lastInsertId();
    foreach (survey_fields($requestId) as $questionIndex => $question) {
        $answer = $answerByQuestionIndex[$questionIndex] ?? null;
        if ($answer === null || $answer === [] || $answer === false) {
            continue;
        }
        if ($question['type'] === 'checkbox') {
            $valueText = '1';
        } elseif (is_array($answer)) {
            $valueText = (string) json_encode(array_map(function (int $optionNumber): string { return 'o' . $optionNumber; }, array_values($answer)));
        } else {
            $valueText = 'o' . (int) $answer;
        }
        $isEncrypted = form_is_encrypted($question);
        db_exec(
            'INSERT INTO adhoc_response_values (response_id, request_field_id, value_text, is_encrypted, has_warning) VALUES (?, ?, ?, ?, 0)',
            [$responseId, $question['id'], $isEncrypted ? enc($valueText, survey_aad($responseId, $question['id'])) : $valueText, $isEncrypted ? 1 : 0]
        );
    }
}

/** 重みで 1 つ選ぶ（番号は 1 から）。乱数は mt_rand（呼ぶ側が mt_srand で種を決めて、毎回同じ結果にする） */
function seed_weighted_pick(array $weightList): int
{
    $total = array_sum($weightList);
    $draw = mt_rand(1, max(1, $total));
    foreach (array_values($weightList) as $index => $weight) {
        $draw -= $weight;
        if ($draw <= 0) {
            return $index + 1;
        }
    }
    return count($weightList);
}

/** 「AI 利用状況」の質問（お試し課の調査と全課共通の調査で同じもの） */
function seed_ai_question_spec_list(): array
{
    return [
        ['AIを業務で使っていますか？', 'radio', ['毎日使う', '時々使う', '使わない']],
        ['AIは何を使っていますか？', 'multi_check', ['GPT', 'Gemini', 'Claude(code含む)', 'その他']],
        ['AI使ってみてどうですか。業務の仕方変わりましたか？', 'radio', ['激変', 'だいぶ変わった', '少し変わった', '何も変わっていない', 'むしろ不便になった']],
    ];
}

/**
 * 「AI 利用状況」の回答を 1 人分作る。$usageWeightList は「毎日・時々・使わない」の重み（課ごとの傾向）。
 * 使い方と変わり方は、利用の頻度に合わせて傾ける（毎日使う人ほど「変わった」が多い）。使わない人は道具を選ばない
 */
function seed_ai_answer(array $usageWeightList): array
{
    $usageNumber = seed_weighted_pick($usageWeightList);
    $toolNumberList = [];
    if ($usageNumber !== 3) {
        foreach ([1 => 60, 2 => 35, 3 => 45, 4 => 10] as $toolNumber => $percent) {   // GPT・Gemini・Claude・その他を選ぶ割合（%）
            if (mt_rand(1, 100) <= $percent) {
                $toolNumberList[] = $toolNumber;
            }
        }
        if (!$toolNumberList) {
            $toolNumberList[] = 1;
        }
    }
    $changeWeightListByUsage = [1 => [3, 4, 2, 1, 0], 2 => [0, 2, 5, 3, 1], 3 => [0, 0, 1, 7, 2]];
    return [$usageNumber, $toolNumberList, seed_weighted_pick($changeWeightListByUsage[$usageNumber])];
}
