<?php

declare(strict_types=1);

// ====================================================================
// 権限の判定（詳細設計書 共通編 §10）
// ====================================================================

/** システム管理者か（補佐は含まない） */
function is_admin(array $user): bool
{
    return (int) $user['is_admin'] === 1;
}

/** システム管理者補佐か（G-14 §6.9。管理者が対応できないときの代行。個々の情報は見られない） */
function is_admin_deputy(array $user): bool
{
    return (int) ($user['is_admin_deputy'] ?? 0) === 1;
}

/** システム全体の管理（課・ユーザー・組織の改訂・項目・案件・注意の言葉）ができるか: 管理者と補佐 */
function can_manage_system(array $user): bool
{
    return is_admin($user) || is_admin_deputy($user);
}

/** 自分が確認者の課（課ID => is_final） */
function reviewer_departments(int $userId): array
{
    $out = [];
    foreach (db_all('SELECT department_id, is_final FROM department_reviewers WHERE user_id = ?', [$userId]) as $r) {
        $out[(int) $r['department_id']] = (int) $r['is_final'] === 1;
    }
    return $out;
}

function is_reviewer_of(int $userId, int $departmentId): bool
{
    return db_val('SELECT 1 FROM department_reviewers WHERE user_id = ? AND department_id = ?', [$userId, $departmentId]) !== null;
}

/** その課でチェックする確認者か（課長は常に。課代・リーダーは課長が決める。K-039） */
function reviewer_does_check(int $userId, int $departmentId): bool
{
    return db_val('SELECT 1 FROM department_reviewers WHERE user_id = ? AND department_id = ? AND (does_check = 1 OR is_final = 1)', [$userId, $departmentId]) !== null;
}

/**
 * 新しく確認者にしたときの「チェックする」の既定（K-039）: 課長と、役職が課長代理の人は「する」、ほか（リーダーなど）は「しない」。
 */
function reviewer_default_does_check(int $userId, bool $isFinal): int
{
    if ($isFinal) {
        return 1;
    }
    return (string) db_val('SELECT role_title FROM users WHERE id = ?', [$userId]) === '課長代理' ? 1 : 0;
}

function is_final_reviewer_of(int $userId, int $departmentId): bool
{
    return db_val('SELECT 1 FROM department_reviewers WHERE user_id = ? AND department_id = ? AND is_final = 1', [$userId, $departmentId]) !== null;
}

/** 課別項目を管理できるか（課の項目管理者、課長、管理者） */
function can_manage_fields_of(array $user, int $departmentId): bool
{
    if (can_manage_system($user)) {
        return true;
    }
    $uid = (int) $user['id'];
    if (is_final_reviewer_of($uid, $departmentId)) {
        return true;
    }
    return db_val('SELECT 1 FROM department_admins WHERE user_id = ? AND department_id = ?', [$uid, $departmentId]) !== null;
}

/**
 * 課と、その課が引き継いだ改訂前の課の ID（検索などで、改訂前の報告も含めるため）。
 *
 * @return int[]
 */
function department_with_predecessor_ids(int $departmentId): array
{
    $departmentIdSet = [$departmentId => true];
    $pendingDepartmentIdList = [$departmentId];
    while ($pendingDepartmentIdList) {
        $successorDepartmentId = array_pop($pendingDepartmentIdList);
        foreach (db_all('SELECT predecessor_department_id FROM department_successions WHERE successor_department_id = ?', [$successorDepartmentId]) as $successionRow) {
            $predecessorDepartmentId = (int) $successionRow['predecessor_department_id'];
            if (!isset($departmentIdSet[$predecessorDepartmentId])) {
                $departmentIdSet[$predecessorDepartmentId] = true;
                $pendingDepartmentIdList[] = $predecessorDepartmentId;
            }
        }
    }
    return array_keys($departmentIdSet);
}

/**
 * 確認者としての見る権限の一覧（確認者の設定 1 行ごと）。1 回の要求の中では覚えておく。
 *
 * @return array<int, array{departmentId: int, isFinal: bool, teamId: ?int, isTeamScoped: bool, coveredDepartmentIdSet: array<int, true>}>
 *   coveredDepartmentIdSet: その課と、組織の改訂で引き継いだ前の課（G-14 §6.6）
 *   isTeamScoped: 「班ごと」の課で、課長でなく担当の班があるとき（その班の人の報告だけを見る。K-025）
 */
function report_view_grants(int $userId): array
{
    static $grantListByUserId = [];
    if (isset($grantListByUserId[$userId])) {
        return $grantListByUserId[$userId];
    }
    $grantList = [];
    foreach (db_all(
        'SELECT r.department_id, r.is_final, r.team_id, d.review_scope
           FROM department_reviewers r JOIN departments d ON d.id = r.department_id
          WHERE r.user_id = ?',
        [$userId]
    ) as $reviewerRow) {
        $isFinal = (int) $reviewerRow['is_final'] === 1;
        $teamId = $reviewerRow['team_id'] === null ? null : (int) $reviewerRow['team_id'];
        $grantList[] = [
            'departmentId'           => (int) $reviewerRow['department_id'],
            'isFinal'                => $isFinal,
            'teamId'                 => $teamId,
            'isTeamScoped'           => $reviewerRow['review_scope'] === 'team' && !$isFinal && $teamId !== null,
            'coveredDepartmentIdSet' => array_fill_keys(department_with_predecessor_ids((int) $reviewerRow['department_id']), true),
        ];
    }
    $grantListByUserId[$userId] = $grantList;
    return $grantList;
}

/**
 * 利用者の今の課と班（報告は人について回る。K-032・K-033）。1 回の要求の中では覚えておく。
 *
 * @return array{departmentId: ?int, teamId: ?int}
 */
function user_current_placement(int $userId): array
{
    static $placementByUserId = [];
    if (!isset($placementByUserId[$userId])) {
        $userRow = db_one('SELECT department_id, team_id FROM users WHERE id = ?', [$userId]);
        $placementByUserId[$userId] = [
            'departmentId' => $userRow === null || $userRow['department_id'] === null ? null : (int) $userRow['department_id'],
            'teamId'       => $userRow === null || $userRow['team_id'] === null ? null : (int) $userRow['team_id'],
        ];
    }
    return $placementByUserId[$userId];
}

/**
 * 確認者として、この報告を見られるか。
 * 報告の課（作成時の課）か、本人の今の課（K-032）を、その課かそれを引き継いだ課の確認者として見られるとき。
 * 「班ごと」の課で担当の班がある確認者は、本人の今の班がその班のときだけ（K-033）。課長は班に関係なく見る。
 *
 * @param bool $isFinalRequired true なら課長として（「限定」を見られるか）
 * @param bool $isOwnerDepartmentIncluded false なら、報告の課（作成時の課）からだけ判定する（チェックの人数を数えるとき）
 */
function reviewer_can_see_report(int $viewerUserId, array $report, bool $isFinalRequired, bool $isOwnerDepartmentIncluded = true): bool
{
    $ownerPlacement = user_current_placement((int) $report['user_id']);
    $relevantDepartmentIdSet = [];
    if ($report['department_id'] !== null) {
        $relevantDepartmentIdSet[(int) $report['department_id']] = true;
    }
    if ($isOwnerDepartmentIncluded && $ownerPlacement['departmentId'] !== null) {
        $relevantDepartmentIdSet[$ownerPlacement['departmentId']] = true;
    }
    foreach (report_view_grants($viewerUserId) as $grant) {
        if ($isFinalRequired && !$grant['isFinal']) {
            continue;
        }
        if (!array_intersect_key($grant['coveredDepartmentIdSet'], $relevantDepartmentIdSet)) {
            continue;
        }
        if ($grant['isTeamScoped'] && $ownerPlacement['teamId'] !== $grant['teamId']) {
            continue;
        }
        return true;
    }
    return false;
}

/**
 * 報告を見られるか（共通編 §10.2）。本人と、確認者として見られる人（reviewer_can_see_report()）だけ。
 * 管理者でも、確認者でない課の報告は見られない（管理の権限と報告を見る権限は分ける）。
 * 下書きは本人だけ。削除済みは見られない。
 */
function can_view_report(array $user, array $report): bool
{
    if ($report['deleted_at'] !== null) {
        return false;
    }
    $uid = (int) $user['id'];
    if ((int) $report['user_id'] === $uid) {
        return true;
    }
    if ($report['status'] === 'draft') {
        return false;
    }
    return reviewer_can_see_report($uid, $report, false);
}

/** 「限定」の項目を見られるか（本人と、課長として報告を見られる人だけ。管理者でも課長でなければ見られない） */
function can_view_restricted(array $user, array $report): bool
{
    $uid = (int) $user['id'];
    if ((int) $report['user_id'] === $uid) {
        return true;
    }
    return reviewer_can_see_report($uid, $report, true);
}

/**
 * チェックを求める確認者（「チェック n/m」の m）。報告の課（作成時の課。引き継ぎを含む）の、有効な確認者のうち、
 * その報告を見られる人（班ごとの課では担当の班の人と、課全体の確認者・課長）。本人は除く。
 *
 * @return int[] 利用者 ID
 */
function report_expected_reviewer_ids(array $report): array
{
    $expectedReviewerIdList = [];
    // 「チェックしない」の課代・リーダーは数えない（K-039。課長は常にチェックする）
    foreach (db_all('SELECT DISTINCT r.user_id FROM department_reviewers r JOIN users u ON u.id = r.user_id AND u.is_active = 1
                      WHERE r.user_id <> ? AND (r.does_check = 1 OR r.is_final = 1)', [(int) $report['user_id']]) as $reviewerRow) {
        if (reviewer_can_see_report((int) $reviewerRow['user_id'], $report, false, false)) {
            $expectedReviewerIdList[] = (int) $reviewerRow['user_id'];
        }
    }
    return $expectedReviewerIdList;
}

/**
 * 報告のチェックの数（「チェック n/m」）。n は今の revision でチェック済みの、チェックを求める確認者の数。
 *
 * @return array{checked: int, expected: int}
 */
function report_check_counts(array $report): array
{
    $expectedReviewerIdList = report_expected_reviewer_ids($report);
    $checkedCount = 0;
    if ($expectedReviewerIdList) {
        $checkedUserIdSet = array_fill_keys(array_map('intval', array_column(
            db_all('SELECT user_id FROM report_reviews WHERE report_id = ? AND checked_revision >= ?', [(int) $report['id'], (int) $report['revision']]),
            'user_id'
        )), true);
        foreach ($expectedReviewerIdList as $expectedReviewerId) {
            $checkedCount += isset($checkedUserIdSet[$expectedReviewerId]) ? 1 : 0;
        }
    }
    return ['checked' => $checkedCount, 'expected' => count($expectedReviewerIdList)];
}

/**
 * 報告の中身を見られる課（課ID => 課の行）。確認者（課長を含む）の課だけ。管理者でも確認者でなければ含まない。
 * 横並び（G-09）・検索（G-20）などの中身を出す画面で使う。
 */
function reviewable_departments(array $user): array
{
    $out = [];
    foreach (db_all(
        'SELECT d.* FROM departments d JOIN department_reviewers r ON r.department_id = d.id
          WHERE r.user_id = ? AND d.is_active = 1 ORDER BY d.sort_order, d.id',
        [(int) $user['id']]
    ) as $d) {
        $out[(int) $d['id']] = $d;
    }
    return $out;
}

/** 課長として扱う課（課ID => 課の行）。最終確認者の課だけ（管理者でも課長でなければ含まない） */
function final_departments(array $user): array
{
    $out = [];
    foreach (reviewable_departments($user) as $id => $d) {
        if (is_final_reviewer_of((int) $user['id'], $id)) {
            $out[$id] = $d;
        }
    }
    return $out;
}

/**
 * 提出状況（G-08）を見られる課（課ID => 課の行）。確認者の課と、管理者は全課。
 * 提出状況は中身を出さない（提出したか・チェックの数だけ）ので、管理者は運用のために全課を見られる。
 */
function status_departments(array $user): array
{
    if (!can_manage_system($user)) {
        return reviewable_departments($user);
    }
    $departmentById = [];
    foreach (db_all('SELECT * FROM departments WHERE is_active = 1 ORDER BY sort_order, id') as $departmentRow) {
        $departmentById[(int) $departmentRow['id']] = $departmentRow;
    }
    return $departmentById;
}

/** メニューに出す項目 */
/**
 * メニューの項目。見出し（報告・確認・管理・アカウント）ごとにまとめる。
 *
 * @return array<int, array{0: string, 1: string, 2: string}> [パス, 表示名, 見出し]
 */
function menu_items(array $user): array
{
    $userId = (int) $user['id'];
    $isAdmin = can_manage_system($user);   // 管理者と補佐（補佐は画面の中で個々の情報を出さない）
    $isFinalByReviewerDepartmentId = reviewer_departments($userId);   // 課 ID => 課長か
    $isReviewer = (bool) $isFinalByReviewerDepartmentId;
    $isFinalReviewer = in_array(true, $isFinalByReviewerDepartmentId, true);
    $items = [
        ['/index.php', 'ホーム', '報告'],
        ['/reports/mine.php', '自分の報告', '報告'],
        ['/reports/comments.php', 'コメント', '報告'],
        ['/reports/search.php', '検索', '報告'],
    ];
    if ($isReviewer || $isAdmin) {
        $items[] = ['/status/index.php', '提出状況', '確認'];
    }
    if ($isFinalReviewer) {
        $items[] = ['/surveys/manage.php', '臨時の調査', '確認'];
        $items[] = ['/export/index.php', '出力', '確認'];
    }
    if ($isFinalReviewer) {
        $items[] = ['/admin/department.php', '課の管理', '管理'];   // 課長が自分の課を管理する（G-23。K-042）
    }
    if ($isAdmin || $isFinalReviewer || db_val('SELECT 1 FROM department_admins WHERE user_id = ?', [$userId]) !== null) {
        $items[] = ['/admin/fields.php', '項目マスタ', '管理'];
    }
    if ($isReviewer || comment_template_manageable_departments($user)) {
        $items[] = ['/admin/comment_templates.php', 'コメントの定型文', '管理'];
    }
    if (msg_can_edit($user)) {
        $items[] = ['/admin/messages.php', 'メッセージ', '管理'];
    }
    if ($isAdmin) {
        $items[] = ['/requests/index.php', '要望・依頼', '管理'];   // 管理者・補佐: 受けて対応する（G-22）
    } elseif ($isReviewer) {
        $items[] = ['/requests/index.php', '要望・依頼', '確認'];   // 課長・確認者: システム管理者へ出す（G-22）
    }
    if ($isAdmin) {
        $items[] = ['/admin/notices.php', '更新のお知らせ', '管理'];   // 便利機能 A
        $items[] = ['/admin/projects.php', '案件の管理', '管理'];
        $items[] = ['/admin/org.php', '組織・ユーザー', '管理'];
        $items[] = ['/admin/logs.php', '記録・削除データ', '管理'];
    }
    // 管理の画面が 1 つでもあれば、管理のトップ（G-21）を管理の先頭に置く
    $firstAdminItemIndex = null;
    foreach ($items as $itemIndex => $menuItem) {
        if ($menuItem[2] === '管理') {
            $firstAdminItemIndex = $itemIndex;
            break;
        }
    }
    if ($firstAdminItemIndex !== null) {
        array_splice($items, $firstAdminItemIndex, 0, [['/admin/index.php', '管理のトップ', '管理']]);
    }
    $items[] = ['/faq.php', 'よくある質問', 'アカウント'];   // 便利機能 J（全員）
    $items[] = ['/account.php', 'アカウント設定', 'アカウント'];
    return $items;
}
