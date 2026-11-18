<?php

declare(strict_types=1);

// ====================================================================
// 報告の提出のお知らせ（画面の中だけ。メールは使わない。共通編 §11・G-08 §6.3）
// - 締め日が近い（3 日前から）・過ぎたのに今月の報告を出していない人に、どの画面でも上に知らせる
// - 課長・確認者が、提出状況（G-08）で未提出の人に提出を促す。促された人は、その月の報告を出すまで、どの画面でも上に知らせが出る
// ====================================================================

/** 締め日の何日前から知らせるか */
const REMINDER_CLOSE_SOON_DAYS = 3;
/** 促す知らせを出す対象の月（作成・編集できる月と同じく、当月と過去 3 か月） */
const REMINDER_MONTHS_BACK = 3;

/** 促した記録の表が無ければ作る（本番で migrate.php を流さなくても動くように） */
function reminder_ensure_table(): void
{
    static $isChecked = false;
    if ($isChecked) {
        return;
    }
    $isChecked = true;
    db()->exec(
        'CREATE TABLE IF NOT EXISTS report_reminders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            period TEXT NOT NULL,
            department_id INTEGER NOT NULL REFERENCES departments(id),
            user_id INTEGER NOT NULL REFERENCES users(id),
            sent_by INTEGER NOT NULL REFERENCES users(id),
            created_at TEXT NOT NULL
        )'
    );
    db()->exec('CREATE INDEX IF NOT EXISTS ix_report_reminders_user ON report_reminders (user_id, period)');
}

/** その月の報告を出したか（提出済み・確認完了） */
function reminder_has_submitted(int $userId, string $period): bool
{
    return db_val(
        "SELECT 1 FROM report_records WHERE user_id = ? AND period = ? AND status IN ('submitted', 'confirmed') AND is_legacy = 0 AND deleted_at IS NULL",
        [$userId, $period]
    ) !== null;
}

/**
 * 画面の上に出す、提出のお知らせ（締め日が近い・過ぎた、課長・確認者から促された）
 *
 * @return array<int, array{text: string, url: string}>
 */
function reminder_banner_list(array $user): array
{
    if ($user['department_id'] === null) {
        return [];
    }
    $userId = (int) $user['id'];
    $bannerList = [];
    $editUrlByPeriod = function (string $period): string {
        return url('/reports/edit.php?period=' . rawurlencode($period));
    };

    // 締め日が近い（今月の報告。対象の月は、締め日を過ぎると次の月に切り替わる）
    $targetPeriod = report_target_period($user);
    $closeDate = report_close_date($user, $targetPeriod);
    if ($closeDate !== null && !reminder_has_submitted($userId, $targetPeriod)) {
        $daysLeft = (int) floor((strtotime($closeDate) - strtotime(date('Y-m-d'))) / 86400);
        if ($daysLeft >= 0 && $daysLeft <= REMINDER_CLOSE_SOON_DAYS) {
            $bannerList[] = ['text' => $daysLeft === 0
                ? msg('{period}の報告の締め日は今日（{date}）です。まだ提出していません。', ['period' => period_label($targetPeriod), 'date' => display_date($closeDate)])
                : msg('{period}の報告の締め日は {date} です（あと {days} 日）。まだ提出していません。', ['period' => period_label($targetPeriod), 'date' => display_date($closeDate), 'days' => $daysLeft]),
                'url' => $editUrlByPeriod($targetPeriod)];
        }
    }
    // 締め日を過ぎた（1 つ前の月の報告を出していない）。その月が終わってから作られたアカウントには出さない（途中で入った人）
    $overduePeriod = period_shift($targetPeriod, -1);
    $overdueCloseDate = report_close_date($user, $overduePeriod);
    if ($overdueCloseDate !== null && $overdueCloseDate < date('Y-m-d') && report_period_editable($overduePeriod)
        && (string) ($user['created_at'] ?? '') < period_shift($overduePeriod, 1) . '-01'
        && !reminder_has_submitted($userId, $overduePeriod)) {
        $bannerList[] = ['text' => msg('{period}の報告の締め日（{date}）を過ぎています。まだ提出していません。', ['period' => period_label($overduePeriod), 'date' => display_date($overdueCloseDate)]),
            'url' => $editUrlByPeriod($overduePeriod)];
    }

    // 課長・確認者から促された（その月の報告を出すまで）
    reminder_ensure_table();
    $oldestPeriod = period_shift(date('Y-m'), -REMINDER_MONTHS_BACK);
    foreach (db_all(
        'SELECT rm.period, MAX(rm.created_at) AS last_sent_at,
                (SELECT u.display_name FROM report_reminders latest JOIN users u ON u.id = latest.sent_by
                  WHERE latest.user_id = rm.user_id AND latest.period = rm.period ORDER BY latest.created_at DESC, latest.id DESC LIMIT 1) AS sender_name
           FROM report_reminders rm
          WHERE rm.user_id = ? AND rm.period >= ?
          GROUP BY rm.period
          ORDER BY rm.period',
        [$userId, $oldestPeriod]
    ) as $reminderRow) {
        $remindedPeriod = (string) $reminderRow['period'];
        if (reminder_has_submitted($userId, $remindedPeriod)) {
            continue;
        }
        $bannerList[] = ['text' => msg('{sender} さんから、{period}の報告を提出するよう知らせが届いています（{date}）。', [
                'sender' => (string) $reminderRow['sender_name'], 'period' => period_label($remindedPeriod), 'date' => display_date(substr((string) $reminderRow['last_sent_at'], 0, 10)),
            ]),
            'url' => $editUrlByPeriod($remindedPeriod)];
    }
    return $bannerList;
}

/**
 * 課の、その月に最後に促した日時（利用者の ID => 日時）
 *
 * @return array<int, string>
 */
function reminder_last_sent_map(int $departmentId, string $period): array
{
    reminder_ensure_table();
    $lastSentAtByUserId = [];
    foreach (db_all('SELECT user_id, MAX(created_at) AS last_sent_at FROM report_reminders WHERE department_id = ? AND period = ? GROUP BY user_id', [$departmentId, $period]) as $reminderRow) {
        $lastSentAtByUserId[(int) $reminderRow['user_id']] = (string) $reminderRow['last_sent_at'];
    }
    return $lastSentAtByUserId;
}

/** 促せる月か（当月と過去 3 か月。作成・編集できる月と同じ） */
function reminder_period_allowed(string $period): bool
{
    return report_period_editable($period);
}
