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
    $result = ['from' => null, 'blocks' => [], 'values' => [], 'notices' => []];
    $prev = db_one(
        'SELECT * FROM report_records WHERE user_id = ? AND period < ? AND is_legacy = 0 AND deleted_at IS NULL ORDER BY period DESC LIMIT 1',
        [$userId, $period]
    );
    if ($prev === null) {
        return $result;
    }
    $result['from'] = (int) $prev['id'];
    $prevFields = [];
    foreach (form_snapshot_fields((int) $prev['id']) as $pf) {
        $prevFields[$pf['id']] = $pf;
    }
    $prevValues = report_values((int) $prev['id']);
    $rpToProject = [0 => 0];
    foreach (report_blocks((int) $prev['id']) as $b) {
        if ((int) $b['is_active'] !== 1) {
            $result['notices'][] = '前月の案件 ' . $b['code'] . ' は終了したため外しました';
            continue;
        }
        $rpToProject[(int) $b['id']] = (int) $b['project_id'];
        $result['blocks'][] = (int) $b['project_id'];
    }
    foreach ($fields as $f) {
        if (!$f['carry'] || $f['type'] === 'project' || !isset($prevFields[$f['id']])) {
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
    render('report_edit', [
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
