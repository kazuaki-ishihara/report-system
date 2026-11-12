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

function report_status_label(?string $status): string
{
    $map = ['draft' => '下書き', 'submitted' => '提出済み', 'confirmed' => '確認完了'];
    return $status === null ? '未作成' : ($map[$status] ?? $status);
}
