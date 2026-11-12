<?php

declare(strict_types=1);

// ====================================================================
// G-15 記録の閲覧（詳細設計書 G15_logs.md）。CSV 出力は G-10 と同じ形式。
// ====================================================================

$user = page_start();
require_admin($user);

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
if ($from > $to || strtotime($to) - strtotime($from) > 366 * 86400) {
    error_page(400, '期間は 1 年以内にしてください。');
}
if ($kind !== '' && !in_array($kind, ['view', 'export', 'admin', 'auth'], true)) {
    error_page(400);
}
$esc = function (string $s): string { return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%'; };
$where = ['l.created_at >= ?', 'l.created_at < ?'];
$params = [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
if ($kind !== '') {
    $where[] = 'l.action = ?';
    $params[] = $kind;
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
        error_page(403, '出力は PC から行ってください。');
    }
    if ($count > 10000) {
        error_page(400, '該当が 10,000 件を超えています。条件を絞ってください。');
    }
    $rows = [];
    foreach (db_all('SELECT l.created_at, u.login_id, l.action, l.target, l.ip_address ' . $sql . ' ORDER BY l.id DESC', $params) as $r) {
        $rows[] = [$r['created_at'], (string) $r['login_id'], $r['action'], $r['target'], $r['ip_address']];
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
]);
