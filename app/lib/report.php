<?php

declare(strict_types=1);

// ====================================================================
// 月次報告の共通の処理
// ====================================================================

function period_shift(string $period, int $months): string
{
    $d = DateTime::createFromFormat('!Y-m', $period);
    $d->modify(($months >= 0 ? '+' : '') . $months . ' month');
    return $d->format('Y-m');
}

function period_label(string $period): string
{
    return (int) substr($period, 0, 4) . '年' . (int) substr($period, 5, 2) . '月';
}

/**
 * 報告の対象月（詳細設計書 G-03 §4.3）。
 * 既定は前月。課の締め日を過ぎていれば当月。
 */
function report_target_period(array $user, ?int $today = null): string
{
    $ts = $today ?? time();
    $current = date('Y-m', $ts);
    $previous = period_shift($current, -1);
    if ($user['department_id'] === null) {
        return $previous;
    }
    $closeDay = db_val('SELECT close_day FROM departments WHERE id = ?', [(int) $user['department_id']]);
    if ($closeDay === null) {
        return $previous;
    }
    $lastDay = (int) date('t', $ts);
    $close = min((int) $closeDay, $lastDay);
    return ((int) date('j', $ts) <= $close) ? $previous : $current;
}

/** 締め日の日付（表示用）。締め日がなければ null */
function report_close_date(array $user, string $period): ?string
{
    if ($user['department_id'] === null) {
        return null;
    }
    $closeDay = db_val('SELECT close_day FROM departments WHERE id = ?', [(int) $user['department_id']]);
    if ($closeDay === null) {
        return null;
    }
    // 対象月の翌月の締め日まで（前月分を月初に報告する運用）
    $next = period_shift($period, 1);
    $last = (int) date('t', strtotime($next . '-01'));
    return $next . '-' . str_pad((string) min((int) $closeDay, $last), 2, '0', STR_PAD_LEFT);
}

/** 作成・編集できる月か（当月と過去 3 か月） */
function report_period_editable(string $period): bool
{
    $current = date('Y-m');
    return $period <= $current && $period >= period_shift($current, -3);
}

function report_find(int $id): ?array
{
    return db_one('SELECT * FROM report_records WHERE id = ?', [$id]);
}

function report_find_own(int $userId, string $period): ?array
{
    return db_one('SELECT * FROM report_records WHERE user_id = ? AND period = ? AND is_legacy = 0 AND deleted_at IS NULL', [$userId, $period]);
}

/** 報告の案件ブロック（並び順） */
function report_blocks(int $reportId): array
{
    return db_all(
        'SELECT rp.id, rp.project_id, rp.sort_order, p.code, p.alias, p.is_active
           FROM report_projects rp JOIN projects p ON p.id = rp.project_id
          WHERE rp.report_id = ? ORDER BY rp.sort_order, rp.id',
        [$reportId]
    );
}

/**
 * 報告の値を復号して返す: [report_project_id][field_id] => ['value' => string|null, 'warning' => bool]
 * 復号できない値は 'value' => null。
 */
function report_values(int $reportId): array
{
    $out = [];
    foreach (db_all('SELECT * FROM report_field_values WHERE report_id = ?', [$reportId]) as $v) {
        $rp = (int) $v['report_project_id'];
        $fid = (int) $v['field_id'];
        $val = (string) $v['value_text'];
        if ((int) $v['is_encrypted'] === 1) {
            $val = dec($val, form_aad($reportId, $fid, $rp));
        }
        $out[$rp][$fid] = ['value' => $val, 'warning' => (int) $v['has_warning'] === 1];
    }
    return $out;
}

/** 利用者が選べる案件（有効、かつ課の指定がないか同じ課） */
function selectable_projects(?int $deptId): array
{
    return db_all(
        'SELECT id, code, alias FROM projects WHERE is_active = 1 AND (department_id IS NULL OR department_id = ?) ORDER BY code',
        [$deptId ?? 0]
    );
}

/**
 * 前月からの引き継ぎ（G-04 §4.3）。まだ報告がない月の入力画面の初期値を作る。
 * 返り値: ['from' => 報告ID|null, 'blocks' => [project_id...], 'values' => [project_id(0=全体)][field_id] => 値, 'notices' => [...]]
 */
function report_carry_over(int $userId, string $period, array $fields): array
{
    $prev = db_one(
        'SELECT * FROM report_records WHERE user_id = ? AND period < ? AND is_legacy = 0 AND deleted_at IS NULL ORDER BY period DESC LIMIT 1',
        [$userId, $period]
    );
    if ($prev === null) {
        return ['from' => null, 'blocks' => [], 'values' => [], 'notices' => []];
    }
    return report_copy_values((int) $prev['id'], $fields, true, msg('前月の案件 {code} は終了したため外しました'));
}

/**
 * 写す元にできる自分の報告（G-04 §4.4）。移行データ・削除済み・対象の月の報告は除く。新しい月から 12 件。
 *
 * @return array<int, array{id: int|string, period: string, status: string, projects: array<int, array{id: int|string, code: string, alias: string}>}>
 *         projects は、その報告の案件のうち今も有効なもの（1 案件だけ写すときの選択肢）
 */
function report_copy_sources(int $userId, string $period): array
{
    $sourceReportList = db_all(
        'SELECT id, period, status FROM report_records
          WHERE user_id = ? AND period <> ? AND is_legacy = 0 AND deleted_at IS NULL
          ORDER BY period DESC LIMIT 12',
        [$userId, $period]
    );
    foreach ($sourceReportList as $sourceIndex => $sourceReport) {
        $sourceReportList[$sourceIndex]['projects'] = db_all(
            'SELECT p.id, p.code, p.alias FROM report_projects rp JOIN projects p ON p.id = rp.project_id
              WHERE rp.report_id = ? AND p.is_active = 1 ORDER BY rp.sort_order, rp.id',
            [(int) $sourceReport['id']]
        );
    }
    return $sourceReportList;
}

/**
 * 報告の内容を、今の様式（$fields）の入力の初期値に写す。
 * $onlyCarry が true なら carry_over = 1 の項目だけ（前月からの引き継ぎ）、false ならすべての項目（過去の報告から写す）。
 * 無効になった案件は写さず、$endedProjectNotice（{code} に案件コードを入れる）を notices に入れる。
 */
function report_copy_values(int $sourceId, array $fields, bool $onlyCarry, string $endedProjectNotice): array
{
    $result = ['from' => $sourceId, 'blocks' => [], 'values' => [], 'notices' => []];
    $prevFields = [];
    foreach (form_snapshot_fields($sourceId) as $pf) {
        $prevFields[$pf['id']] = $pf;
    }
    $prevValues = report_values($sourceId);
    $rpToProject = [0 => 0];
    foreach (report_blocks($sourceId) as $b) {
        if ((int) $b['is_active'] !== 1) {
            $result['notices'][] = strtr($endedProjectNotice, ['{code}' => (string) $b['code']]);
            continue;
        }
        $rpToProject[(int) $b['id']] = (int) $b['project_id'];
        $result['blocks'][] = (int) $b['project_id'];
    }
    foreach ($fields as $f) {
        if (($onlyCarry && !$f['carry']) || $f['type'] === 'project' || !isset($prevFields[$f['id']])) {
            continue;
        }
        $pf = $prevFields[$f['id']];
        if ($pf['type'] !== $f['type'] && !($pf['type'] === 'single_text' && $f['type'] === 'multi_text')
            && !(in_array($pf['type'], ['select', 'radio'], true) && in_array($f['type'], ['select', 'radio'], true))) {
            continue;
        }
        foreach ($rpToProject as $rpId => $projectId) {
            if (($f['scope'] === 'report') !== ($rpId === 0)) {
                continue;
            }
            $v = $prevValues[$rpId][$f['id']]['value'] ?? '';
            if ($v === null || $v === '') {
                continue;
            }
            if (in_array($f['type'], ['select', 'radio'], true) && !in_array($v, form_option_codes($f), true)) {
                continue;
            }
            if ($f['type'] === 'multi_check') {
                $arr = json_decode($v, true);
                $arr = array_values(array_intersect(is_array($arr) ? $arr : [], form_option_codes($f)));
                if (!$arr) {
                    continue;
                }
                $v = json_encode($arr, JSON_UNESCAPED_UNICODE);
            }
            $result['values'][$projectId][$f['id']] = $v;
        }
    }
    return $result;
}

/**
 * 前月との比べ（G-04 §4.6・G-05 §4.2）。同じ人の、対象月より前でいちばん新しい報告（移行データ・削除済みを除く）と比べる。
 * 見る人がその報告を見られないとき（下書き・別の課など）は比べない。「限定」の項目は、見る人が限定を見られるときだけ。
 *
 * @param array $viewer       見る人（users の行）
 * @param int   $ownerUserId  報告を書く人
 * @param string $period      対象月（YYYY-MM）
 * @return array{
 *   previousReport: ?array,                               比べる報告（なければ null）
 *   previousPeriodLabel: string,                          「YYYY年M月」
 *   previousFieldById: array<int, array>,                 比べる報告の項目の定義（作成当時）
 *   previousValueByProjectId: array<int, array<int, string>>,  案件 ID（報告全体は 0）=> [項目 ID => 値]
 *   previousProjectCodeById: array<int, string>,          比べる報告の案件 ID => 案件コード
 *   canViewPreviousRestricted: bool,                      比べる報告の「限定」の値を見られるか
 *   isPreviousFreeTextPurged: bool                        比べる報告の自由記述が保管期限で消えているか
 * }
 */
function report_previous_comparison(array $viewer, int $ownerUserId, string $period): array
{
    $comparison = report_no_previous_comparison();
    $previousReport = db_one(
        'SELECT * FROM report_records WHERE user_id = ? AND period < ? AND is_legacy = 0 AND deleted_at IS NULL ORDER BY period DESC LIMIT 1',
        [$ownerUserId, $period]
    );
    if ($previousReport === null || !can_view_report($viewer, $previousReport)) {
        return $comparison;
    }
    $previousReportId = (int) $previousReport['id'];
    $comparison['previousReport'] = $previousReport;
    $comparison['previousPeriodLabel'] = period_label((string) $previousReport['period']);
    $comparison['canViewPreviousRestricted'] = can_view_restricted($viewer, $previousReport);
    $comparison['isPreviousFreeTextPurged'] = $previousReport['free_text_purged_at'] !== null;
    foreach (form_snapshot_fields($previousReportId) as $previousField) {
        $comparison['previousFieldById'][(int) $previousField['id']] = $previousField;
    }
    $projectIdByReportProjectId = [0 => 0];
    foreach (report_blocks($previousReportId) as $previousBlock) {
        $projectIdByReportProjectId[(int) $previousBlock['id']] = (int) $previousBlock['project_id'];
        $comparison['previousProjectCodeById'][(int) $previousBlock['project_id']] = (string) $previousBlock['code'];
        $comparison['previousValueByProjectId'][(int) $previousBlock['project_id']] = [];
    }
    $comparison['previousValueByProjectId'][0] = [];
    foreach (report_values($previousReportId) as $reportProjectId => $valueByFieldId) {
        if (!isset($projectIdByReportProjectId[$reportProjectId])) {
            continue;
        }
        foreach ($valueByFieldId as $fieldId => $storedValue) {
            $comparison['previousValueByProjectId'][$projectIdByReportProjectId[$reportProjectId]][(int) $fieldId] = (string) ($storedValue['value'] ?? '');
        }
    }
    return $comparison;
}

/** 比べないときの report_previous_comparison() の戻り値（移行データの報告など） */
function report_no_previous_comparison(): array
{
    return [
        'previousReport' => null, 'previousPeriodLabel' => '', 'previousFieldById' => [], 'previousValueByProjectId' => [],
        'previousProjectCodeById' => [], 'canViewPreviousRestricted' => false, 'isPreviousFreeTextPurged' => false,
    ];
}

/**
 * 前月の値の表示（「前月: …」の 1 行）。比べられない・同じ値なら空文字。
 *
 * @param array  $comparison   report_previous_comparison() の戻り値
 * @param array  $field        今の報告の項目の定義
 * @param int    $projectId    案件 ID（報告全体は 0）
 * @param string $currentValue 今の値
 * @return string HTML（エスケープ済み）
 */
function report_previous_value_html(array $comparison, array $field, int $projectId, string $currentValue): string
{
    if ($comparison['previousReport'] === null || !isset($comparison['previousValueByProjectId'][$projectId])) {
        return '';
    }
    $fieldId = (int) $field['id'];
    $previousField = $comparison['previousFieldById'][$fieldId] ?? null;
    if ($previousField === null) {
        return '';
    }
    if ($previousField['conf'] === 'restricted' && !$comparison['canViewPreviousRestricted']) {
        return '';
    }
    if ($comparison['isPreviousFreeTextPurged'] && form_is_free_text($previousField)) {
        return '';
    }
    $previousValue = $comparison['previousValueByProjectId'][$projectId][$fieldId] ?? '';
    if ($previousValue === $currentValue) {
        return '';
    }
    $previousDisplayHtml = $previousValue === '' ? '<span class="muted">' . h(msg('（空）')) . '</span>' : form_display_html($previousField, $previousValue);
    return '<div class="previous-value"><span class="tag tag-changed">' . h(msg('前月から変更')) . '</span> '
        . h(msg('{period}:', ['period' => $comparison['previousPeriodLabel']])) . ' ' . $previousDisplayHtml . '</div>';
}

/** G-04 の画面を出す（画面を開いたときと、入力エラーで戻すときに使う） */
function report_edit_render(array $user, string $period, ?array $report, array $fields, array $state, array $errors, array $notices = []): void
{
    $deptId = $report !== null ? ($report['department_id'] === null ? null : (int) $report['department_id']) : (int) $user['department_id'];
    $projects = [];
    foreach (selectable_projects($deptId) as $p) {
        $projects[(int) $p['id']] = $p;
    }
    // 報告にすでにある案件は、終了していても選べるようにする
    foreach ($state['blocks'] as $b) {
        $pid = (int) $b['project_id'];
        if ($pid > 0 && !isset($projects[$pid])) {
            $p = db_one('SELECT id, code, alias FROM projects WHERE id = ?', [$pid]);
            if ($p !== null) {
                $projects[$pid] = $p;
            }
        }
    }
    $isDraft = $report === null || $report['status'] === 'draft';
    render('report_edit', [
        'copySources' => $isDraft ? report_copy_sources((int) $user['id'], $period) : [],
        'previousComparison' => report_previous_comparison($user, (int) $user['id'], $period),
        'title'    => period_label($period) . 'の報告',
        'user'     => $user,
        'period'   => $period,
        'report'   => $report,
        'fields'   => $fields,
        'state'    => $state,
        'errors'   => $errors,
        'notices'  => $notices,
        'projects' => $projects,
        'words'    => warning_words(),
    ]);
}

function report_status_label(?string $status): string
{
    $map = ['draft' => '下書き', 'submitted' => '提出済み', 'confirmed' => '確認完了'];
    return $status === null ? '未作成' : ($map[$status] ?? $status);
}

/**
 * 報告の状態の札（状態ごとに色を分ける。APP-017）。未作成＝灰の点線、下書き＝青、提出済み＝緑、確認完了＝緑の塗り。
 */
function report_status_tag_html(?string $status): string
{
    $statusClassName = $status === null ? 'none' : (in_array($status, ['draft', 'submitted', 'confirmed'], true) ? $status : 'none');
    return '<span class="tag status-tag status-' . $statusClassName . '">' . h(report_status_label($status)) . '</span>';
}

/**
 * コメントの定型文（G-19）。課ごとに、並び順で返す。
 *
 * @return array<int, array{id: int|string, body: string, sort_order: int|string}>
 */
function comment_templates_of(int $departmentId): array
{
    return db_all('SELECT id, body, sort_order FROM comment_templates WHERE department_id = ? ORDER BY sort_order, id', [$departmentId]);
}

/**
 * 定型文の表の名前（範囲 'department' は課の定型文、'mine' は自分の定型文）。
 */
function comment_template_table_name(string $templateScope): string
{
    return $templateScope === 'department' ? 'comment_templates' : 'personal_comment_templates';
}

/**
 * 範囲ごとの定型文（課の定型文は課 ID、自分の定型文は利用者 ID で絞る）。並び順。
 *
 * @return array<int, array{id: int|string, body: string, sort_order: int|string}>
 */
function comment_template_rows(string $templateScope, int $ownerId): array
{
    if ($templateScope === 'department') {
        return comment_templates_of($ownerId);
    }
    return db_all('SELECT id, body, sort_order FROM personal_comment_templates WHERE user_id = ? ORDER BY sort_order, id', [$ownerId]);
}

/** 定型文を足す。戻り値は新しい ID */
function comment_template_insert(string $templateScope, int $ownerId, string $bodyText, int $sortOrder, int $operatorUserId): int
{
    if ($templateScope === 'department') {
        db_exec('INSERT INTO comment_templates (department_id, body, sort_order, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$ownerId, $bodyText, $sortOrder, $operatorUserId, now(), now()]);
    } else {
        db_exec('INSERT INTO personal_comment_templates (user_id, body, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$ownerId, $bodyText, $sortOrder, now(), now()]);
    }
    return (int) db()->lastInsertId();
}

/**
 * 定型文を管理できる課（その課の課長＝is_final = 1 の確認者。有効な課だけ）。
 *
 * @return array<int, array> 課 ID => departments の行
 */
function comment_template_manageable_departments(array $user): array
{
    $departmentById = [];
    foreach (db_all(
        'SELECT d.* FROM departments d JOIN department_reviewers r ON r.department_id = d.id
          WHERE r.user_id = ? AND r.is_final = 1 AND d.is_active = 1 ORDER BY d.sort_order, d.id',
        [(int) $user['id']]
    ) as $departmentRow) {
        $departmentById[(int) $departmentRow['id']] = $departmentRow;
    }
    return $departmentById;
}
