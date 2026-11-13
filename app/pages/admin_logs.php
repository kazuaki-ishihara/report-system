<?php

declare(strict_types=1);

// ====================================================================
// G-15 記録の閲覧（詳細設計書 G15_logs.md）。CSV 出力は G-10 と同じ形式。
// ====================================================================

$user = page_start();
require_system_manager($user);

$from = (string) ($_GET['from'] ?? date('Y-m-d', strtotime('-7 days')));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
$kind = (string) ($_GET['kind'] ?? '');
$who = trim((string) ($_GET['who'] ?? ''));
$target = trim((string) ($_GET['target'] ?? ''));
foreach ([$from, $to] as $d) {
    if (DateTime::createFromFormat('!Y-m-d', $d) === false) {
        error_page(400);
    }
}
if ($from > $to) {
    error_page(400, msg('期間の開始は、終了と同じ日かそれより前にしてください。'));
}
if (strtotime($to) - strtotime($from) > 366 * 86400) {
    error_page(400, msg('期間は 1 年以内にしてください。'));
}
// 補佐は「だれがどの報告を見た・出力した」は見られない（ログインと管理の操作の記録だけ。K-043）
$isOperatorDeputy = !is_admin($user);
$allowedKindList = $isOperatorDeputy ? ['admin', 'auth', 'auth_fail'] : ['view', 'export', 'admin', 'auth', 'auth_fail'];
if ($kind !== '' && !in_array($kind, $allowedKindList, true)) {
    error_page($isOperatorDeputy ? 403 : 400);
}
$esc = function (string $s): string { return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%'; };
$where = ['l.created_at >= ?', 'l.created_at < ?'];
$params = [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
if ($kind === 'auth_fail') {
    // ログインの失敗（パスワード・2 段階認証の誤り、ロック、ロック中の試行）。K-041
    $where[] = "l.action = 'auth' AND l.target LIKE 'login:%' AND l.target <> 'login:ok'";
} elseif ($kind !== '') {
    $where[] = 'l.action = ?';
    $params[] = $kind;
} elseif ($isOperatorDeputy) {
    $where[] = "l.action IN ('admin', 'auth')";
}
if ($who !== '') {
    $where[] = "u.login_id LIKE ? ESCAPE '\\'";
    $params[] = $esc($who);
}
if ($target !== '') {
    $where[] = "l.target LIKE ? ESCAPE '\\'";
    $params[] = $esc($target);
}
$sql = 'FROM access_logs l LEFT JOIN users u ON u.id = l.user_id WHERE ' . implode(' AND ', $where);
$count = (int) db_val('SELECT COUNT(*) ' . $sql, $params);

if (($_GET['csv'] ?? '') === '1') {
    if (is_mobile_request()) {
        error_page(403, msg('出力は PC から行ってください。'));
    }
    if ($count > 10000) {
        error_page(400, msg('該当が 10,000 件を超えています。条件を絞ってください。'));
    }
    $rows = [];
    foreach (db_all('SELECT l.created_at, u.login_id, l.action, l.target, l.ip_address, l.acted_as_deputy ' . $sql . ' ORDER BY l.id DESC', $params) as $r) {
        $rows[] = [$r['created_at'], (string) $r['login_id'] . ((int) $r['acted_as_deputy'] === 1 ? '（補佐）' : ''), $r['action'], $r['target'], $r['ip_address']];
    }
    audit_log('export', 'export:logs;from=' . $from . ';to=' . $to);
    $body = export_csv(['社外秘', '記録 ' . $from . '〜' . $to, '出力者: ' . $user['login_id'] . '  出力日時: ' . date('Y-m-d H:i')], ['日時', '利用者', '種類', '対象', 'IP アドレス'], $rows);
    send_security_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="logs_' . date('Ymd-Hi') . '.csv"');
    echo $body;
    exit;
}

$page = isset($_GET['page']) ? input_id('page') : 1;
$rows = db_all('SELECT l.*, u.login_id ' . $sql . ' ORDER BY l.id DESC LIMIT 100 OFFSET ?', array_merge($params, [($page - 1) * 100]));

render('admin_logs', [
    'title' => '記録', 'user' => $user, 'rows' => $rows, 'count' => $count, 'page' => $page,
    'q' => ['from' => $from, 'to' => $to, 'kind' => $kind, 'who' => $who, 'target' => $target],
    'isOperatorDeputy' => $isOperatorDeputy,
]);
