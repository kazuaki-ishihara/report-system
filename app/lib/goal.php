<?php

declare(strict_types=1);

// ====================================================================
// G-27 目標管理（詳細設計書 G27_goals.md。K-064）
// 目標管理評価シート（様式は app/templates/goal_*.xlsx）を Web で書いて評価し、様式のとおりの xlsx で受け取る。月次報告とは別の機能。
// - 見られるのは、本人・その課の課長・その課の課長代理（課長代理は一般・主任のシートだけ）。管理者・補佐は見られない。
// - 本人は目標・報告・本人評価を、課長は配点（ストレッチ／適正／物足りない）・上長評価・総合評価を入れる。課長代理は見て、コメントする。
// - コメント・返信と面談の記録は、本人・課長・課長代理が書ける。監査のため、コメントと面談の記録は直さない・消さない。
// - 中身は暗号化する（シートは goal_sheets:<ID>、コメントは goal_sheet_comments:<シート ID>、面談は goal_sheet_meetings:<シート ID>）。
// ====================================================================

require_once APP_DIR . '/lib/goal_templates.php';

/** 評価（作業用のシートの評点の表。評価 => 係数） */
const GOAL_GRADE_FACTORS = ['A' => 1.2, 'B' => 1.0, 'C1' => 0.7, 'C2' => 0.6, 'C3' => 0.5, 'D' => 0.3, 'E' => 0.1];
/** 設定目標の配点（職位に対して） */
const GOAL_DIFFICULTY_POINTS = ['ストレッチ' => 120, '適正' => 100, '物足りない' => 60];
/** コンピテンシーの評点（0〜5） */
const GOAL_COMPETENCY_SCORES = [5 => '満足できる結果', 4 => '期待に応えられている', 3 => '可もなく不可もなく及第点', 2 => '一歩足りない', 1 => 'まだまだ', 0 => '評価に値せず'];
/** 本人が書く文の欄 => [表示名, 様式の行数, 1 行の字数のめやす, 様式の列]（様式の枠に収まる長さにする） */
const GOAL_TEXT_FIELDS = [
    'goal'         => ['1 年後のゴール（具体的に何がどうなった状態）', 7, 30, 'A'],
    'progress'     => ['中間時点での進捗（具体的にどうなっている状態）', 7, 25, 'G'],
    'mid_report'   => ['中間報告（プロセスと進捗を具体的に記載）', 9, 30, 'A'],
    'mid_plan'     => ['後半に向けての課題と対策', 9, 25, 'G'],
    'final_report' => ['最終報告（プロセスとゴール）', 9, 30, 'A'],
    'final_review' => ['振り返り', 9, 25, 'G'],
];
/** 文の欄の、目標の見出しの行からの位置（様式: 見出しの 2 行下から 7 行、10 行下から 9 行、20 行下から 9 行） */
const GOAL_TEXT_ROW_OFFSETS = ['goal' => 2, 'progress' => 2, 'mid_report' => 10, 'mid_plan' => 10, 'final_report' => 20, 'final_review' => 20];
/** 業績目標（計画・実績。千円）の欄がある種類（様式の 8 行目。達成率と評点は様式の式） */
const GOAL_BUSINESS_TARGET_KINDS = ['manager', 'sales'];
/** 目標のテーマの長さ（様式の 1 行） */
const GOAL_THEME_MAX = 30;
const GOAL_COUNT = 2;

/** 年度（10 月 1 日〜翌 9 月 30 日。2026 年度は 2026-10-01〜2027-09-30） */
function goal_fiscal_year_of(string $dateText): int
{
    $yearNumber = (int) substr($dateText, 0, 4);
    return (int) substr($dateText, 5, 2) >= 10 ? $yearNumber : $yearNumber - 1;
}

/** 作れる年度（今の年度と次の年度） */
function goal_creatable_years(): array
{
    $currentYear = goal_fiscal_year_of(date('Y-m-d'));
    return [$currentYear, $currentYear + 1];
}

/**
 * 役職で選べる様式の種類（1 つ目が既定）。一般は一般だけ、主任・課長代理はリーダー・課長代理だけで、選び直せない。
 * 課長・部長は、課長以上・営業・間接部門から選ぶ（営業・間接部門の様式は、課長以上の様式のファイルにだけあるため）
 *
 * @return string[]
 */
function goal_selectable_kinds(array $user): array
{
    $roleTitle = (string) ($user['role_title'] ?? '');
    if ($roleTitle === '課長' || $roleTitle === '部長') {
        return ['manager', 'sales', 'indirect'];
    }
    if ($roleTitle === '課長代理' || $roleTitle === '主任') {
        return ['leader'];
    }
    return ['general'];
}

/** 役職から決まる既定の様式の種類 */
function goal_default_kind(array $user): string
{
    return goal_selectable_kinds($user)[0];
}

/** 空の目標 1 つ */
function goal_empty_goal(): array
{
    return ['theme' => '', 'goal' => '', 'progress' => '', 'mid_report' => '', 'mid_plan' => '', 'mid_self' => '',
            'final_report' => '', 'final_review' => '', 'final_self' => '', 'difficulty' => '', 'mid_boss' => '', 'final_boss' => ''];
}

/** 足りないキーを補い、型をそろえる */
function goal_normalize(array $sheetData, string $kindCode): array
{
    $itemCount = count(GOAL_TEMPLATE_KINDS[$kindCode]['items']);
    $goalList = [];
    for ($goalIndex = 0; $goalIndex < GOAL_COUNT; $goalIndex++) {
        $storedGoal = isset($sheetData['goals'][$goalIndex]) && is_array($sheetData['goals'][$goalIndex]) ? $sheetData['goals'][$goalIndex] : [];
        $normalizedGoal = goal_empty_goal();
        foreach (array_keys($normalizedGoal) as $goalKey) {
            $normalizedGoal[$goalKey] = isset($storedGoal[$goalKey]) ? (string) $storedGoal[$goalKey] : '';
        }
        $goalList[] = $normalizedGoal;
    }
    $scoreListByOwner = [];
    foreach (['competency_self', 'competency_boss'] as $scoreKey) {
        $scoreList = [];
        for ($itemIndex = 0; $itemIndex < $itemCount; $itemIndex++) {
            $storedScore = $sheetData[$scoreKey][$itemIndex] ?? '';
            $scoreList[] = (is_int($storedScore) || (is_string($storedScore) && preg_match('/\A[0-5]\z/', $storedScore) === 1)) ? (string) $storedScore : '';
        }
        $scoreListByOwner[$scoreKey] = $scoreList;
    }
    return [
        'department_name' => (string) ($sheetData['department_name'] ?? ''),
        'full_name'       => (string) ($sheetData['full_name'] ?? ''),
        'position'        => (string) ($sheetData['position'] ?? ''),
        'business_plan'   => (string) ($sheetData['business_plan'] ?? ''),
        'business_actual' => (string) ($sheetData['business_actual'] ?? ''),
        'goals'           => $goalList,
        'competency_self' => $scoreListByOwner['competency_self'],
        'competency_boss' => $scoreListByOwner['competency_boss'],
        'overall_boss'    => (string) ($sheetData['overall_boss'] ?? ''),
    ];
}

/**
 * シートを読む（無ければ null）
 *
 * @return array{id: int, user_id: int, fiscal_year: int, kind: string, created_at: string, updated_at: string, data: array<string, mixed>, unreadable: bool}|null
 */
function goal_sheet(int $sheetId): ?array
{
    $sheetRow = db_one('SELECT * FROM goal_sheets WHERE id = ?', [$sheetId]);
    if ($sheetRow === null) {
        return null;
    }
    $kindCode = isset(GOAL_TEMPLATE_KINDS[$sheetRow['kind']]) ? (string) $sheetRow['kind'] : 'general';
    $plainText = dec((string) $sheetRow['data_enc'], 'goal_sheets:' . (int) $sheetRow['id']);
    $decodedData = $plainText === null ? null : json_decode($plainText, true);
    return [
        'id'          => (int) $sheetRow['id'],
        'user_id'     => (int) $sheetRow['user_id'],
        'fiscal_year' => (int) $sheetRow['fiscal_year'],
        'kind'        => $kindCode,
        'created_at'  => (string) $sheetRow['created_at'],
        'updated_at'  => (string) $sheetRow['updated_at'],
        'data'        => goal_normalize(is_array($decodedData) ? $decodedData : [], $kindCode),
        'unreadable'  => !is_array($decodedData),
    ];
}

/** 新しいシートの中身（所属・氏名・職位は利用者の情報から） */
function goal_new_sheet_data(array $owner, string $kindCode): array
{
    $departmentName = !empty($owner['department_id']) ? (string) db_val('SELECT name FROM departments WHERE id = ?', [(int) $owner['department_id']]) : '';
    return goal_normalize(['department_name' => $departmentName, 'full_name' => (string) ($owner['display_name'] ?? ''), 'position' => (string) ($owner['role_title'] ?? '')], $kindCode);
}

/**
 * 見る人の立場: owner（本人）・boss（課長。上長評価を入れる）・deputy（課長代理。見てコメントする）・null（見られない）
 * 課は、シートの持ち主の今の所属の課。課長代理が見られるのは、一般・主任の人のシートだけ（課長代理・課長のシートは見ない）。
 */
function goal_viewer_role(array $viewer, int $ownerUserId): ?string
{
    $viewerUserId = (int) $viewer['id'];
    if ($viewerUserId === $ownerUserId) {
        return 'owner';
    }
    $owner = db_one('SELECT id, department_id, role_title, is_active FROM users WHERE id = ?', [$ownerUserId]);
    if ($owner === null || empty($owner['department_id'])) {
        return null;
    }
    $departmentId = (int) $owner['department_id'];
    if (is_final_reviewer_of($ownerUserId, $departmentId)) {
        return null;   // 課長のシートは、本人だけ（課長の上長はこのシステムにいない）
    }
    if (is_final_reviewer_of($viewerUserId, $departmentId)) {
        return 'boss';
    }
    if ((string) ($viewer['role_title'] ?? '') === '課長代理' && (int) ($viewer['department_id'] ?? 0) === $departmentId
        && in_array((string) $owner['role_title'], ['一般', '主任'], true)) {
        return 'deputy';
    }
    return null;
}

/**
 * 課長・課長代理として見られる人（課ごと）。その年度のシートの有無と、評価の進み具合も付ける
 *
 * @return array<int, array{department_id: int, department_name: string, is_boss: bool, members: array<int, array<string, mixed>>}>
 */
function goal_member_overview(array $viewer, int $fiscalYear): array
{
    $departmentIdList = [];
    foreach (db_all('SELECT department_id FROM department_reviewers WHERE user_id = ? AND is_final = 1', [(int) $viewer['id']]) as $reviewerRow) {
        $departmentIdList[] = (int) $reviewerRow['department_id'];
    }
    if ((string) ($viewer['role_title'] ?? '') === '課長代理' && !empty($viewer['department_id'])) {
        $departmentIdList[] = (int) $viewer['department_id'];
    }
    $overviewList = [];
    foreach (array_unique($departmentIdList) as $departmentId) {
        $memberList = [];
        foreach (db_all('SELECT id, display_name, role_title FROM users WHERE department_id = ? AND is_active = 1 AND id <> ? ORDER BY id', [$departmentId, (int) $viewer['id']]) as $memberRow) {
            if (goal_viewer_role($viewer, (int) $memberRow['id']) === null) {
                continue;
            }
            $sheetRow = db_one('SELECT id FROM goal_sheets WHERE user_id = ? AND fiscal_year = ?', [(int) $memberRow['id'], $fiscalYear]);
            $memberSheet = $sheetRow !== null ? goal_sheet((int) $sheetRow['id']) : null;
            $memberList[] = [
                'user_id'  => (int) $memberRow['id'],
                'name'     => (string) $memberRow['display_name'],
                'role'     => (string) $memberRow['role_title'],
                'sheet'    => $memberSheet,
                'progress' => $memberSheet !== null ? goal_progress($memberSheet) : null,
            ];
        }
        $overviewList[] = [
            'department_id'   => (int) $departmentId,
            'department_name' => (string) db_val('SELECT name FROM departments WHERE id = ?', [$departmentId]),
            'is_boss'         => is_final_reviewer_of((int) $viewer['id'], (int) $departmentId),   // 課長なら、一覧から受け取れる
            'members'         => $memberList,
        ];
    }
    return $overviewList;
}

/** 進み具合（一覧に出す）: 本人の中間・最終の評価、上長の中間・最終の評価が入っているか */
function goal_progress(array $sheet): array
{
    $progressFlags = ['mid_self' => true, 'mid_boss' => true, 'final_self' => true, 'final_boss' => true];
    foreach ($sheet['data']['goals'] as $goalData) {
        foreach (array_keys($progressFlags) as $gradeKey) {
            if ($goalData[$gradeKey] === '') {
                $progressFlags[$gradeKey] = false;
            }
        }
    }
    return $progressFlags;
}

/** 文を、様式の 1 行の字数で折り返した行の一覧（全角は 1 字、半角は 0.5 字として数える） */
function goal_wrap_lines(string $text, int $widthCharacters): array
{
    $wrappedLineList = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $sourceLine) {
        $currentLine = '';
        $currentWidth = 0.0;
        foreach (preg_split('//u', $sourceLine, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $characterWidth = strlen($character) === 1 ? 0.5 : 1.0;
            if ($currentWidth + $characterWidth > $widthCharacters) {
                $wrappedLineList[] = $currentLine;
                $currentLine = '';
                $currentWidth = 0.0;
            }
            $currentLine .= $character;
            $currentWidth += $characterWidth;
        }
        $wrappedLineList[] = $currentLine;
    }
    while ($wrappedLineList && end($wrappedLineList) === '') {
        array_pop($wrappedLineList);
    }
    return $wrappedLineList;
}

/** 本人が送った値を、今の中身に入れる（課長が入れる欄は今のまま） */
function goal_apply_owner_post(array $sheetData): array
{
    foreach (['department_name', 'full_name', 'position'] as $headerKey) {
        $sheetData[$headerKey] = post_str($headerKey);
    }
    foreach (['business_plan', 'business_actual'] as $businessKey) {
        $sheetData[$businessKey] = str_replace([',', '，'], '', function_exists('mb_convert_kana') ? mb_convert_kana(post_str($businessKey), 'n', 'UTF-8') : post_str($businessKey));
    }
    $postedGoalList = isset($_POST['goals']) && is_array($_POST['goals']) ? $_POST['goals'] : [];
    for ($goalIndex = 0; $goalIndex < GOAL_COUNT; $goalIndex++) {
        $postedGoal = isset($postedGoalList[$goalIndex]) && is_array($postedGoalList[$goalIndex]) ? $postedGoalList[$goalIndex] : [];
        foreach (array_merge(['theme', 'mid_self', 'final_self'], array_keys(GOAL_TEXT_FIELDS)) as $ownerKey) {
            $postedText = isset($postedGoal[$ownerKey]) && is_string($postedGoal[$ownerKey]) ? $postedGoal[$ownerKey] : '';
            $sheetData['goals'][$goalIndex][$ownerKey] = trim(str_replace(["\r\n", "\r"], "\n", $postedText));
        }
    }
    $postedScoreList = isset($_POST['competency_self']) && is_array($_POST['competency_self']) ? $_POST['competency_self'] : [];
    foreach (array_keys($sheetData['competency_self']) as $itemIndex) {
        $sheetData['competency_self'][$itemIndex] = isset($postedScoreList[$itemIndex]) && is_string($postedScoreList[$itemIndex]) ? trim($postedScoreList[$itemIndex]) : '';
    }
    return $sheetData;
}

/** 課長が送った値を、今の中身に入れる（本人が入れる欄は今のまま） */
function goal_apply_boss_post(array $sheetData): array
{
    $postedGoalList = isset($_POST['goals']) && is_array($_POST['goals']) ? $_POST['goals'] : [];
    for ($goalIndex = 0; $goalIndex < GOAL_COUNT; $goalIndex++) {
        $postedGoal = isset($postedGoalList[$goalIndex]) && is_array($postedGoalList[$goalIndex]) ? $postedGoalList[$goalIndex] : [];
        foreach (['difficulty', 'mid_boss', 'final_boss'] as $bossKey) {
            $sheetData['goals'][$goalIndex][$bossKey] = isset($postedGoal[$bossKey]) && is_string($postedGoal[$bossKey]) ? trim($postedGoal[$bossKey]) : '';
        }
    }
    $postedScoreList = isset($_POST['competency_boss']) && is_array($_POST['competency_boss']) ? $_POST['competency_boss'] : [];
    foreach (array_keys($sheetData['competency_boss']) as $itemIndex) {
        $sheetData['competency_boss'][$itemIndex] = isset($postedScoreList[$itemIndex]) && is_string($postedScoreList[$itemIndex]) ? trim($postedScoreList[$itemIndex]) : '';
    }
    $sheetData['overall_boss'] = post_str('overall_boss');
    return $sheetData;
}

/**
 * 中身を確かめる（誤りの文の一覧）
 *
 * @return string[]
 */
function goal_validate(array $sheetData): array
{
    $errorTextList = [];
    validate_text($sheetData['department_name'], msg('所属'), 40, false, $errorTextList);
    validate_text($sheetData['full_name'], msg('氏名'), 40, true, $errorTextList);
    validate_text($sheetData['position'], msg('職位'), 20, false, $errorTextList);
    foreach (['business_plan' => msg('業績目標の計画'), 'business_actual' => msg('業績目標の実績')] as $businessKey => $businessLabel) {
        if ($sheetData[$businessKey] !== '' && (preg_match('/\A\d{1,7}(\.\d)?\z/', $sheetData[$businessKey]) !== 1)) {
            $errorTextList[] = msg('{label}は千円の数（小数は 1 けたまで）で入れてください', ['label' => $businessLabel]);
        }
    }
    if ($sheetData['business_actual'] !== '' && ($sheetData['business_plan'] === '' || (float) $sheetData['business_plan'] <= 0)) {
        $errorTextList[] = msg('業績目標の実績を入れるときは、計画（0 より大きい数）も入れてください');
    }
    foreach ($sheetData['goals'] as $goalIndex => $goalData) {
        $goalLabel = msg('目標{number}', ['number' => $goalIndex + 1]);
        validate_text($goalData['theme'], $goalLabel . ' ' . msg('テーマ'), GOAL_THEME_MAX, false, $errorTextList);
        foreach (GOAL_TEXT_FIELDS as $fieldKey => $fieldSetting) {
            if (has_control_chars($goalData[$fieldKey], true)) {
                $errorTextList[] = msg('{goal}の「{field}」に使えない文字が含まれています', ['goal' => $goalLabel, 'field' => $fieldSetting[0]]);
                continue;
            }
            $lineCount = count(goal_wrap_lines($goalData[$fieldKey], $fieldSetting[2]));
            if ($lineCount > $fieldSetting[1]) {
                $errorTextList[] = msg('{goal}の「{field}」が長すぎます。様式の欄（{lines} 行・1 行 {width} 字ほど）に収まるようにしてください（いま {count} 行）',
                    ['goal' => $goalLabel, 'field' => $fieldSetting[0], 'lines' => $fieldSetting[1], 'width' => $fieldSetting[2], 'count' => $lineCount]);
            }
        }
        foreach (['mid_self', 'final_self', 'mid_boss', 'final_boss'] as $gradeKey) {
            if ($goalData[$gradeKey] !== '' && !isset(GOAL_GRADE_FACTORS[$goalData[$gradeKey]])) {
                $errorTextList[] = msg('{goal}の評価は A・B・C1・C2・C3・D・E から選んでください', ['goal' => $goalLabel]);
            }
        }
        if ($goalData['difficulty'] !== '' && !isset(GOAL_DIFFICULTY_POINTS[$goalData['difficulty']])) {
            $errorTextList[] = msg('{goal}の配点は、ストレッチ・適正・物足りないから選んでください', ['goal' => $goalLabel]);
        }
    }
    foreach (['competency_self', 'competency_boss'] as $scoreKey) {
        foreach ($sheetData[$scoreKey] as $scoreText) {
            if ($scoreText !== '' && preg_match('/\A[0-5]\z/', $scoreText) !== 1) {
                $errorTextList[] = msg('コンピテンシーの評点は 0〜5 から選んでください');
                break;
            }
        }
    }
    if ($sheetData['overall_boss'] !== '' && !isset(GOAL_GRADE_FACTORS[$sheetData['overall_boss']])) {
        $errorTextList[] = msg('総合評価は A・B・C1・C2・C3・D・E から選んでください');
    }
    return array_values(array_unique($errorTextList));
}

/**
 * 評点（様式の式と同じ計算）: 目標ごとの配点、中間・最終の本人・上長の評点と平均、コンピテンシーの 100 点換算
 *
 * @return array{points: array<int, int|null>, mid: array<string, float>, final: array<string, float>, competency_self: float, competency_boss: float}
 */
function goal_scores(array $sheetData, string $kindCode): array
{
    $pointList = [];
    $gradeScoreByKey = [];
    foreach ($sheetData['goals'] as $goalIndex => $goalData) {
        $goalPoints = GOAL_DIFFICULTY_POINTS[$goalData['difficulty']] ?? null;
        $pointList[$goalIndex] = $goalPoints;
        foreach (['mid_self', 'mid_boss', 'final_self', 'final_boss'] as $gradeKey) {
            $gradeScoreByKey[$gradeKey][$goalIndex] = ($goalPoints !== null && isset(GOAL_GRADE_FACTORS[$goalData[$gradeKey]]))
                ? GOAL_GRADE_FACTORS[$goalData[$gradeKey]] * $goalPoints : 0.0;
        }
    }
    $itemCount = max(1, count(GOAL_TEMPLATE_KINDS[$kindCode]['items']));
    $competencyScoreByKey = [];
    foreach (['competency_self', 'competency_boss'] as $scoreKey) {
        $competencyScoreByKey[$scoreKey] = array_sum(array_map('intval', array_filter($sheetData[$scoreKey], function (string $scoreText): bool { return $scoreText !== ''; }))) * 100 / ($itemCount * 5);
    }
    $averageOf = function (array $scoreList): float { return array_sum($scoreList) / max(1, count($scoreList)); };
    return [
        'points'          => $pointList,
        'mid'             => ['self' => $gradeScoreByKey['mid_self'], 'boss' => $gradeScoreByKey['mid_boss'],
                              'self_average' => $averageOf($gradeScoreByKey['mid_self']), 'boss_average' => $averageOf($gradeScoreByKey['mid_boss'])],
        'final'           => ['self' => $gradeScoreByKey['final_self'], 'boss' => $gradeScoreByKey['final_boss'],
                              'self_average' => $averageOf($gradeScoreByKey['final_self']), 'boss_average' => $averageOf($gradeScoreByKey['final_boss'])],
        'competency_self' => $competencyScoreByKey['competency_self'],
        'competency_boss' => $competencyScoreByKey['competency_boss'],
    ];
}

/** シートを保存する（新しく作るときは $sheetId = null）。保存したシートの ID */
function goal_save(?int $sheetId, int $ownerUserId, int $fiscalYear, string $kindCode, array $sheetData, int $actorUserId, string $historyAction): int
{
    return (int) tx(function () use ($sheetId, $ownerUserId, $fiscalYear, $kindCode, $sheetData, $actorUserId, $historyAction): int {
        if ($sheetId === null) {
            db_exec('INSERT INTO goal_sheets (user_id, fiscal_year, kind, data_enc, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$ownerUserId, $fiscalYear, $kindCode, '', now(), now()]);
            $sheetId = (int) db()->lastInsertId();
        }
        db_exec('UPDATE goal_sheets SET kind = ?, data_enc = ?, updated_at = ? WHERE id = ?',
            [$kindCode, enc((string) json_encode($sheetData, JSON_UNESCAPED_UNICODE), 'goal_sheets:' . $sheetId), now(), $sheetId]);
        goal_add_history($sheetId, $actorUserId, $historyAction);
        return $sheetId;
    });
}

/** 更新の履歴（だれが・いつ・何をしたか。中身は入れない。監査のため） */
function goal_add_history(int $sheetId, int $actorUserId, string $historyAction): void
{
    db_exec('INSERT INTO goal_sheet_history (sheet_id, user_id, action, created_at) VALUES (?, ?, ?, ?)', [$sheetId, $actorUserId, $historyAction, now()]);
}

/** 履歴の操作の表示名 */
const GOAL_HISTORY_LABELS = [
    'create' => '作成', 'save_owner' => '本人が保存', 'save_boss' => '課長が評価を保存', 'comment' => 'コメント', 'reply' => '返信',
    'meeting' => '面談の記録', 'download' => 'Excel を受け取った',
];

/**
 * コメントと返信（古い順。返信は親の下に並べる）
 *
 * @return array<int, array{id: int, user_id: int, user_name: string, body: string, created_at: string, replies: array<int, array<string, mixed>>}>
 */
function goal_comments(int $sheetId): array
{
    $commentById = [];
    $topCommentIdList = [];
    foreach (db_all('SELECT c.id, c.user_id, c.parent_id, c.body_enc, c.created_at, u.display_name FROM goal_sheet_comments c JOIN users u ON u.id = c.user_id WHERE c.sheet_id = ? ORDER BY c.id', [$sheetId]) as $commentRow) {
        $commentItem = [
            'id' => (int) $commentRow['id'], 'user_id' => (int) $commentRow['user_id'], 'user_name' => (string) $commentRow['display_name'],
            'body' => dec((string) $commentRow['body_enc'], 'goal_sheet_comments:' . $sheetId) ?? msg('（この内容は表示できません）'),
            'created_at' => (string) $commentRow['created_at'], 'replies' => [],
        ];
        if ($commentRow['parent_id'] === null) {
            $commentById[$commentItem['id']] = $commentItem;
            $topCommentIdList[] = $commentItem['id'];
        } elseif (isset($commentById[(int) $commentRow['parent_id']])) {
            $commentById[(int) $commentRow['parent_id']]['replies'][] = $commentItem;
        }
    }
    return array_map(function (int $commentId) use ($commentById): array { return $commentById[$commentId]; }, $topCommentIdList);
}

/**
 * 面談の記録（新しい順）
 *
 * @return array<int, array{id: int, held_on: string, note: string, user_name: string, created_at: string}>
 */
function goal_meetings(int $sheetId): array
{
    $meetingList = [];
    foreach (db_all('SELECT m.id, m.held_on, m.note_enc, m.created_at, u.display_name FROM goal_sheet_meetings m JOIN users u ON u.id = m.created_by WHERE m.sheet_id = ? ORDER BY m.held_on DESC, m.id DESC', [$sheetId]) as $meetingRow) {
        $meetingList[] = [
            'id' => (int) $meetingRow['id'], 'held_on' => (string) $meetingRow['held_on'], 'user_name' => (string) $meetingRow['display_name'],
            'note' => dec((string) $meetingRow['note_enc'], 'goal_sheet_meetings:' . $sheetId) ?? msg('（この内容は表示できません）'),
            'created_at' => (string) $meetingRow['created_at'],
        ];
    }
    return $meetingList;
}

/** 更新の履歴（新しい順） */
function goal_history(int $sheetId): array
{
    return db_all('SELECT h.action, h.created_at, u.display_name FROM goal_sheet_history h JOIN users u ON u.id = h.user_id WHERE h.sheet_id = ? ORDER BY h.id DESC', [$sheetId]);
}

// ---- 様式の xlsx（app/templates/goal_*.xlsx の、種類のシートに値を入れて渡す） ----------------

/**
 * シートのセルに値を入れる（書式はそのまま。様式にないセルは、行の中に列の順を守って足す。式は残す）
 *
 * @param string|int|float|null $cellValue
 */
function goal_xlsx_set_cell(DOMDocument $sheetDocument, DOMXPath $sheetXPath, string $cellReference, $cellValue): void
{
    $mainNamespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $cellNodeList = $sheetXPath->query('//m:sheetData/m:row/m:c[@r="' . $cellReference . '"]');
    if ($cellNodeList !== false && $cellNodeList->length > 0) {
        $cellElement = $cellNodeList->item(0);
    } else {
        $rowNumber = (int) preg_replace('/\A[A-Z]+/', '', $cellReference);
        $rowNodeList = $sheetXPath->query('//m:sheetData/m:row[@r="' . $rowNumber . '"]');
        if ($rowNodeList === false || $rowNodeList->length === 0) {
            throw new RuntimeException('xlsx: row not found in template: ' . $rowNumber);
        }
        $rowElement = $rowNodeList->item(0);
        $cellElement = $sheetDocument->createElementNS($mainNamespace, 'c');
        $cellElement->setAttribute('r', $cellReference);
        $columnText = (string) preg_replace('/\d+$/', '', $cellReference);
        $insertBeforeNode = null;
        foreach ($rowElement->childNodes as $existingNode) {
            if ($existingNode instanceof DOMElement && $existingNode->localName === 'c') {
                $existingColumnText = (string) preg_replace('/\d+$/', '', $existingNode->getAttribute('r'));
                if (strlen($existingColumnText) > strlen($columnText) || (strlen($existingColumnText) === strlen($columnText) && strcmp($existingColumnText, $columnText) > 0)) {
                    $insertBeforeNode = $existingNode;
                    break;
                }
            }
        }
        $rowElement->insertBefore($cellElement, $insertBeforeNode);
    }
    $hasFormula = false;
    foreach (iterator_to_array($cellElement->childNodes) as $childNode) {
        if ($childNode instanceof DOMElement && $childNode->localName === 'f') {
            $hasFormula = true;
            continue;
        }
        $cellElement->removeChild($childNode);
    }
    $cellElement->removeAttribute('t');
    if ($cellValue === null || $cellValue === '') {
        return;
    }
    if (is_int($cellValue) || is_float($cellValue)) {
        $cellElement->appendChild($sheetDocument->createElementNS($mainNamespace, 'v', is_float($cellValue) ? rtrim(rtrim(sprintf('%.10F', $cellValue), '0'), '.') : (string) $cellValue));
        return;
    }
    if ($hasFormula) {
        throw new RuntimeException('xlsx: text into formula cell: ' . $cellReference);
    }
    $cellElement->setAttribute('t', 'inlineStr');
    $inlineStringElement = $sheetDocument->createElementNS($mainNamespace, 'is');
    $textElement = $sheetDocument->createElementNS($mainNamespace, 't');
    $textElement->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
    $textElement->appendChild($sheetDocument->createTextNode((string) $cellValue));
    $inlineStringElement->appendChild($textElement);
    $cellElement->appendChild($inlineStringElement);
}

/** シートの中身を、種類の様式（app/templates/goal_*.xlsx）に入れて返す。開いたときに、そのシートが出るようにする */
function goal_xlsx(array $sheet): string
{
    $kindDefinition = GOAL_TEMPLATE_KINDS[$sheet['kind']];
    $entryByName = zip_read_entries((string) file_get_contents(APP_DIR . '/templates/' . $kindDefinition['file']));
    $sheetPath = $kindDefinition['sheet_path'];
    if (!isset($entryByName[$sheetPath], $entryByName['xl/workbook.xml'])) {
        throw new RuntimeException('xlsx: template sheet not found');
    }
    $sheetDocument = new DOMDocument();
    $sheetDocument->preserveWhiteSpace = true;
    if (!$sheetDocument->loadXML($entryByName[$sheetPath])) {
        throw new RuntimeException('xlsx: template sheet broken');
    }
    $sheetXPath = new DOMXPath($sheetDocument);
    $sheetXPath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $sheetData = $sheet['data'];
    $scores = goal_scores($sheetData, $sheet['kind']);

    goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'B5', $sheetData['department_name']);
    goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'F5', $sheetData['full_name']);
    goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'B6', $sheet['fiscal_year']);
    goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'G6', $sheetData['position']);
    goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'K5', $sheetData['overall_boss']);
    if (in_array($sheet['kind'], GOAL_BUSINESS_TARGET_KINDS, true)) {
        // 業績目標（様式の見本の値は残さない）。達成率と評点は様式の式の値も入れる（>120%：5／≧110%：4／≧100%：3／≧95%：2／≧85%：1／それ未満：0）
        $businessPlan = $sheetData['business_plan'] !== '' ? (float) $sheetData['business_plan'] : null;
        $businessActual = $sheetData['business_actual'] !== '' ? (float) $sheetData['business_actual'] : null;
        $achievementRate = ($businessPlan !== null && $businessPlan > 0 && $businessActual !== null) ? $businessActual / $businessPlan : null;
        $achievementScore = null;
        if ($achievementRate !== null) {
            $achievementScore = $achievementRate > 1.2 ? 5 : ($achievementRate >= 1.1 ? 4 : ($achievementRate >= 1.0 ? 3 : ($achievementRate >= 0.95 ? 2 : ($achievementRate >= 0.85 ? 1 : 0))));
        }
        goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'B8', $businessPlan);
        goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'C8', $businessActual);
        goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'D8', $achievementRate);
        goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'E8', $achievementScore);
    }
    foreach ($kindDefinition['goal_rows'] as $goalIndex => $goalHeadingRow) {
        $goalData = $sheetData['goals'][$goalIndex];
        goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'B' . $goalHeadingRow, $goalData['theme']);
        foreach (GOAL_TEXT_FIELDS as $fieldKey => $fieldSetting) {
            $wrappedLineList = goal_wrap_lines($goalData[$fieldKey], $fieldSetting[2]);
            for ($lineIndex = 0; $lineIndex < $fieldSetting[1]; $lineIndex++) {
                goal_xlsx_set_cell($sheetDocument, $sheetXPath, $fieldSetting[3] . ($goalHeadingRow + GOAL_TEXT_ROW_OFFSETS[$fieldKey] + $lineIndex), $wrappedLineList[$lineIndex] ?? null);
            }
        }
        goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'L' . ($goalHeadingRow + 2), $goalData['difficulty']);
        goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'L' . ($goalHeadingRow + 5), $scores['points'][$goalIndex] ?? null);
        goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'L' . ($goalHeadingRow + 10), $goalData['mid_self']);
        goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'L' . ($goalHeadingRow + 15), $goalData['mid_boss']);
        goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'L' . ($goalHeadingRow + 20), $goalData['final_self']);
        goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'L' . ($goalHeadingRow + 25), $goalData['final_boss']);
    }
    // 評点の行（目標 2 の見出しの 33 行下）の式の値。開いたときに計算し直さないソフトのために入れておく
    $scoreRow = $kindDefinition['goal_rows'][1] + 33;
    $scoreCellValues = [
        'A' => $scores['mid']['self'][0], 'B' => $scores['mid']['boss'][0], 'C' => $scores['mid']['self'][1], 'D' => $scores['mid']['boss'][1],
        'E' => $scores['mid']['self_average'], 'F' => $scores['mid']['boss_average'],
        'G' => $scores['final']['self'][0], 'H' => $scores['final']['boss'][0], 'I' => $scores['final']['self'][1], 'J' => $scores['final']['boss'][1],
        'K' => $scores['final']['self_average'], 'L' => $scores['final']['boss_average'],
    ];
    foreach ($scoreCellValues as $columnText => $scoreValue) {
        goal_xlsx_set_cell($sheetDocument, $sheetXPath, $columnText . $scoreRow, (float) $scoreValue);
    }
    foreach ($kindDefinition['items'] as $itemIndex => $itemDefinition) {
        $selfScore = $sheetData['competency_self'][$itemIndex];
        $bossScore = $sheetData['competency_boss'][$itemIndex];
        goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'K' . $itemDefinition['row'], $selfScore !== '' ? (int) $selfScore : null);
        goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'L' . $itemDefinition['row'], $bossScore !== '' ? (int) $bossScore : null);
    }
    goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'K' . $kindDefinition['score_row'], (float) $scores['competency_self']);
    goal_xlsx_set_cell($sheetDocument, $sheetXPath, 'L' . $kindDefinition['score_row'], (float) $scores['competency_boss']);

    // 開いたときに、このシートが出るようにする（ほかのシートの選択を外す）
    $sheetXml = (string) $sheetDocument->saveXML();
    $entryByName[$sheetPath] = (string) preg_replace('/<sheetView\b(?![^>]*tabSelected)/', '<sheetView tabSelected="1"', $sheetXml, 1);
    $sheetIndex = 0;
    if (preg_match_all('/<sheet\b[^>]*name="([^"]*)"/', $entryByName['xl/workbook.xml'], $sheetNameMatches)) {
        foreach ($sheetNameMatches[1] as $candidateIndex => $candidateName) {
            if (html_entity_decode($candidateName, ENT_QUOTES | ENT_XML1, 'UTF-8') === $kindDefinition['sheet']) {
                $sheetIndex = $candidateIndex;
            }
        }
    }
    foreach (array_keys($entryByName) as $entryName) {
        if ($entryName !== $sheetPath && strpos($entryName, 'xl/worksheets/sheet') === 0 && substr($entryName, -4) === '.xml') {
            $entryByName[$entryName] = (string) preg_replace('/(<sheetView\b[^>]*?)\s+tabSelected="1"/', '$1', $entryByName[$entryName], 1);
        }
    }
    $workbookXml = (string) preg_replace('/(<workbookView\b[^>]*?)\s+activeTab="\d+"/', '$1', $entryByName['xl/workbook.xml']);
    $workbookXml = (string) preg_replace('/<workbookView\b/', '<workbookView activeTab="' . $sheetIndex . '"', $workbookXml, 1);
    $entryByName['xl/workbook.xml'] = (string) preg_replace('/<calcPr\b(?![^>]*fullCalcOnLoad)/', '<calcPr fullCalcOnLoad="1"', $workbookXml, 1);
    return zip_store($entryByName);
}

/**
 * 課長が、課の人のその年度のシートをまとめて受け取る（zip の中に 1 人 1 つの xlsx）。課長自身・ほかの課長のシートは入れない。
 * 受け取ったことは、それぞれのシートの更新の履歴に残す。シートが 1 つも無ければ null
 */
function goal_department_zip(array $viewer, int $departmentId, int $fiscalYear): ?string
{
    $entryByName = [];
    foreach (db_all('SELECT s.id FROM goal_sheets s JOIN users u ON u.id = s.user_id WHERE u.department_id = ? AND s.fiscal_year = ? ORDER BY u.id', [$departmentId, $fiscalYear]) as $sheetRow) {
        $memberSheet = goal_sheet((int) $sheetRow['id']);
        if ($memberSheet === null || $memberSheet['unreadable'] || goal_viewer_role($viewer, $memberSheet['user_id']) !== 'boss') {
            continue;
        }
        $entryName = goal_xlsx_file_name($memberSheet);
        if (isset($entryByName[$entryName])) {
            $entryName = substr($entryName, 0, -5) . '_' . $memberSheet['user_id'] . '.xlsx';   // 同じ氏名の人がいたら、利用者の番号で分ける
        }
        $entryByName[$entryName] = goal_xlsx($memberSheet);
        goal_add_history($memberSheet['id'], (int) $viewer['id'], 'download');
    }
    return $entryByName ? zip_store($entryByName) : null;
}

/** ダウンロードのファイル名（例: 目標管理評価シート_2026年度_A一般1.xlsx） */
function goal_xlsx_file_name(array $sheet): string
{
    $safeName = (string) preg_replace('/[\\\\\/:*?"<>|\s]+/u', '', $sheet['data']['full_name']);
    return '目標管理評価シート_' . $sheet['fiscal_year'] . '年度_' . ($safeName !== '' ? $safeName : 'sheet') . '.xlsx';
}
