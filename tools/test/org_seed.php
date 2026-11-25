<?php

declare(strict_types=1);

// ====================================================================
// ローカル検証環境に、組織図（png/組織図全体.png）どおりの課と人を足す（本番では使わない。検証環境だけ）
//   php tools/test/dev_seed.php --yes --with-faq
//   php tools/test/trial_seed.php --yes            （お試し課。なくてもよい）
//   php tools/test/org_seed.php --yes
// 部長以上（課に所属しない。「部長以上」の印）4 人と、依頼元（人財管理部）の担当 1 人も作る（結果を見る人。K-067）。
// 課の単位は仮設定 K-022 の 7 つ（システム 1 部の 1・2 課、2 部の 1・2・3 課、3 部、営業課）。
// 課長代理ごとにラインを作り、課は「ラインごと」の管理にする。主任はラインの確認者。営業課は課長と課員だけ。
// そのうえで、全課共通の臨時の調査「AI利用状況（全社）」を作り、対象者の全員が回答した状態にする（全員回答のシミュレーション）。
// 答えは課ごとの傾向を付けてばらけさせる。乱数の種は決まっているので、何度作っても同じ答えになる。
// 名前は架空。パスワードは pass-<ID>-1234（dev_seed.php と同じ決まり）。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/seed_helpers.php';

seed_require_local_environment();
if (!isset(getopt('', ['yes'])['yes'])) {
    fwrite(STDERR, "組織図どおりの課・人と、全課共通の調査（全員回答）を足します。--yes を付けて実行してください\n");
    exit(1);
}
if (auth_find_user('s11.kacho') !== null) {
    fwrite(STDERR, "組織図のデータはもう入っています（作り直すときは、先に dev_seed.php --yes でテストデータを作り直す）\n");
    exit(1);
}
if (!survey_all_departments_supported()) {
    fwrite(STDERR, "DB が古い形です。先に php app/bin/migrate.php を実行してください\n");
    exit(1);
}

/*
 * 組織図の課。teams は課長代理ごとのライン: [課長代理がいるか, 主任の人数, 課員の人数]。
 * usage は「AI を業務で使っていますか」の重み（毎日・時々・使わない）。課ごとの傾向にする
 */
const ORG_UNIT_LIST = [
    ['code' => 's11', 'name' => 'システム1部 システム1課', 'head' => '課長', 'teams' => [[true, 1, 2], [true, 1, 3]], 'usage' => [6, 3, 1]],
    ['code' => 's12', 'name' => 'システム1部 システム2課', 'head' => '課長', 'teams' => [[true, 1, 1], [true, 1, 1]], 'usage' => [3, 5, 2]],
    ['code' => 's21', 'name' => 'システム2部 システム1課', 'head' => '課長', 'teams' => [[true, 0, 3], [true, 1, 3]], 'usage' => [5, 4, 1]],
    ['code' => 's22', 'name' => 'システム2部 システム2課', 'head' => '課長', 'teams' => [[true, 0, 3], [true, 1, 3]], 'usage' => [4, 4, 2]],
    ['code' => 's23', 'name' => 'システム2部 システム3課', 'head' => '課長', 'teams' => [[true, 1, 1]], 'usage' => [2, 5, 3]],
    ['code' => 's3', 'name' => 'システム3部', 'head' => '部長', 'teams' => [[true, 1, 6]], 'usage' => [3, 4, 3]],   // 課がないので部を 1 単位（K-022）
    ['code' => 'eigyo', 'name' => '営業部 営業課', 'head' => '課長', 'teams' => [[false, 0, 4]], 'usage' => [1, 3, 6]],
];

const ORG_FAMILY_NAME_LIST = ['佐藤', '鈴木', '高橋', '田中', '伊藤', '渡辺', '山本', '中村', '小林', '加藤', '吉田', '山田', '佐々木', '山口', '松本',
    '井上', '木村', '林', '斎藤', '清水', '山崎', '阿部', '森田', '池田', '橋本', '石川', '前田', '小川', '後藤', '岡田'];
const ORG_GIVEN_NAME_LIST = ['大翔', '陽菜', '蓮', '結衣', '湊', '葵', '悠真', '凛', '樹', '美桜', '颯太', '紬', '陸', '芽依', '奏太', '心春',
    '海斗', '咲良', '翼', '彩花', '拓真', '日向', '健太', '優奈', '直樹', '真由', '和也', '綾乃', '慎吾', '千尋'];

/** 部長以上（課に所属しない）: [ログイン ID, 表示名]。組織図のシステム事業統括と部長 */
const ORG_EXECUTIVE_LIST = [
    ['exec.toukatsu', '統括 太郎'],
    ['exec.s1', '一部 部長'],
    ['exec.s2', '二部 部長'],
    ['exec.eigyo', '営業 部長'],
];

mt_srand(20261124);
$organizationResult = tx(function (): array {
    $nowText = now();
    $nameIndex = 0;
    $nextDisplayName = function () use (&$nameIndex): string {
        // 姓と名をずらして組み合わせ、同じ名前が出ないようにする
        $displayName = ORG_FAMILY_NAME_LIST[$nameIndex % count(ORG_FAMILY_NAME_LIST)] . ' '
            . ORG_GIVEN_NAME_LIST[(intdiv($nameIndex, count(ORG_FAMILY_NAME_LIST)) * 7 + $nameIndex * 11) % count(ORG_GIVEN_NAME_LIST)];
        $nameIndex++;
        return $displayName;
    };
    $usageWeightListByUserId = [];
    $departmentCount = 0;
    $sortOrder = 100;
    foreach (ORG_UNIT_LIST as $unit) {
        $hasTeams = count(array_filter($unit['teams'], function (array $team): bool { return $team[0]; })) > 0;
        db_exec('INSERT INTO departments (name, sort_order, review_scope, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$unit['name'], $sortOrder, $hasTeams ? 'team' : 'department', $nowText, $nowText]);
        $departmentId = (int) db()->lastInsertId();
        $departmentCount++;
        $sortOrder += 10;

        $headUserId = seed_create_user($unit['code'] . '.kacho', $nextDisplayName(), $unit['head'], $departmentId, null, 'final');
        $usageWeightListByUserId[$headUserId] = $unit['usage'];
        $memberNumber = 0;
        foreach ($unit['teams'] as $teamIndex => [$hasDeputy, $leaderCount, $memberCount]) {
            $teamId = null;
            if ($hasDeputy) {
                $deputyDisplayName = $nextDisplayName();
                db_exec('INSERT INTO department_teams (department_id, name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                    [$departmentId, explode(' ', $deputyDisplayName)[0] . 'ライン', ($teamIndex + 1) * 10, $nowText, $nowText]);
                $teamId = (int) db()->lastInsertId();
                $deputyUserId = seed_create_user($unit['code'] . '.dairi' . ($teamIndex + 1), $deputyDisplayName, '課長代理', $departmentId, $teamId, 'reviewer', $teamId);
                $usageWeightListByUserId[$deputyUserId] = $unit['usage'];
            }
            for ($leaderNumber = 1; $leaderNumber <= $leaderCount; $leaderNumber++) {
                $leaderUserId = seed_create_user($unit['code'] . '.leader' . ($teamIndex + 1), $nextDisplayName(), '主任', $departmentId, $teamId, 'reviewer', $teamId);
                $usageWeightListByUserId[$leaderUserId] = $unit['usage'];
            }
            for ($memberIndex = 0; $memberIndex < $memberCount; $memberIndex++) {
                $memberNumber++;
                $memberUserId = seed_create_user(sprintf('%s.m%02d', $unit['code'], $memberNumber), $nextDisplayName(), '一般', $departmentId, $teamId);
                $usageWeightListByUserId[$memberUserId] = $unit['usage'];
            }
        }
    }

    // 部長以上（課に所属しない。「部長以上」の印で、すべての全課共通の調査の結果を見る。K-067）と、依頼元（人財管理部）の担当
    foreach (ORG_EXECUTIVE_LIST as [$executiveLoginId, $executiveDisplayName]) {
        $executiveUserId = seed_create_user($executiveLoginId, $executiveDisplayName, '部長', null);
        db_exec('UPDATE users SET is_executive = 1 WHERE id = ?', [$executiveUserId]);
    }
    $requesterStaffUserId = seed_create_user('jinzai.tanto', '人財 担当', '一般', null);

    // 全課共通の調査（K-066）: 有効な課に所属する全員が対象（ほかのテスト用の課・お試し課の人も入る）。全員が回答する
    $adminUserId = (int) db_val("SELECT id FROM users WHERE login_id = 'admin01'");
    $targetUserIdList = array_map(function (array $member): int { return (int) $member['id']; }, survey_all_departments_members());
    $questionSpecList = seed_ai_question_spec_list();
    $questionSpecList[] = ['生成 AI の社内研修を受けたいですか', 'checkbox', []];
    $requestId = seed_create_open_survey(null, 'AI利用状況（全社）', '人財管理部', '全社の AI の利用状況を知り、研修の計画に使うため', $adminUserId, $questionSpecList, $targetUserIdList);
    db_exec('INSERT INTO adhoc_request_viewers (request_id, user_id) VALUES (?, ?)', [$requestId, $requesterStaffUserId]);   // 依頼元の担当に結果を見せる
    foreach ($targetUserIdList as $targetUserId) {
        $answer = seed_ai_answer($usageWeightListByUserId[$targetUserId] ?? [3, 4, 3]);
        $answer[] = mt_rand(1, 100) <= ($answer[0] === 3 ? 30 : 60);   // 研修を受けたい（使っていない人は少なめ）
        seed_answer_survey($requestId, $targetUserId, $answer);
    }
    return ['department_count' => $departmentCount, 'user_count' => count($usageWeightListByUserId), 'request_id' => $requestId, 'respondent_count' => count($targetUserIdList)];
});

echo '組織図のデータを足しました: 課 ' . $organizationResult['department_count'] . ' つ・' . $organizationResult['user_count'] . ' 人（ID の例: s11.kacho・s11.dairi1・s11.leader1・s11.m01。パスワードは pass-<ID>-1234）' . "\n";
echo '全課共通の調査「AI利用状況（全社）」(id=' . $organizationResult['request_id'] . ')に、対象者 ' . $organizationResult['respondent_count'] . " 人の全員が回答しました\n";
echo "結果を見る人: 部長以上 exec.toukatsu・exec.s1・exec.s2・exec.eigyo（すべての全課共通の調査）、依頼元の担当 jinzai.tanto（この調査だけ）\n";
