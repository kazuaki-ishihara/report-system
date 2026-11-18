<?php

declare(strict_types=1);

// ====================================================================
// バックアップ（便利機能 H。G-21 §7）
//   app/bin/backup.php が DB の写しを backup_dir に作る（VACUUM INTO）。古いものは backup_keep 個だけ残す。
//   管理のトップに、DB の大きさと最後のバックアップの日時を出す（backup_warn_days より古ければ目立たせる）。
// ====================================================================

/** バックアップの置き場所（設定 backup_dir。既定は app/backups） */
function backup_directory(): string
{
    return (string) cfg('backup_dir');
}

/** この道具が作るバックアップのファイル名の先頭（ほかのファイル（移行の前の写しなど）は消さない） */
const BACKUP_FILE_PREFIX = 'reports_';

/**
 * DB の大きさと、最後のバックアップ。
 *
 * @return array{databaseBytes: int, latestBackupAt: ?string, latestBackupName: ?string, backupCount: int, isStale: bool}
 */
function backup_status(): array
{
    $databasePath = (string) cfg('db_path');
    $databaseBytes = 0;
    foreach (['', '-wal'] as $fileSuffix) {
        if (is_file($databasePath . $fileSuffix)) {
            $databaseBytes += (int) filesize($databasePath . $fileSuffix);
        }
    }
    $latestBackupTime = null;
    $latestBackupName = null;
    $backupCount = 0;
    foreach (glob(backup_directory() . '/*.sqlite') ?: [] as $backupPath) {
        $backupCount++;
        $backupTime = (int) filemtime($backupPath);
        if ($latestBackupTime === null || $backupTime > $latestBackupTime) {
            $latestBackupTime = $backupTime;
            $latestBackupName = basename($backupPath);
        }
    }
    $warnDays = (int) cfg('backup_warn_days');
    return [
        'databaseBytes'    => $databaseBytes,
        'latestBackupAt'   => $latestBackupTime === null ? null : date('Y-m-d H:i:s', $latestBackupTime),
        'latestBackupName' => $latestBackupName,
        'backupCount'      => $backupCount,
        'isStale'          => $latestBackupTime === null || $latestBackupTime < time() - $warnDays * 86400,
    ];
}

/** バイト数を読みやすく（1.2 MB など） */
function backup_size_text(int $byteCount): string
{
    if ($byteCount >= 1024 * 1024) {
        return number_format($byteCount / 1024 / 1024, 1) . ' MB';
    }
    return number_format(max(1, (int) ceil($byteCount / 1024))) . ' KB';
}

/**
 * バックアップを 1 つ作り、古いもの（この道具が作ったもの）を backup_keep 個まで減らす。
 *
 * @return string 作ったファイルの場所
 */
function backup_create(): string
{
    $backupDirectory = backup_directory();
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException('バックアップの置き場所を作れません: ' . $backupDirectory);
    }
    $backupPath = $backupDirectory . '/' . BACKUP_FILE_PREFIX . date('Ymd_His') . '.sqlite';
    // 動いている DB の写しを、ひとまとまりの状態で作る（SQLite 3.27 以上の VACUUM INTO）
    db()->exec('VACUUM INTO ' . db()->quote($backupPath));
    @chmod($backupPath, 0600);
    $keepCount = max(1, (int) cfg('backup_keep'));
    $ownBackupPathList = glob($backupDirectory . '/' . BACKUP_FILE_PREFIX . '*.sqlite') ?: [];
    sort($ownBackupPathList);
    foreach (array_slice($ownBackupPathList, 0, max(0, count($ownBackupPathList) - $keepCount)) as $oldBackupPath) {
        @unlink($oldBackupPath);
    }
    return $backupPath;
}
