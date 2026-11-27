<?php

declare(strict_types=1);

// ====================================================================
// G-11 調査の結果（一覧）: 部長以上・依頼元の担当が、結果を見られる全課共通の調査を選ぶ（K-067）
// 管理者・補佐は「臨時の調査」の一覧から結果を開く。個人の回答・名前は出さない
// ====================================================================

$user = page_start();
survey_close_expired();
$viewableRequestList = survey_viewable_all_departments_requests($user);
if (!$viewableRequestList && !is_executive($user)) {
    error_page(403);
}

render('survey_results', ['title' => '調査の結果', 'user' => $user, 'rows' => $viewableRequestList]);
