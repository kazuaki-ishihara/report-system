<?php

require_once '../config/database.php';
require_once '../lib/functions.php';

// レポートIDを取得
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    http_response_code(400);
    exit('レポートIDが正しくありません。');
}

// レポートを取得
$stmt = $pdo->prepare("
    SELECT *
    FROM reports
    WHERE id = :id
");

$stmt->execute([
    ':id' => $id
]);

$report = $stmt->fetch();

if (!$report) {
    http_response_code(404);
    exit('指定されたレポートが見つかりません。');
}

// コメント対象の項目
$fields = [
    'report_date' => '日付',
    'name'        => '氏名',
    'subject'     => '件名',
    'content'     => '内容',
    'issues'      => '課題',
    'remarks'     => '備考'
];

// コメントを取得
$stmt = $pdo->prepare("
    SELECT *
    FROM comments
    WHERE report_id = :report_id
    ORDER BY created_at ASC, id ASC
");

$stmt->execute([
    ':report_id' => $id
]);

$allComments = $stmt->fetchAll();

// 項目ごとにコメントを整理
$commentsByField = [];

foreach ($fields as $fieldName => $label) {
    $commentsByField[$fieldName] = [];
}

foreach ($allComments as $comment) {
    $fieldName = $comment['field_name'];

    if (array_key_exists($fieldName, $commentsByField)) {
        $commentsByField[$fieldName][] = $comment;
    }
}

?>

<!DOCTYPE html>
<html lang="ja">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>レポート詳細</title>

    <link rel="stylesheet" href="css/style.css">

    <style>
        /*
         * レポート詳細の行
         *
         * 1列目：コメントアイコン
         * 2列目：項目名
         * 3列目：内容
         */
        .detail-row {
            display: grid;
            grid-template-columns: 64px 220px minmax(0, 1fr);
            align-items: stretch;
            gap: 0;
            padding: 8px 0;
            border-bottom: 1px solid #e5edf7;
        }

        /*
         * コメントアイコン列
         */
        .detail-icon-column {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 0;
        }

        /*
         * 項目名列
         */
        .detail-title-column {
            display: flex;
            align-items: center;
            min-width: 0;
            padding: 0 16px;
            font-weight: bold;
            color: #172b4d;
        }

        /*
         * 内容列
         */
        .detail-content-column {
            min-width: 0;
        }

        /*
         * コメントアイコンと吹き出し
         */
        .comment-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /*
         * コメントアイコン
         * 丸い枠や背景をなくす
         */
        button.comment-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            width: 52px;
            min-width: 36px;
            height: 36px;
            padding: 0 2px;
            border: none;
            border-radius: 0;
            background-color: transparent;
            color: #9aa5b1;
            font-size: 25px;
            line-height: 1;
            cursor: pointer;
        }

        /* コメントが登録されているアイコン */
        button.comment-icon.has-comments {
            color: #0783ef;
        }

        button.comment-icon:hover {
            background-color: transparent;
            color: #005fbd;
            transform: scale(1.1);
        }

        /* コメント件数 */
        .comment-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            padding: 0 4px;
            box-sizing: border-box;
            border-radius: 9px;
            background-color: #0783ef;
            color: #fff;
            font-size: 11px;
            font-weight: bold;
            line-height: 1;
        }

        button.comment-icon:not(.has-comments) .comment-count {
            display: none;
        }

        button.comment-icon:focus-visible {
            outline: 2px solid #1976d2;
            outline-offset: 2px;
        }

        /*
         * コメント吹き出し
         */
        .comment-popover {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            z-index: 100;
            width: 320px;
            max-width: calc(100vw - 60px);
            max-height: 400px;
            overflow-y: auto;
            padding: 16px;
            border: 1px solid #cbd9e8;
            border-radius: 8px;
            background-color: #fff;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.16);
            box-sizing: border-box;
        }

        .comment-popover[hidden] {
            display: none;
        }

        /*
         * 吹き出し上部の三角形
         */
        .comment-popover::before {
            content: "";
            position: absolute;
            top: -8px;
            left: 12px;
            width: 14px;
            height: 14px;
            border-top: 1px solid #cbd9e8;
            border-left: 1px solid #cbd9e8;
            background-color: #fff;
            transform: rotate(45deg);
        }

        /*
         * 吹き出しのタイトル
         */
        .comment-popover-title {
            margin: 0 0 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5edf7;
            color: #172b4d;
            font-size: 16px;
            font-weight: bold;
        }

        /*
         * コメント一覧
         */
        .comment-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }

        /*
         * コメント1件分
         */
        .comment-item {
            padding: 10px 0;
            border-bottom: 1px solid #e5edf7;
        }

        .comment-text {
            margin: 0 0 6px;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
            color: #172b4d;
            line-height: 1.6;
        }

        .comment-date {
            margin: 0;
            color: #7186a5;
            font-size: 12px;
        }

        /*
         * コメントがない場合
         */
        .no-comment {
            margin: 0 0 12px;
            color: #718096;
            font-size: 14px;
        }

        /*
         * コメント操作ボタン
         */
        .comment-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }

        .comment-edit-button,
        .comment-delete-button {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
        }

        /*
         * 編集ボタン
         */
        .comment-edit-button {
            border: 1px solid #1976d2;
            background-color: #fff;
            color: #1976d2;
        }

        .comment-edit-button:hover {
            background-color: #e3f2fd;
        }

        /*
         * 削除ボタン
         */
        .comment-delete-form {
            display: inline;
            margin: 0;
        }

        .comment-delete-button {
            border: 1px solid #d32f2f;
            background-color: #fff;
            color: #d32f2f;
        }

        .comment-delete-button:hover {
            background-color: #ffebee;
        }

        /*
         * コメント登録フォーム
         */
        .comment-form {
            margin-top: 14px;
        }

        .comment-form label {
            display: block;
            margin-bottom: 6px;
            color: #172b4d;
            font-size: 14px;
            font-weight: bold;
        }

        .comment-form textarea {
            display: block;
            width: 100%;
            min-height: 80px;
            padding: 10px;
            border: 1px solid #cbd9e8;
            border-radius: 5px;
            box-sizing: border-box;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
        }

        .comment-form textarea:focus {
            outline: none;
            border-color: #1976d2;
            box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.12);
        }

        .comment-form button {
            margin-top: 8px;
            padding: 8px 18px;
            border: none;
            border-radius: 5px;
            background-color: #087bea;
            color: #fff;
            font-size: 14px;
            cursor: pointer;
        }

        .comment-form button:hover {
            background-color: #005fbd;
        }

        /*
         * レポートの内容
         */
        .detail-value {
            box-sizing: border-box;
            width: 100%;
            min-height: 44px;
            padding: 10px 12px;
            border: 1px solid #dce7f3;
            border-radius: 5px;
            background-color: #f4f7fb;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
            color: #172b4d;
            line-height: 1.6;
        }

        /*
         * 文章が長い項目
         */
        .detail-value.multiline {
            min-height: 80px;
        }

        /*
         * 操作ボタン
         */
        .detail-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            margin-top: 20px;
        }

        /*
         * スマートフォン向け
         */
        @media (max-width: 700px) {

            .detail-row {
                grid-template-columns: 44px 100px minmax(0, 1fr);
                padding: 8px 0;
            }

            .detail-title-column {
                padding: 0 8px;
                overflow-wrap: anywhere;
            }

            button.comment-icon {
                width: 32px;
                height: 32px;
                font-size: 22px;
            }

            .comment-popover {
                width: 300px;
                max-width: calc(100vw - 40px);
            }

        }

        @media (max-width: 400px) {

            .detail-row {
                grid-template-columns: 38px 72px minmax(0, 1fr);
            }

            .detail-title-column {
                padding: 0 5px;
                font-size: 14px;
            }

            .detail-value {
                padding: 8px;
                font-size: 14px;
            }

        }
    </style>

</head>

<body>

<div class="container">

    <h1>レポート詳細</h1>

    <div class="form-container">

        <?php foreach ($fields as $fieldName => $label): ?>

            <?php
            // 文章が長い項目
            $multilineFields = [
                'content',
                'issues',
                'remarks'
            ];

            $valueClass = in_array(
                $fieldName,
                $multilineFields,
                true
            )
                ? 'detail-value multiline'
                : 'detail-value';
            ?>

            <?php
            // この項目に登録されているコメント数
            $commentCount = count($commentsByField[$fieldName]);
            $hasComments = $commentCount > 0;
            ?>

            <div class="detail-row">

                <!-- 1列目：コメントアイコン -->
                <div class="detail-icon-column">

                    <div class="comment-wrapper">

                        <button
                            type="button"
                            class="comment-icon<?= $hasComments ? ' has-comments' : '' ?>"
                            data-comment-target="comments-<?= h($fieldName) ?>"
                            aria-controls="comments-<?= h($fieldName) ?>"
                            aria-expanded="false"
                            aria-label="<?= h($label) ?>のコメント<?= $hasComments ? ' ' . $commentCount . '件' : 'なし' ?>"
                            title="<?= h($label) ?>のコメント<?= $hasComments ? '：' . $commentCount . '件' : '（なし）' ?>"
                        >
                            💬<?php if ($hasComments): ?>
                                <span class="comment-count"><?= h($commentCount) ?></span>
                            <?php endif; ?>
                        </button>

                        <!-- コメント吹き出し -->
                        <div
                            class="comment-popover"
                            id="comments-<?= h($fieldName) ?>"
                            hidden
                        >

                            <p class="comment-popover-title">
                                <?= h($label) ?>のコメント
                            </p>

                            <!-- 登録済みコメント -->
                            <?php if (empty($commentsByField[$fieldName])): ?>

                                <p class="no-comment">
                                    コメントはまだありません。
                                </p>

                            <?php else: ?>

                                <ul class="comment-list">

                                    <?php foreach ($commentsByField[$fieldName] as $comment): ?>

                                        <li class="comment-item">

                                            <p class="comment-text"><?= h($comment['comment']) ?></p>

                                            <p class="comment-date">
                                                登録：
                                                <?= h($comment['created_at']) ?>
                                            </p>

                                            <?php
                                            // 更新済みの場合は更新日時も表示
                                            if (
                                                !empty($comment['updated_at']) &&
                                                $comment['updated_at'] !== $comment['created_at']
                                            ):
                                            ?>

                                                <p class="comment-date">
                                                    更新：
                                                    <?= h($comment['updated_at']) ?>
                                                </p>

                                            <?php endif; ?>

                                            <!-- 編集・削除ボタン -->
                                            <div class="comment-actions">

                                                <a
                                                    href="comment_edit.php?id=<?= h($comment['id']) ?>"
                                                    class="comment-edit-button"
                                                >
                                                    編集
                                                </a>

                                                <form
                                                    action="comment_delete.php"
                                                    method="post"
                                                    class="comment-delete-form"
                                                    onsubmit="return confirm('このコメントを削除しますか？');"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="comment_id"
                                                        value="<?= h($comment['id']) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="report_id"
                                                        value="<?= h($report['id']) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="field_name"
                                                        value="<?= h($fieldName) ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="comment-delete-button"
                                                    >
                                                        削除
                                                    </button>

                                                </form>

                                            </div>

                                        </li>

                                    <?php endforeach; ?>

                                </ul>

                            <?php endif; ?>

                            <!-- コメント登録フォーム -->
                            <form
                                action="comment_store.php"
                                method="post"
                                class="comment-form"
                            >

                                <input
                                    type="hidden"
                                    name="report_id"
                                    value="<?= h($report['id']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="field_name"
                                    value="<?= h($fieldName) ?>"
                                >

                                <label for="comment-<?= h($fieldName) ?>">
                                    コメントを追加
                                </label>

                                <textarea
                                    id="comment-<?= h($fieldName) ?>"
                                    name="comment"
                                    required
                                    maxlength="5000"
                                    placeholder="コメントを入力してください"
                                ></textarea>

                                <button type="submit">
                                    登録
                                </button>

                            </form>

                        </div>

                    </div>

                </div>

                <!-- 2列目：項目名 -->
                <div class="detail-title-column">
                    <?= h($label) ?>
                </div>

                <!-- 3列目：内容 -->
                <div class="detail-content-column">

                    <div class="<?= h($valueClass) ?>"><?= h($report[$fieldName] ?? '') ?></div>

                </div>

            </div>

        <?php endforeach; ?>

        <!-- 操作ボタン -->
        <div class="detail-actions">

            <a
                href="edit.php?id=<?= h($report['id']) ?>"
                class="button"
            >
                編集
            </a>

            <a
                href="index.php"
                class="back-button"
            >
                一覧に戻る
            </a>

        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // コメントアイコンを取得
    const commentButtons = document.querySelectorAll(
        '.comment-icon[data-comment-target]'
    );

    // 吹き出しをすべて閉じる
    function closeAllPopovers() {

        document.querySelectorAll('.comment-popover').forEach(function (popover) {
            popover.hidden = true;
        });

        commentButtons.forEach(function (button) {
            button.setAttribute('aria-expanded', 'false');
        });
    }

    // コメントアイコンのクリック処理
    commentButtons.forEach(function (button) {

        button.addEventListener('click', function (event) {

            event.stopPropagation();

            const targetId = button.dataset.commentTarget;
            const popover = document.getElementById(targetId);

            if (!popover) {
                return;
            }

            // クリック前に開いていたか確認
            const isOpen = !popover.hidden;

            // すべての吹き出しを閉じる
            closeAllPopovers();

            // 閉じていた場合はクリックした吹き出しを開く
            if (!isOpen) {
                popover.hidden = false;
                button.setAttribute('aria-expanded', 'true');
            }

        });

    });

    // 吹き出し内部のクリックでは閉じない
    document.querySelectorAll('.comment-popover').forEach(function (popover) {

        popover.addEventListener('click', function (event) {
            event.stopPropagation();
        });

    });

    // 吹き出しの外側をクリックしたら閉じる
    document.addEventListener('click', function () {
        closeAllPopovers();
    });

    // Escapeキーで閉じる
    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {
            closeAllPopovers();
        }

    });

    // コメント登録・編集・削除後、対象の吹き出しを開く
    if (window.location.hash.startsWith('#comments-')) {

        const targetId = window.location.hash.substring(1);
        const popover = document.getElementById(targetId);

        if (popover) {

            const button = document.querySelector(
                '[data-comment-target="' + targetId + '"]'
            );

            if (button) {
                popover.hidden = false;
                button.setAttribute('aria-expanded', 'true');
            }

        }

    }

});
</script>

</body>

</html>