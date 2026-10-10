<?php

require_once '../config/database.php';


// POST以外は一覧へ戻す
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}


// IDを取得
$id = filter_input(
    INPUT_POST,
    'id',
    FILTER_VALIDATE_INT
);


if (!$id) {
    exit('不正なIDです。');
}


// 削除
$stmt = $pdo->prepare("
    DELETE FROM reports
    WHERE id = :id
");

$stmt->execute([
    ':id' => $id
]);


// 一覧へ戻る
header('Location: index.php');
exit;

