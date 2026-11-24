<?php

declare(strict_types=1);

// ====================================================================
// ローカル検証環境に「お試し」の状態を足す（本番では使わない）
//   php tools/test/dev_seed.php --yes --with-faq   （先にテストデータを作る）
//   php tools/test/trial_seed.php --yes
// デモ用のサイト（report-system_onion）のお試しと同じ形を作る:
//   - 課「お試し課1」: 小野 雄太（y.ono。役職は一般のまま、課の「課長」に設定）・課長代理 2 名（確認者）・課員 6 名
//   - 臨時の調査「AI利用状況」（依頼元: 人財管理部。公開中。集計のみ）と、全員（9 人）の回答。答えはばらけるように作る
// config.local.php に 'allow_dev_seed' => true があるときだけ動く。y.ono がもういれば何もしない。
// パスワードは dev_seed.php と同じ決まり（pass-<ID>-1234）。乱数の種は決まっているので、何度作っても同じ答えになる。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/seed_helpers.php';

seed_require_local_environment();
if (!isset(getopt('', ['yes'])['yes'])) {
    fwrite(STDERR, "お試しの課・利用者・調査を足します。--yes を付けて実行してください\n");
    exit(1);
}
if (auth_find_user('y.ono') !== null) {
    fwrite(STDERR, "お試しの状態はもう入っています（作り直すときは、先に dev_seed.php --yes でテストデータを作り直す）\n");
    exit(1);
}

const TRIAL_DEPARTMENT_NAME = 'お試し課1';
// [ログイン ID, 表示名, 役職, 確認者の役]
const TRIAL_MEMBER_LIST = [
    ['y.ono', '小野 雄太', '一般', 'final'],   // 役職は一般のまま課長（お試しで課長の画面を試すため。権限は役職ではなく課長の設定で決まる）
    ['trial.dairi1', '石井 健一', '課長代理', 'reviewer'],
    ['trial.dairi2', '森 由美', '課長代理', 'reviewer'],
    ['trial.m01', '青木 翔', '一般', null],
    ['trial.m02', '坂本 彩', '一般', null],
    ['trial.m03', '村田 大輔', '主任', null],
    ['trial.m04', '藤田 真央', '一般', null],
    ['trial.m05', '岡本 拓海', '一般', null],
    ['trial.m06', '西村 美咲', '一般', null],
];

mt_srand(20261124);
$trialIds = tx(function (): array {
    $nowText = now();
    db_exec('INSERT INTO departments (name, sort_order, created_at, updated_at) VALUES (?, 30, ?, ?)', [TRIAL_DEPARTMENT_NAME, $nowText, $nowText]);
    $departmentId = (int) db()->lastInsertId();
    $userIdByLoginId = [];
    foreach (TRIAL_MEMBER_LIST as [$loginId, $displayName, $roleTitle, $reviewerRole]) {
        $userIdByLoginId[$loginId] = seed_create_user($loginId, $displayName, $roleTitle, $departmentId, null, $reviewerRole);
    }
    $requestId = seed_create_open_survey(
        $departmentId, 'AI利用状況', '人財管理部', 'AI の業務での利用状況を知るため',
        $userIdByLoginId['y.ono'], seed_ai_question_spec_list(), array_values($userIdByLoginId)
    );
    // y.ono はデモ用のサイトと同じ答え（毎日・GPT と Gemini と Claude・激変）。ほかの人は課の傾向「時々」が多めで、ばらけるように
    seed_answer_survey($requestId, $userIdByLoginId['y.ono'], [1, [1, 2, 3], 1]);
    foreach ($userIdByLoginId as $loginId => $userId) {
        if ($loginId !== 'y.ono') {
            seed_answer_survey($requestId, $userId, seed_ai_answer([3, 4, 2]));
        }
    }
    return ['department_id' => $departmentId, 'request_id' => $requestId];
});

echo 'お試しの状態を足しました: 課「' . TRIAL_DEPARTMENT_NAME . '」(id=' . $trialIds['department_id'] . '。課長 y.ono・課長代理 2 名・課員 6 名)・'
    . '臨時の調査「AI利用状況」(id=' . $trialIds['request_id'] . '。' . count(TRIAL_MEMBER_LIST) . " 人全員が回答)。パスワードは pass-<ID>-1234\n";
