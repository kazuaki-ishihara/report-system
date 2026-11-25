<?php

declare(strict_types=1);

// ====================================================================
// ホームに本人だけに出す「あなたの役割と権限」（詳細設計書 G03_home.md §4.4）
// 権限の文は、画面の制限に使う判定（perm.php・survey.php など）から作る。役職だけからは決めない
// ====================================================================

/**
 * 役割の札。役職（一般・主任・課長代理・課長・部長）のあとに、システムの役割・人財管理者・部長以上の印。
 *
 * @return string[]
 */
function role_summary_label_list(array $user): array
{
    $specialistTitle = specialist_title_of((int) $user['id']);
    $labelList = [display_role_title((int) $user['id'], (string) ($user['role_title'] ?? '')), $specialistTitle !== '' ? '専門職' : '', system_role_label($user)];
    $labelList[] = is_hr_manager($user) ? '人財管理者' : '';
    $labelList[] = is_executive($user) ? '部長以上' : '';
    return array_values(array_filter($labelList, function (string $label): bool {
        return $label !== '';
    }));
}

/**
 * 課ごとの担当（課長・確認者）。
 *
 * @return array<int, array{departmentId: int, departmentName: string, isActive: bool, isFinal: bool, teamName: string, doesCheck: bool}>
 */
function role_summary_duty_list(int $userId): array
{
    $dutyList = [];
    foreach (report_view_grants($userId) as $grant) {
        $departmentRow = db_one('SELECT name, is_active FROM departments WHERE id = ?', [$grant['departmentId']]);
        if ($departmentRow === null) {
            continue;
        }
        $dutyList[] = [
            'departmentId'   => $grant['departmentId'],
            'departmentName' => (string) $departmentRow['name'],
            'isActive'       => (int) $departmentRow['is_active'] === 1,
            'isFinal'        => $grant['isFinal'],
            // 「ラインごと」の課で担当のラインがある確認者だけ、ラインの名前を出す（見える範囲と同じ）
            'teamName'       => $grant['isTeamScoped'] ? (string) db_val('SELECT name FROM department_teams WHERE id = ?', [$grant['teamId']]) : '',
            'doesCheck'      => reviewer_does_check($userId, $grant['departmentId']),
        ];
    }
    return $dutyList;
}

/** 課ごとの担当の 1 行（例: 「A課（課長）」「A課（確認者・山田ライン）」） */
function role_summary_duty_text(array $duty): string
{
    return $duty['departmentName'] . '（' . ($duty['isFinal'] ? '課長' : '確認者')
        . ($duty['teamName'] !== '' ? '・' . $duty['teamName'] : '') . ($duty['isActive'] ? '' : '・無効の課') . '）';
}

/**
 * できることの一覧。
 *
 * @return string[]
 */
function role_summary_permission_list(array $user, array $dutyList): array
{
    $permissionList = [];
    if ($user['department_id'] !== null) {
        $permissionList[] = '自分の月次報告を作って提出する' . (specialist_title_of((int) $user['id']) === SPECIALIST_HIDDEN_TITLE ? '（ほかの人には見えず、確認の対象にならない）' : '');
    }
    foreach ($dutyList as $duty) {
        $departmentPrefix = $duty['departmentName'] . ': ';
        $rangeText = $duty['teamName'] !== '' ? '担当のライン（' . $duty['teamName'] . '）' : '課の全員';
        if ($duty['isFinal']) {
            $permissionList[] = $departmentPrefix . $rangeText . 'の報告を見る（「限定」の項目を含む）';
            if ($duty['isActive']) {
                $permissionList[] = $departmentPrefix . '報告の確認を完了にする・出力する・課の臨時の調査を作る・課の管理をする';
            }
        } else {
            $permissionList[] = $departmentPrefix . $rangeText . 'の報告を見てコメントする' . ($duty['doesCheck'] ? '・チェックする' : '（チェックはしない設定）');
        }
        if ($duty['isFinal'] && $duty['isActive'] && function_exists('goal_viewer_role')) {
            $permissionList[] = '目標管理: ' . $duty['departmentName'] . 'の人のシートを見て、上長評価を入れる';
        }
    }
    if (function_exists('goal_viewer_role') && (string) ($user['role_title'] ?? '') === '課長代理' && specialist_title_of((int) $user['id']) === '' && $user['department_id'] !== null
        && !is_final_reviewer_of((int) $user['id'], (int) $user['department_id'])) {
        $permissionList[] = '目標管理: 同じ課の一般・主任の人のシートを見る';
    }
    foreach (db_all(
        'SELECT d.name FROM department_admins a JOIN departments d ON d.id = a.department_id
          WHERE a.user_id = ? AND d.is_active = 1 ORDER BY d.sort_order, d.id',
        [(int) $user['id']]
    ) as $fieldAdminRow) {
        $permissionList[] = $fieldAdminRow['name'] . ': 課の項目を変更する（項目管理者）';
    }
    if (survey_can_manage_all_departments($user)) {
        $permissionList[] = '全課共通の臨時の調査を作り、結果（集計だけ）を見て出力する';
    } elseif (is_executive($user)) {
        $permissionList[] = '全課共通の臨時の調査の結果（集計だけ）を見て出力する';
    } elseif (survey_has_result_viewing_menu($user)) {
        $permissionList[] = '指定された全課共通の臨時の調査の結果（集計だけ）を見て出力する';
    }
    if (has_admin_authority($user)) {
        $permissionList[] = 'システム全体の管理（課・ユーザー・項目・案件・記録・削除データなど）をする。報告の中身は、確認者の課の分だけ見る';
    } elseif (is_admin_deputy($user)) {
        $permissionList[] = 'システム管理者の管理の仕事を代行する（確認者でない課の報告の中身などの、個々の情報は見ない）';
    }
    return $permissionList;
}
