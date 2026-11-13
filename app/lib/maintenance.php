<?php

declare(strict_types=1);

// ====================================================================
// メンテナンス中の表示（便利機能 F。G-21 §7）
//   管理者・補佐が「メンテナンス中にする（何時まで・一言）」を切り替える。
//   その間、管理者・補佐以外の人には全画面に知らせを出し、保存などの操作（POST）を受け付けない。
//   時刻を過ぎたら自動で終わる。状態は app_state の maintenance（JSON）。
// ====================================================================

const MAINTENANCE_MESSAGE_MAX_LENGTH = 100;

/**
 * 今のメンテナンスの状態。メンテナンス中でなければ（または時刻を過ぎていれば）null。
 *
 * @return array{until: string, message: string, started_by: int, started_at: string}|null
 */
function maintenance_state(): ?array
{
    static $cachedState = false;
    if ($cachedState !== false) {
        return $cachedState;
    }
    $storedValue = db_val("SELECT value FROM app_state WHERE name = 'maintenance'");
    $state = $storedValue === null ? null : json_decode((string) $storedValue, true);
    if (!is_array($state) || !isset($state['until']) || (string) $state['until'] <= now()) {
        $cachedState = null;
        return null;
    }
    $cachedState = [
        'until'      => (string) $state['until'],
        'message'    => (string) ($state['message'] ?? ''),
        'started_by' => (int) ($state['started_by'] ?? 0),
        'started_at' => (string) ($state['started_at'] ?? ''),
    ];
    return $cachedState;
}

/** この人の操作をメンテナンスで止めるか（管理者・補佐は止めない） */
function maintenance_blocks(?array $user): bool
{
    return maintenance_state() !== null && ($user === null || !can_manage_system($user));
}

/** メンテナンスの知らせの文（画面の上に出す） */
function maintenance_notice_text(): string
{
    $state = maintenance_state();
    if ($state === null) {
        return '';
    }
    $noticeText = msg('メンテナンス中です（{until} まで）。この間は保存などの操作ができません。入力中の内容は、終わってから保存してください。',
        ['until' => display_datetime($state['until'])]);
    return $state['message'] !== '' ? $noticeText . "\n" . $state['message'] : $noticeText;
}

/** メンテナンスを始める（$untilText は Y-m-d H:i:s） */
function maintenance_start(string $untilText, string $messageText, int $userId): void
{
    db_exec(
        "INSERT INTO app_state (name, value) VALUES ('maintenance', ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value",
        [json_encode(['until' => $untilText, 'message' => $messageText, 'started_by' => $userId, 'started_at' => now()], JSON_UNESCAPED_UNICODE)]
    );
}

function maintenance_end(): void
{
    db_exec("DELETE FROM app_state WHERE name = 'maintenance'");
}
