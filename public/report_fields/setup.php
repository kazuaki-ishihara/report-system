<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/report_fields.php';

// ==================================================
// 項目管理のセットアップ（管理者のみ）
//
// 項目定義・選択肢・追加項目の値のテーブルを作り、既存の 6 項目を初期登録する。
// 何度実行しても、作成済みのものは作り直さない。
// ==================================================

$loginUserId = requireReportFieldAdmin($pdo);

// アプリのフォルダ（この中にはバックアップを置かせない）
$appRoot = realpath(dirname(__DIR__, 2));

// DB ファイル（config/database.php で定義）
$databaseFile = realpath($dbFile);


// ==================================================
// バックアップ先の確認
// ==================================================

/**
 * バックアップ先の設定を確認する
 *
 * @return array{dir: string, error: string|null}
 *   dir が空ならバックアップ先が未設定
 */
function checkBackupDir(string $appRoot): array
{
    $config = require __DIR__ . '/../../config/backup.php';
    $dir = is_array($config) && isset($config['dir']) && is_string($config['dir'])
        ? rtrim(trim($config['dir']), '/\\')
        : '';

    if ($dir === '') {
        return ['dir' => '', 'error' => null];
    }

    $isAbsolute = $dir[0] === '/' || preg_match('/^[A-Za-z]:[\/\\\\]/', $dir) === 1;

    if (!$isAbsolute) {
        return ['dir' => $dir, 'error' => 'バックアップ先は絶対パスで指定してください（config/backup.php）。'];
    }

    // 存在しない場合は親フォルダで判定する
    $checkPath = is_dir($dir) ? realpath($dir) : realpath(dirname($dir));

    if ($checkPath === false) {
        return ['dir' => $dir, 'error' => 'バックアップ先のフォルダ（またはその親フォルダ）が見つかりません（config/backup.php）。'];
    }

    $normalizedCheck = rtrim(str_replace('\\', '/', $checkPath), '/') . '/';
    $normalizedRoot = rtrim(str_replace('\\', '/', $appRoot), '/') . '/';

    if (strpos($normalizedCheck, $normalizedRoot) === 0) {
        return ['dir' => $dir, 'error' => 'バックアップ先にアプリのフォルダの中は指定できません。Web から見えない場所を指定してください（config/backup.php）。'];
    }

    return ['dir' => $dir, 'error' => null];
}

$backup = checkBackupDir($appRoot);


// ==================================================
// 状態の確認
// ==================================================

/**
 * 今のセットアップ状態を調べる
 *
 * 既存 6 項目の初期登録は、項目定義のテーブルが空のときだけ行う
 * （未使用で削除した内容・課題・備考を、再実行で復活させないため）。
 *
 * @return array{tables: bool, seeded: bool}
 */
function readSetupStatus(PDO $pdo): array
{
    $tables = reportFieldTablesExist($pdo);
    $seeded = false;

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'report_fields'"
    );
    $stmt->execute();

    if ((int) $stmt->fetchColumn() === 1) {
        $seeded = (int) $pdo->query('SELECT COUNT(*) FROM report_fields')->fetchColumn() > 0;
    }

    return ['tables' => $tables, 'seeded' => $seeded];
}


// ==================================================
// 実行（POST）
// ==================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    if ($backup['error'] !== null) {
        redirectWithMessage('setup.php', 'error', $backup['error']);
    }

    if ($backup['dir'] === '' && postFlag('manual_backup_confirmed') !== 1) {
        redirectWithMessage(
            'setup.php',
            'error',
            '自動バックアップ先が未設定です。DB ファイルを手で保存し、確認欄にチェックを付けてから実行してください。'
        );
    }

    if ($databaseFile === false) {
        error_log('項目管理セットアップ: DB ファイルが見つかりません');
        redirectWithMessage('setup.php', 'error', 'DB ファイルが見つかりません。');
    }

    $now = date('Y-m-d H:i:s');
    $backupFile = '';

    try {
        // 他の書き込みを止めてから、バックアップと作成を行う
        $pdo->exec('BEGIN IMMEDIATE');

        // ---- バックアップ ----
        if ($backup['dir'] !== '') {
            if (!is_dir($backup['dir']) && !@mkdir($backup['dir'], 0700, true)) {
                throw new RuntimeException('バックアップ先のフォルダを作成できません: ' . $backup['dir']);
            }

            $backupFile = $backup['dir'] . DIRECTORY_SEPARATOR .
                'reports_before_report_fields_' . date('Ymd_His') . '.sqlite';

            if (!@copy($databaseFile, $backupFile)) {
                throw new RuntimeException('バックアップをコピーできません: ' . $backupFile);
            }

            @chmod($backupFile, 0600);
        }

        // ---- テーブル作成 ----
        foreach (reportFieldSchemaSql() as $sql) {
            $pdo->exec($sql);
        }

        // ---- 既存 6 項目の初期登録（項目定義が空のときだけ） ----
        $fieldCount = (int) $pdo->query('SELECT COUNT(*) FROM report_fields')->fetchColumn();

        $insert = $pdo->prepare(
            'INSERT INTO report_fields (
                code, label, field_type, is_system, is_active, is_required,
                show_in_list, is_searchable, sort_order, max_length,
                min_value, max_value, allow_decimal,
                created_at, updated_at, updated_by
             )
             SELECT
                :code, :label, :field_type, :is_system, 1, :is_required,
                :show_in_list, :is_searchable, :sort_order, :max_length,
                NULL, NULL, 0,
                :created_at, :updated_at, :updated_by
             WHERE NOT EXISTS (
                SELECT 1 FROM report_fields WHERE code = :code_check
             )'
        );

        foreach ($fieldCount > 0 ? [] : reportFieldInitialRows() as $index => $row) {
            $insert->execute([
                ':code'          => $row['code'],
                ':label'         => $row['label'],
                ':field_type'    => $row['field_type'],
                ':is_system'     => $row['is_system'],
                ':is_required'   => $row['is_required'],
                ':show_in_list'  => $row['show_in_list'],
                ':is_searchable' => $row['is_searchable'],
                ':sort_order'    => ($index + 1) * REPORT_FIELD_SORT_STEP,
                ':max_length'    => $row['max_length'],
                ':created_at'    => $now,
                ':updated_at'    => $now,
                ':updated_by'    => $loginUserId,
                ':code_check'    => $row['code'],
            ]);
        }

        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        } else {
            // BEGIN IMMEDIATE は PDO のトランザクションとして扱われないため直接戻す
            try {
                $pdo->exec('ROLLBACK');
            } catch (PDOException $rollbackError) {
                // トランザクションが開始されていない場合は何もしない
            }
        }

        error_log('項目管理セットアップエラー: ' . $e->getMessage());

        redirectWithMessage(
            'setup.php',
            'error',
            'セットアップに失敗しました。DB は変更していません。サーバーのエラーログを確認してください。'
        );
    }

    $message = 'セットアップが完了しました。';

    if ($backupFile !== '') {
        $message .= 'バックアップ: ' . basename($backupFile);
    }

    redirectWithMessage('index.php', 'message', $message);
}


// ==================================================
// 画面表示（GET）
// ==================================================

$status = readSetupStatus($pdo);
$isDone = $status['tables'] && $status['seeded'];
$csrfToken = getCsrfToken();
list($message, $error) = getFlashMessages();

?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>項目管理のセットアップ</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/report_fields.css">
</head>

<body>

<div class="rf-container rf-narrow">

    <h1>項目管理のセットアップ</h1>

    <?php if ($message !== ''): ?>
        <div class="rf-message" role="status"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="rf-error" role="alert"><?= h($error) ?></div>
    <?php endif; ?>

    <p>
        レポートの項目を管理するためのテーブル（項目定義・選択肢・追加項目の値）を作り、
        今の 6 項目（日付・氏名・件名・内容・課題・備考）を登録します。
        既存のレポートとコメントのデータは変更しません。
    </p>

    <h2>現在の状態</h2>

    <ul class="rf-status-list">
        <li>
            項目定義・選択肢・追加項目の値のテーブル：
            <?= $status['tables'] ? '作成済み' : '<strong>未作成（または一部のみ）</strong>' ?>
        </li>
        <li>
            既存 6 項目の登録：
            <?= $status['seeded'] ? '登録済み' : '<strong>未登録</strong>' ?>
        </li>
        <li>
            自動バックアップ先：
            <?php if ($backup['error'] !== null): ?>
                <strong>設定に誤りがあります</strong>
            <?php elseif ($backup['dir'] === ''): ?>
                <strong>未設定</strong>（config/backup.php）
            <?php else: ?>
                設定済み
            <?php endif; ?>
        </li>
    </ul>

    <?php if ($backup['error'] !== null): ?>
        <div class="rf-error" role="alert"><?= h($backup['error']) ?></div>
    <?php endif; ?>

    <?php if ($isDone): ?>

        <div class="rf-message">セットアップは完了しています。</div>

        <div class="rf-toolbar">
            <a href="index.php" class="rf-button">項目管理へ</a>
            <a href="../index.php" class="rf-button rf-button-secondary">レポート一覧へ戻る</a>
        </div>

    <?php else: ?>

        <form method="post" action="setup.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <?php if ($backup['dir'] === ''): ?>
                <div class="rf-warning">
                    自動バックアップ先が設定されていないため、実行前に DB ファイル（data/reports.sqlite）を手で保存してください。
                </div>

                <div class="form-group">
                    <label class="rf-checkbox-label">
                        <input type="checkbox" name="manual_backup_confirmed" value="1" required>
                        DB ファイルを手で保存しました
                    </label>
                </div>
            <?php else: ?>
                <p class="rf-help">実行前に、DB ファイルを自動でバックアップします。</p>
            <?php endif; ?>

            <div class="rf-toolbar">
                <button
                    type="submit"
                    class="rf-button"
                    <?= $backup['error'] !== null ? 'disabled' : '' ?>
                >
                    セットアップを実行する
                </button>
                <a href="../index.php" class="rf-button rf-button-secondary">レポート一覧へ戻る</a>
            </div>
        </form>

    <?php endif; ?>

</div>

</body>

</html>
