<?php

declare(strict_types=1);

// ====================================================================
// 組織の一括の入れ替え（CSV）（詳細設計書 G14_org_users.md §6.8）
// 毎年の組織改訂で、課員の所属・ライン・役割をまとめて入れる。動かない人は CSV でも同じ値のまま（変わらない）。
// 流れ: 今の組織を出力 → 直す → 取り込む（変わる内容を確かめる）→ 反映する。
// ====================================================================

/** CSV の列（出力も取り込みもこの順） */
const ORG_CSV_COLUMNS = ['ログイン ID', '表示名', '所属の課', 'ライン', '役割', '担当のライン', '項目管理者', '有効', '兼務'];
/** 「兼務」の列が無い CSV（2026-11-26 より前に出力したもの）の列。取り込むと、兼務はそのまま */
const ORG_CSV_COLUMNS_WITHOUT_CONCURRENT = ['ログイン ID', '表示名', '所属の課', 'ライン', '役割', '担当のライン', '項目管理者', '有効'];
const ORG_CSV_ROLE_LABELS = ['' => '', 'reviewer' => '確認者', 'final' => '課長'];
/** 前の見出しの名前（「班」を「ライン」に変える前に出力した CSV も取り込めるように、今の名前に読み替える。2026-11-24） */
const ORG_CSV_OLD_COLUMN_NAMES = ['班' => 'ライン', '担当の班' => '担当のライン'];

/**
 * 今の組織を CSV の行にする（1 人 1 行。役割は所属の課での役割）。
 *
 * @return array<int, string[]>
 */
function org_csv_current_rows(): array
{
    $csvRowList = [];
    foreach (db_all(
        'SELECT u.id, u.login_id, u.display_name, u.is_active, d.name AS department_name, t.name AS team_name,
                r.is_final, rt.name AS reviewer_team_name, (a.user_id IS NOT NULL) AS is_field_admin
           FROM users u
           LEFT JOIN departments d ON d.id = u.department_id
           LEFT JOIN department_teams t ON t.id = u.team_id
           LEFT JOIN department_reviewers r ON r.user_id = u.id AND r.department_id = u.department_id
           LEFT JOIN department_teams rt ON rt.id = r.team_id
           LEFT JOIN department_admins a ON a.user_id = u.id AND a.department_id = u.department_id
          WHERE u.login_id IS NOT NULL
          ORDER BY d.sort_order, d.id, u.display_name'
    ) as $userRow) {
        $roleCode = $userRow['is_final'] === null ? '' : ((int) $userRow['is_final'] === 1 ? 'final' : 'reviewer');
        $csvRowList[] = [
            (string) $userRow['login_id'], (string) $userRow['display_name'], (string) ($userRow['department_name'] ?? ''),
            (string) ($userRow['team_name'] ?? ''), ORG_CSV_ROLE_LABELS[$roleCode], (string) ($userRow['reviewer_team_name'] ?? ''),
            (int) $userRow['is_field_admin'] === 1 ? '1' : '', (int) $userRow['is_active'] === 1 ? '1' : '0',
            org_csv_concurrent_text(org_csv_current_concurrent_roles((int) $userRow['id'])),
        ];
    }
    return $csvRowList;
}

/**
 * 所属の課の外での役割（兼務）。課の並び順。
 *
 * @return array<int, array{departmentId: int, departmentName: string, roleCode: string, reviewerTeamName: string}>
 */
function org_csv_current_concurrent_roles(int $userId): array
{
    $concurrentRoleList = [];
    foreach (db_all(
        'SELECT r.department_id, d.name AS department_name, r.is_final, rt.name AS reviewer_team_name
           FROM department_reviewers r JOIN users u ON u.id = r.user_id JOIN departments d ON d.id = r.department_id
           LEFT JOIN department_teams rt ON rt.id = r.team_id
          WHERE r.user_id = ? AND r.department_id IS NOT u.department_id
          ORDER BY d.sort_order, d.id',
        [$userId]
    ) as $concurrentRow) {
        $concurrentRoleList[] = [
            'departmentId' => (int) $concurrentRow['department_id'], 'departmentName' => (string) $concurrentRow['department_name'],
            'roleCode' => (int) $concurrentRow['is_final'] === 1 ? 'final' : 'reviewer', 'reviewerTeamName' => (string) ($concurrentRow['reviewer_team_name'] ?? ''),
        ];
    }
    return $concurrentRoleList;
}

/**
 * 兼務を CSV の 1 つの欄の文にする（「課の名前:役割[:担当のライン]」を「; 」でつなぐ。例「B課:課長; C課:確認者:山田ライン」）。
 *
 * @param array<int, array{departmentName: string, roleCode: string, reviewerTeamName: string}> $concurrentRoleList
 */
function org_csv_concurrent_text(array $concurrentRoleList): string
{
    return implode('; ', array_map(static function (array $concurrentRole): string {
        return $concurrentRole['departmentName'] . ':' . ORG_CSV_ROLE_LABELS[$concurrentRole['roleCode']]
            . ($concurrentRole['reviewerTeamName'] !== '' ? ':' . $concurrentRole['reviewerTeamName'] : '');
    }, $concurrentRoleList));
}

/**
 * 取り込んだ CSV を読み、反映の計画を作る（DB は変えない）。
 *
 * @param string $csvText 取り込んだファイルの中身（UTF-8。BOM・出力の見出し行があってもよい）
 * @param int $operatorUserId 操作する管理者（自分自身を無効にしないため）
 * @return array{
 *   errorTextList: string[],
 *   changeList: array<int, array{loginId: string, displayName: string, descriptionList: string[]}>,
 *   newTeamList: array<int, array{departmentId: int, departmentName: string, teamName: string}>,
 *   planByUserId: array<int, array{departmentId: ?int, teamName: string, roleCode: string, reviewerTeamName: string, isFieldAdmin: bool, isActive: bool}>
 * }
 */
function org_csv_plan(string $csvText, int $operatorUserId): array
{
    $plan = ['errorTextList' => [], 'warningTextList' => [], 'changeList' => [], 'newTeamList' => [], 'planByUserId' => [], 'hasConcurrentColumn' => false];
    $readColumnList = ORG_CSV_COLUMNS;
    // 補佐が取り込むときは、管理者・補佐の利用者を変えられない（K-043）
    // 補佐（開発担当。K-059）は管理者と同じに扱う
    $isOperatorDeputy = (int) db_val('SELECT is_admin_deputy FROM users WHERE id = ? AND is_admin_deputy_lead = 0', [$operatorUserId]) === 1;
    $csvText = preg_replace('/\A\xEF\xBB\xBF/', '', $csvText);
    $csvStream = fopen('php://memory', 'r+');
    fwrite($csvStream, $csvText);
    rewind($csvStream);
    $isHeaderFound = false;
    $lineNumber = 0;
    $departmentIdByName = [];
    foreach (db_all('SELECT id, name FROM departments WHERE is_active = 1') as $departmentRow) {
        $departmentIdByName[(string) $departmentRow['name']] = (int) $departmentRow['id'];
    }
    $seenLoginIdSet = [];
    $parsedRowList = [];
    while (($csvCells = fgetcsv($csvStream, 0, ',', '"', '')) !== false) {
        $lineNumber++;
        if ($csvCells === [null] || $csvCells === ['']) {
            continue;
        }
        $csvCells = array_map(function ($cellValue): string { return trim((string) $cellValue); }, $csvCells);
        if (!$isHeaderFound) {
            $headerCells = array_map(function (string $headerCell): string { return ORG_CSV_OLD_COLUMN_NAMES[$headerCell] ?? $headerCell; }, $csvCells);
            if ($headerCells === ORG_CSV_COLUMNS || $headerCells === ORG_CSV_COLUMNS_WITHOUT_CONCURRENT) {
                $isHeaderFound = true;
                $readColumnList = $headerCells;
                $plan['hasConcurrentColumn'] = $headerCells === ORG_CSV_COLUMNS;
            }
            continue;   // 見出しの前の行（「社外秘」など）は読み飛ばす
        }
        if (count($csvCells) !== count($readColumnList)) {
            $plan['errorTextList'][] = msg('{line} 行目: 列の数が違います', ['line' => $lineNumber]);
            continue;
        }
        $parsedRowList[] = ['lineNumber' => $lineNumber, 'cells' => array_combine($readColumnList, $csvCells) + ['兼務' => '']];
    }
    fclose($csvStream);
    if (!$isHeaderFound) {
        $plan['errorTextList'][] = msg('見出しの行（{columns}）がありません。「今の組織を CSV に出力」したファイルを直して使ってください', ['columns' => implode(',', ORG_CSV_COLUMNS)]);
        return $plan;
    }
    if (count($parsedRowList) > 2000) {
        $plan['errorTextList'][] = msg('行が多すぎます（2,000 行まで）');
        return $plan;
    }

    $roleCodeByLabel = array_flip(ORG_CSV_ROLE_LABELS);
    $finalUserIdListByDepartmentId = [];
    foreach ($parsedRowList as $parsedRow) {
        $lineNumber = $parsedRow['lineNumber'];
        $cells = $parsedRow['cells'];
        $loginId = auth_normalize_login_id($cells['ログイン ID']);
        $userRow = db_one('SELECT * FROM users WHERE login_id = ?', [$loginId]);
        if ($userRow === null) {
            $plan['errorTextList'][] = msg('{line} 行目: 登録されていないログイン ID です（{login}）。新しい人は先に「ユーザーを追加」で登録してください', ['line' => $lineNumber, 'login' => $loginId]);
            continue;
        }
        $userId = (int) $userRow['id'];
        if (isset($seenLoginIdSet[$loginId])) {
            $plan['errorTextList'][] = msg('{line} 行目: 同じログイン ID が 2 回あります（{login}）', ['line' => $lineNumber, 'login' => $loginId]);
            continue;
        }
        $seenLoginIdSet[$loginId] = true;
        $departmentName = $cells['所属の課'];
        if ($departmentName !== '' && !isset($departmentIdByName[$departmentName])) {
            $plan['errorTextList'][] = msg('{line} 行目: 有効な課にない名前です（{department}）', ['line' => $lineNumber, 'department' => $departmentName]);
            continue;
        }
        $departmentId = $departmentName === '' ? null : $departmentIdByName[$departmentName];
        if (!isset($roleCodeByLabel[$cells['役割']])) {
            $plan['errorTextList'][] = msg('{line} 行目: 役割は「課長」「確認者」か空にしてください', ['line' => $lineNumber]);
            continue;
        }
        $roleCode = (string) $roleCodeByLabel[$cells['役割']];
        if (!in_array($cells['有効'], ['1', '0'], true) || !in_array($cells['項目管理者'], ['1', ''], true)) {
            $plan['errorTextList'][] = msg('{line} 行目: 「有効」は 1 か 0、「項目管理者」は 1 か空にしてください', ['line' => $lineNumber]);
            continue;
        }
        $isActive = $cells['有効'] === '1';
        if (!$isActive && $userId === $operatorUserId) {
            $plan['errorTextList'][] = msg('{line} 行目: 自分自身は無効にできません', ['line' => $lineNumber]);
            continue;
        }
        if ($departmentId === null && ($cells['ライン'] !== '' || $roleCode !== '' || $cells['担当のライン'] !== '' || $cells['項目管理者'] === '1')) {
            $plan['errorTextList'][] = msg('{line} 行目: 所属の課がない人に、ライン・役割は付けられません', ['line' => $lineNumber]);
            continue;
        }
        if (($roleCode !== '' || $cells['項目管理者'] === '1') && specialist_title_of($userId) !== '') {
            $plan['errorTextList'][] = msg('{line} 行目: 専門職の人は、課長・確認者・項目管理者にできません', ['line' => $lineNumber]);
            continue;
        }
        if ($roleCode !== 'reviewer' && $cells['担当のライン'] !== '') {
            $plan['errorTextList'][] = msg('{line} 行目: 担当のラインは「確認者」のときだけ入れてください', ['line' => $lineNumber]);
            continue;
        }
        foreach (['ライン', '担当のライン'] as $teamColumnName) {
            if (str_len($cells[$teamColumnName]) > 40 || has_control_chars($cells[$teamColumnName])) {
                $plan['errorTextList'][] = msg('{line} 行目: ラインの名前は 40 文字以内にしてください', ['line' => $lineNumber]);
                continue 2;
            }
        }
        // 兼務（所属の課の外での役割）。列が無い CSV では、今の兼務をそのまま残す
        $concurrentRoleList = null;
        $concurrentParse = null;
        if ($plan['hasConcurrentColumn']) {
            $concurrentParse = org_csv_parse_concurrent($cells['兼務'], $departmentId, $departmentIdByName, $lineNumber);
            if ($concurrentParse['errorTextList']) {
                $plan['errorTextList'] = array_merge($plan['errorTextList'], $concurrentParse['errorTextList']);
                continue;
            }
            $concurrentRoleList = $isActive ? $concurrentParse['roleList'] : [];
            if ($concurrentRoleList && specialist_title_of($userId) !== '') {
                $plan['errorTextList'][] = msg('{line} 行目: 専門職の人は、課長・確認者・項目管理者にできません', ['line' => $lineNumber]);
                continue;
            }
        }
        $currentConcurrentRoleList = org_csv_current_concurrent_roles($userId);
        // 課長の数に入れる兼務: 列があれば CSV の兼務、無ければ今の兼務（所属の課を移って、その課の役割になるものは除く）
        foreach ($concurrentRoleList ?? ($isActive ? $currentConcurrentRoleList : []) as $concurrentRole) {
            if ($concurrentRole['roleCode'] === 'final' && $concurrentRole['departmentId'] !== $departmentId) {
                $finalUserIdListByDepartmentId[$concurrentRole['departmentId']][] = $userId;
                if ($concurrentParse !== null && !role_title_fits_final((string) $userRow['role_title']) && !is_final_reviewer_of($userId, $concurrentRole['departmentId'])) {
                    $plan['warningTextList'][] = msg('{line} 行目: {name} さんの役職は「{title}」です（課長・部長ではありません）。役職と異なる役割をお任せする場合のみ、このまま反映してください。',
                        ['line' => $lineNumber, 'name' => (string) $userRow['display_name'], 'title' => (string) $userRow['role_title']]);
                }
            }
        }
        if ($roleCode === 'final' && $isActive) {
            $finalUserIdListByDepartmentId[$departmentId][] = $userId;
            // 役職が課長・部長でない人を新しく課長にするときは知らせる（止めはしない。K-069）
            $finalCandidateRow = db_one('SELECT display_name, role_title FROM users WHERE id = ?', [$userId]);
            if ($finalCandidateRow !== null && !role_title_fits_final((string) $finalCandidateRow['role_title']) && !is_final_reviewer_of($userId, $departmentId)) {
                $plan['warningTextList'][] = msg('{line} 行目: {name} さんの役職は「{title}」です（課長・部長ではありません）。役職と異なる役割をお任せする場合のみ、このまま反映してください。',
                    ['line' => $lineNumber, 'name' => (string) $finalCandidateRow['display_name'], 'title' => (string) $finalCandidateRow['role_title']]);
            }
        }
        $plan['planByUserId'][$userId] = [
            'departmentId' => $departmentId, 'teamName' => $cells['ライン'], 'roleCode' => $isActive ? $roleCode : '',
            'reviewerTeamName' => $isActive ? $cells['担当のライン'] : '', 'isFieldAdmin' => $isActive && $cells['項目管理者'] === '1', 'isActive' => $isActive,
            'concurrentRoleList' => $concurrentRoleList,
        ];

        // 変わる内容
        $currentPlacementRow = db_one(
            'SELECT d.name AS department_name, t.name AS team_name, r.is_final, rt.name AS reviewer_team_name, (a.user_id IS NOT NULL) AS is_field_admin
               FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN department_teams t ON t.id = u.team_id
               LEFT JOIN department_reviewers r ON r.user_id = u.id AND r.department_id = u.department_id
               LEFT JOIN department_teams rt ON rt.id = r.team_id
               LEFT JOIN department_admins a ON a.user_id = u.id AND a.department_id = u.department_id
              WHERE u.id = ?',
            [$userId]
        );
        $currentRoleCode = $currentPlacementRow['is_final'] === null ? '' : ((int) $currentPlacementRow['is_final'] === 1 ? 'final' : 'reviewer');
        $descriptionList = [];
        $describeChange = function (string $label, string $currentValue, string $newValue) use (&$descriptionList): void {
            if ($currentValue !== $newValue) {
                $descriptionList[] = $label . ': ' . ($currentValue === '' ? '（なし）' : $currentValue) . ' → ' . ($newValue === '' ? '（なし）' : $newValue);
            }
        };
        $describeChange(msg('所属の課'), (string) ($currentPlacementRow['department_name'] ?? ''), $departmentName);
        $describeChange(msg('ライン'), (string) ($currentPlacementRow['team_name'] ?? ''), $cells['ライン']);
        $describeChange(msg('役割'), ORG_CSV_ROLE_LABELS[$currentRoleCode], ORG_CSV_ROLE_LABELS[$isActive ? $roleCode : '']);
        $describeChange(msg('担当のライン'), (string) ($currentPlacementRow['reviewer_team_name'] ?? ''), $isActive ? $cells['担当のライン'] : '');
        $describeChange(msg('項目管理者'), (int) $currentPlacementRow['is_field_admin'] === 1 ? '1' : '', $isActive && $cells['項目管理者'] === '1' ? '1' : '');
        $describeChange(msg('有効'), (int) $userRow['is_active'] === 1 ? '1' : '0', $isActive ? '1' : '0');
        if ($concurrentRoleList !== null) {
            $describeChange(msg('兼務'), org_csv_concurrent_text($currentConcurrentRoleList), org_csv_concurrent_text($concurrentRoleList));
        } elseif ($currentConcurrentRoleList && $isActive) {
            $descriptionList[] = msg('（兼務の列が無い CSV のため、兼務の役割はそのまま）');
        }
        if ($userRow['department_id'] !== null && (int) $userRow['department_id'] !== $departmentId && $currentRoleCode !== '') {
            $descriptionList[] = msg('（前の課の役割は外す）');
        }
        if ($descriptionList && $isOperatorDeputy && user_row_is_system_manager($userRow)) {
            $plan['errorTextList'][] = msg('{loginId}: 管理者と補佐の利用者は、管理者だけが変えられます', ['loginId' => $loginId]);
        }
        if ($descriptionList) {
            $plan['changeList'][] = ['loginId' => $loginId, 'displayName' => (string) $userRow['display_name'], 'descriptionList' => $descriptionList];
        }

        // 新しいライン（所属の課のライン・担当のラインと、兼務の担当のライン）
        $plannedTeamList = [[$departmentId, $departmentName, $cells['ライン']], [$departmentId, $departmentName, $isActive ? $cells['担当のライン'] : '']];
        foreach ($concurrentRoleList ?? [] as $concurrentRole) {
            $plannedTeamList[] = [$concurrentRole['departmentId'], $concurrentRole['departmentName'], $concurrentRole['reviewerTeamName']];
        }
        foreach ($plannedTeamList as [$plannedDepartmentId, $plannedDepartmentName, $teamName]) {
            $departmentId = $plannedDepartmentId;
            $departmentName = $plannedDepartmentName;
            if ($teamName === '' || $departmentId === null) {
                continue;
            }
            $isExistingTeam = db_val('SELECT 1 FROM department_teams WHERE department_id = ? AND name = ? AND is_active = 1', [$departmentId, $teamName]) !== null;
            $isAlreadyPlanned = (bool) array_filter($plan['newTeamList'], function (array $newTeam) use ($departmentId, $teamName): bool {
                return $newTeam['departmentId'] === $departmentId && $newTeam['teamName'] === $teamName;
            });
            if (!$isExistingTeam && !$isAlreadyPlanned) {
                $plan['newTeamList'][] = ['departmentId' => $departmentId, 'departmentName' => $departmentName, 'teamName' => $teamName];
            }
        }
    }

    // CSV に載っていない有効な人（この人たちは変わらない。行を消しても課から外れないことに気づけるよう、変わる内容と一緒に出す。2026-11-26 指示）
    $plan['notInCsvList'] = [];
    foreach (db_all(
        'SELECT u.login_id, u.display_name, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id
          WHERE u.is_active = 1 AND u.login_id IS NOT NULL ORDER BY d.sort_order, d.id, u.display_name'
    ) as $notListedRow) {
        if (!isset($seenLoginIdSet[(string) $notListedRow['login_id']])) {
            $plan['notInCsvList'][] = ['loginId' => (string) $notListedRow['login_id'], 'displayName' => (string) $notListedRow['display_name'],
                'departmentName' => (string) ($notListedRow['department_name'] ?? '')];
        }
    }

    // 課長は課に 1 人まで（CSV にない人の今の課長も数える）
    foreach ($departmentIdByName as $departmentName => $departmentId) {
        $finalUserIdSet = array_fill_keys($finalUserIdListByDepartmentId[$departmentId] ?? [], true);
        foreach (db_all('SELECT r.user_id FROM department_reviewers r JOIN users u ON u.id = r.user_id AND u.is_active = 1 WHERE r.department_id = ? AND r.is_final = 1', [$departmentId]) as $currentFinalRow) {
            if (!isset($plan['planByUserId'][(int) $currentFinalRow['user_id']])) {
                $finalUserIdSet[(int) $currentFinalRow['user_id']] = true;
            }
        }
        if (count($finalUserIdSet) > 1) {
            $plan['errorTextList'][] = msg('{department} の課長が 2 人以上になります（課長は 1 人にしてください）', ['department' => $departmentName]);
        }
    }
    // 管理者が 1 人もいなくならないように
    $remainingAdminCount = 0;
    foreach (db_all('SELECT id FROM users WHERE is_admin = 1 AND is_active = 1') as $adminRow) {
        $adminPlan = $plan['planByUserId'][(int) $adminRow['id']] ?? null;
        $remainingAdminCount += ($adminPlan === null || $adminPlan['isActive']) ? 1 : 0;
    }
    if ($remainingAdminCount === 0) {
        $plan['errorTextList'][] = msg('管理者が 1 人もいなくなるため、反映できません');
    }
    // 開発担当でない補佐も 1 名以上置く（K-075）。もともといないときは、ほかの人の取り込みを止めない
    $activeDeputyRowList = db_all('SELECT id FROM users WHERE is_admin_deputy = 1 AND is_admin_deputy_lead = 0 AND is_active = 1');
    $remainingDeputyCount = 0;
    foreach ($activeDeputyRowList as $deputyRow) {
        $deputyPlan = $plan['planByUserId'][(int) $deputyRow['id']] ?? null;
        $remainingDeputyCount += ($deputyPlan === null || $deputyPlan['isActive']) ? 1 : 0;
    }
    if ($activeDeputyRowList && $remainingDeputyCount === 0) {
        $plan['errorTextList'][] = msg('開発担当のほかに補佐が 1 人もいなくなるため、反映できません');
    }
    return $plan;
}

/**
 * 「兼務」の欄を読む（「課の名前:役割[:担当のライン]」を「;」でつなぐ。全角の「：」「；」も読む。空は兼務なし）。
 *
 * @param array<string, int> $departmentIdByName 有効な課（名前 => ID）
 * @return array{errorTextList: string[], roleList: array<int, array{departmentId: int, departmentName: string, roleCode: string, reviewerTeamName: string}>}
 */
function org_csv_parse_concurrent(string $concurrentText, ?int $homeDepartmentId, array $departmentIdByName, int $lineNumber): array
{
    $parsed = ['errorTextList' => [], 'roleList' => []];
    $concurrentText = str_replace(['；', '：'], [';', ':'], trim($concurrentText));
    if ($concurrentText === '') {
        return $parsed;
    }
    $roleCodeByLabel = array_flip(ORG_CSV_ROLE_LABELS);
    $seenDepartmentIdSet = [];
    foreach (explode(';', $concurrentText) as $entryText) {
        $entryText = trim($entryText);
        if ($entryText === '') {
            continue;
        }
        $entryPartList = array_map('trim', explode(':', $entryText));
        $concurrentDepartmentName = $entryPartList[0];
        $roleLabel = $entryPartList[1] ?? '';
        $reviewerTeamName = $entryPartList[2] ?? '';
        if (count($entryPartList) > 3 || !isset($departmentIdByName[$concurrentDepartmentName]) || !in_array($roleLabel, ['課長', '確認者'], true)) {
            $parsed['errorTextList'][] = msg('{line} 行目: 兼務は「課の名前:課長」か「課の名前:確認者[:担当のライン]」を「;」でつないでください（{entry}）', ['line' => $lineNumber, 'entry' => $entryText]);
            continue;
        }
        $concurrentDepartmentId = $departmentIdByName[$concurrentDepartmentName];
        if ($concurrentDepartmentId === $homeDepartmentId) {
            $parsed['errorTextList'][] = msg('{line} 行目: 所属の課（{department}）の役割は、兼務ではなく「役割」の列に入れてください', ['line' => $lineNumber, 'department' => $concurrentDepartmentName]);
            continue;
        }
        if (isset($seenDepartmentIdSet[$concurrentDepartmentId])) {
            $parsed['errorTextList'][] = msg('{line} 行目: 兼務に同じ課が 2 回あります（{department}）', ['line' => $lineNumber, 'department' => $concurrentDepartmentName]);
            continue;
        }
        $seenDepartmentIdSet[$concurrentDepartmentId] = true;
        $roleCode = (string) $roleCodeByLabel[$roleLabel];
        if ($roleCode === 'final' && $reviewerTeamName !== '') {
            $parsed['errorTextList'][] = msg('{line} 行目: 担当のラインは「確認者」のときだけ入れてください', ['line' => $lineNumber]);
            continue;
        }
        if (str_len($reviewerTeamName) > 40 || has_control_chars($reviewerTeamName)) {
            $parsed['errorTextList'][] = msg('{line} 行目: ラインの名前は 40 文字以内にしてください', ['line' => $lineNumber]);
            continue;
        }
        $parsed['roleList'][] = ['departmentId' => $concurrentDepartmentId, 'departmentName' => $concurrentDepartmentName, 'roleCode' => $roleCode, 'reviewerTeamName' => $reviewerTeamName];
    }
    return $parsed;
}

/**
 * 計画を反映する（1 つのトランザクション）。誤りのない計画だけを渡す。
 *
 * @return int 変えた人の数
 */
function org_csv_apply(array $plan): int
{
    return tx(function () use ($plan): int {
        $teamIdByDepartmentAndName = [];
        $findOrCreateTeamId = function (int $departmentId, string $teamName) use (&$teamIdByDepartmentAndName): int {
            $cacheKey = $departmentId . "\n" . $teamName;
            if (!isset($teamIdByDepartmentAndName[$cacheKey])) {
                $teamRow = db_one('SELECT id, is_active FROM department_teams WHERE department_id = ? AND name = ?', [$departmentId, $teamName]);
                if ($teamRow === null) {
                    $nextSortOrder = (int) db_val('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM department_teams WHERE department_id = ?', [$departmentId]);
                    db_exec('INSERT INTO department_teams (department_id, name, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)',
                        [$departmentId, $teamName, $nextSortOrder, now(), now()]);
                    $teamIdByDepartmentAndName[$cacheKey] = (int) db()->lastInsertId();
                } else {
                    if ((int) $teamRow['is_active'] !== 1) {
                        db_exec('UPDATE department_teams SET is_active = 1, updated_at = ? WHERE id = ?', [now(), (int) $teamRow['id']]);
                    }
                    $teamIdByDepartmentAndName[$cacheKey] = (int) $teamRow['id'];
                }
            }
            return $teamIdByDepartmentAndName[$cacheKey];
        };
        // 1 回目: 変える人の役割（確認者・課長の行）を先にすべて外す。
        //   1 人ずつ外して付け直すと、新しい課長の行が今の課長の行より先に来たとき、今の課長が残ったまま新しい課長を入れることになり、
        //   「課長は 1 つの課に 1 人」（ux_department_final）に当たって反映できない（課長の交代）
        $userRowById = [];
        $previousDoesCheckByUserId = [];
        $previousConcurrentDoesCheckByKey = [];
        foreach ($plan['planByUserId'] as $userId => $userPlan) {
            $userRow = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
            $userRowById[$userId] = $userRow;
            $departmentId = $userPlan['departmentId'];
            // 前の課から移るとき・無効にするときは、前の課（所属の課）の役割を外す
            if ($userRow['department_id'] !== null && ((int) $userRow['department_id'] !== $departmentId || !$userPlan['isActive'])) {
                db_exec('DELETE FROM department_reviewers WHERE user_id = ? AND department_id = ?', [$userId, (int) $userRow['department_id']]);
            }
            if (!$userPlan['isActive']) {
                db_exec('DELETE FROM department_reviewers WHERE user_id = ?', [$userId]);
            }
            if ($departmentId !== null && $userPlan['isActive']) {
                $previousDoesCheckByUserId[$userId] = db_val('SELECT does_check FROM department_reviewers WHERE user_id = ? AND department_id = ?', [$userId, $departmentId]);
                db_exec('DELETE FROM department_reviewers WHERE user_id = ? AND department_id = ?', [$userId, $departmentId]);
            }
            // 兼務の列がある CSV: 所属の課の外の役割を外す（CSV の兼務で付け直す。チェックするかどうかは前の値を引き継ぐ）
            if (($userPlan['concurrentRoleList'] ?? null) !== null) {
                foreach (db_all('SELECT department_id, does_check FROM department_reviewers WHERE user_id = ? AND department_id IS NOT ?', [$userId, $departmentId]) as $concurrentRow) {
                    $previousConcurrentDoesCheckByKey[$userId . ':' . $concurrentRow['department_id']] = (int) $concurrentRow['does_check'];
                }
                db_exec('DELETE FROM department_reviewers WHERE user_id = ? AND department_id IS NOT ?', [$userId, $departmentId]);
            }
        }
        // 2 回目: 所属・有効を変え、役割を付け直す
        $changedUserCount = 0;
        foreach ($plan['planByUserId'] as $userId => $userPlan) {
            $userRow = $userRowById[$userId];
            $departmentId = $userPlan['departmentId'];
            $teamId = ($departmentId !== null && $userPlan['teamName'] !== '') ? $findOrCreateTeamId($departmentId, $userPlan['teamName']) : null;
            if ($userRow['department_id'] !== null && ((int) $userRow['department_id'] !== $departmentId || !$userPlan['isActive'])) {
                db_exec('DELETE FROM department_admins WHERE user_id = ? AND department_id = ?', [$userId, (int) $userRow['department_id']]);
            }
            if (!$userPlan['isActive']) {
                db_exec('DELETE FROM department_admins WHERE user_id = ?', [$userId]);
                if ((int) $userRow['is_active'] === 1) {
                    auth_forget_all_devices($userId);
                }
            }
            db_exec('UPDATE users SET department_id = ?, team_id = ?, is_active = ?, updated_at = ? WHERE id = ?',
                [$departmentId, $teamId, $userPlan['isActive'] ? 1 : 0, now(), $userId]);
            if ($departmentId !== null && $userPlan['isActive']) {
                $previousDoesCheck = $previousDoesCheckByUserId[$userId] ?? null;
                if ($userPlan['roleCode'] !== '') {
                    $isFinalRole = $userPlan['roleCode'] === 'final';
                    $reviewerTeamId = (!$isFinalRole && $userPlan['reviewerTeamName'] !== '') ? $findOrCreateTeamId($departmentId, $userPlan['reviewerTeamName']) : null;
                    $doesCheck = $isFinalRole ? 1 : ($previousDoesCheck !== null ? (int) $previousDoesCheck : reviewer_default_does_check($userId, false));
                    db_exec('INSERT INTO department_reviewers (department_id, user_id, is_final, sort_order, team_id, does_check) VALUES (?, ?, ?, ?, ?, ?)',
                        [$departmentId, $userId, $isFinalRole ? 1 : 0, $isFinalRole ? 0 : 100, $reviewerTeamId, $doesCheck]);
                }
                db_exec('DELETE FROM department_admins WHERE user_id = ? AND department_id = ?', [$userId, $departmentId]);
                if ($userPlan['isFieldAdmin']) {
                    db_exec('INSERT INTO department_admins (department_id, user_id) VALUES (?, ?)', [$departmentId, $userId]);
                }
            }
            if ($userPlan['isActive']) {
                foreach ($userPlan['concurrentRoleList'] ?? [] as $concurrentRole) {
                    $isConcurrentFinal = $concurrentRole['roleCode'] === 'final';
                    $concurrentTeamId = (!$isConcurrentFinal && $concurrentRole['reviewerTeamName'] !== '') ? $findOrCreateTeamId($concurrentRole['departmentId'], $concurrentRole['reviewerTeamName']) : null;
                    $concurrentDoesCheck = $isConcurrentFinal ? 1 : ($previousConcurrentDoesCheckByKey[$userId . ':' . $concurrentRole['departmentId']] ?? reviewer_default_does_check($userId, false));
                    db_exec('INSERT INTO department_reviewers (department_id, user_id, is_final, sort_order, team_id, does_check) VALUES (?, ?, ?, ?, ?, ?)',
                        [$concurrentRole['departmentId'], $userId, $isConcurrentFinal ? 1 : 0, $isConcurrentFinal ? 0 : 100, $concurrentTeamId, $concurrentDoesCheck]);
                }
            }
            $changedUserCount++;
        }
        return $changedUserCount;
    });
}

// ====================================================================
// 組織の改訂の予約（便利機能 G。G-14 §6.8）
//   取り込んだ CSV を予約として持ち、予約した日（apply_on）になったら、その時点の組織に対してもう一度確かめてから反映する。
//   予約の間に手で変えた分とぶつかるなど誤りがあれば反映せず「失敗」にして、管理のトップで知らせる。
//   反映は、その日以降の最初の画面の表示（page_start）か、app/bin/run_schedules.php（定期の処理）で動く。
// ====================================================================

/** 予約できる先の日数（1 年まで） */
const ORG_SCHEDULE_MAX_DAYS = 366;

/**
 * 予約した日が来た組織の改訂を反映する。反映した数を返す。
 * 同時に 2 つの要求から動いても 1 回だけ反映するよう、状態を先に「反映済み」へ変えてから反映する。
 */
function org_schedules_run_due(): int
{
    $todayText = date('Y-m-d');
    $appliedCount = 0;
    foreach (db_all("SELECT * FROM org_schedules WHERE status = 'scheduled' AND apply_on <= ? ORDER BY apply_on, id", [$todayText]) as $scheduleRow) {
        $scheduleId = (int) $scheduleRow['id'];
        $claimStatement = db()->prepare("UPDATE org_schedules SET status = 'applied', finished_at = ? WHERE id = ? AND status = 'scheduled'");
        $claimStatement->execute([now(), $scheduleId]);
        if ($claimStatement->rowCount() !== 1) {
            continue;   // ほかの要求が反映した
        }
        try {
            $schedulePlan = org_csv_plan((string) $scheduleRow['csv_text'], (int) $scheduleRow['created_by']);
            if ($schedulePlan['errorTextList']) {
                db_exec("UPDATE org_schedules SET status = 'failed', result_text = ? WHERE id = ?",
                    [implode("\n", $schedulePlan['errorTextList']), $scheduleId]);
                audit_log('admin', 'organization:schedule:' . $scheduleId . ':failed', (int) $scheduleRow['created_by']);
                continue;
            }
            $changedUserCount = org_csv_apply($schedulePlan);
            db_exec('UPDATE org_schedules SET result_text = ? WHERE id = ?', [msg('組織を反映しました（{count} 人）', ['count' => $changedUserCount]), $scheduleId]);
            audit_log('admin', 'organization:schedule:' . $scheduleId . ':applied:' . $changedUserCount, (int) $scheduleRow['created_by']);
            $appliedCount++;
        } catch (Throwable $applyError) {
            db_exec("UPDATE org_schedules SET status = 'failed', result_text = ? WHERE id = ?", [msg('反映の途中で誤りが起きました。もう一度取り込んでください'), $scheduleId]);
            audit_log('admin', 'organization:schedule:' . $scheduleId . ':error', (int) $scheduleRow['created_by']);
        }
    }
    return $appliedCount;
}

/** 予約した日が来た組織の改訂があるか（画面の表示ごとに軽く確かめる） */
function org_schedules_has_due(): bool
{
    return db_val("SELECT 1 FROM org_schedules WHERE status = 'scheduled' AND apply_on <= ? LIMIT 1", [date('Y-m-d')]) !== null;
}
